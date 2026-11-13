<?php

namespace Cosmii02\ModpackManager\Services;

use App\Enums\EggFormat;
use App\Models\Backup;
use App\Models\Egg;
use App\Models\EggVariable;
use App\Models\Server;
use App\Models\ServerVariable;
use App\Repositories\Daemon\DaemonFileRepository;
use App\Services\Backups\DeleteBackupService;
use App\Services\Backups\InitiateBackupService;
use App\Services\Eggs\EggChangerService;
use App\Services\Eggs\Sharing\EggImporterService;
use App\Services\Servers\ReinstallServerService;
use Cosmii02\ModpackManager\Exceptions\InstallCancelledException;
use Cosmii02\ModpackManager\Models\ModpackInstall;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Orchestrates modpack installation on a Pelican server via the Wings daemon.
 *
 * Strategy:
 *   - CurseForge: if the selected file is (or links to) an official SERVER PACK,
 *     download & extract it directly. Otherwise BUILD a server pack from the
 *     client pack — read manifest.json, have Wings download every mod, and
 *     merge the overrides/ folder.
 *   - Modrinth: a .mrpack only contains an index + overrides, so we always
 *     parse modrinth.index.json and download the server-side files.
 *
 * Wings does all file work (download via pull(), extract via decompressFile()).
 */
class ModpackInstallService
{
    private const ARCHIVE_NAME = 'modpack-download.zip';

    /** Mod files left in /mods before the new pack is placed; these are not the pack's. */
    private array $preexistingMods = [];
    private const EXTRACT_DIR = '.modpack-extract';
    private const BUNDLE_MAX_FILE_BYTES = 1024 * 1024;
    private const BUNDLE_PART_BYTES = 64 * 1024 * 1024;
    private const BUNDLE_DOWNLOAD_CONCURRENCY = 24;
    private const BUNDLE_DIR = 'modpack-manager';
    private const ARCHIVE_JUNK = ['__MACOSX', '.DS_Store', 'Thumbs.db'];
    // A lone top-level folder with one of these names is pack content, not a wrapper.
    private const SERVER_ROOT_FOLDERS = [
        'mods', 'config', 'overrides', 'libraries', 'plugins', 'world', 'worlds', 'kubejs',
        'defaultconfigs', 'resourcepacks', 'datapacks', 'scripts', 'versions',
    ];
    private const DEFAULT_REMOTE_DOWNLOAD_CONCURRENCY = 3;
    private const DEFAULT_PANEL_FALLBACK_CONCURRENCY = 4;

    public function __construct(
        private DaemonFileRepository $fileRepo
    ) {}

    public function install(ModpackInstall $record, array $options = [], array $spec = []): void
    {
        $server = $record->server;

        if (!$server) {
            throw new RuntimeException('Server not found for modpack install record #' . $record->id);
        }

        // New jobs carry their install plan directly so a cache clear, plugin
        // reload, or cache backend mismatch cannot strand a queued install. Keep the
        // old cache lookup only so jobs queued by an older build can still finish.
        if (empty($spec)) {
            $spec = Cache::pull("modpack-manager:install-spec:{$record->id}") ?? [];
        }

        if (empty($spec) || empty($spec['provider'])) {
            throw new RuntimeException(
                "No install spec found for install #{$record->id} (cache may have expired). Please re-trigger."
            );
        }

        $this->fileRepo->setServer($server);

        try {
            $this->ensureInstallNotDismissed($record);
            $this->stepDeleteSelectedBackups($record, $options);
            $this->ensureInstallNotDismissed($record);
            $this->stepSaveConfig($record);
            $this->ensureInstallNotDismissed($record);
            $this->stepCreateBackup($record, $options);
            $this->ensureInstallNotDismissed($record);
            $this->stepDeleteWorlds($record, $options);
            $this->ensureInstallNotDismissed($record);
            $this->stepDeleteFiles($record, $options);
            $this->ensureInstallNotDismissed($record);

            $plan = $this->resolvePlan($record, $spec);
            $this->ensureInstallNotDismissed($record);

            if (!empty($plan['archiveUrl'])) {
                $this->stepDownload($record, $plan['archiveUrl']);
                $this->ensureInstallNotDismissed($record);
                $this->stepExtract($record);
                $plan = $this->rerouteFtbInstallerPack($record, $plan);
            } else {
                // FTB/ATLauncher ship a file list, not a single archive.
                $this->skipArchiveSteps($record);
            }

            $this->ensureInstallNotDismissed($record);
            $this->stepAssembleAndMerge($record, $plan, $spec);
            $this->ensureInstallNotDismissed($record);
            $this->stepRestoreConfig($record, $options);
            $this->ensureInstallNotDismissed($record);
            $this->stepFinalize($record);
            $this->ensureInstallNotDismissed($record);
            $this->stepConfigureLoader($record, $plan);
            $this->ensureInstallNotDismissed($record);
            $this->syncServerIcon($record);

            $record->update(['status' => 'installed', 'progress' => 100, 'error_message' => null]);
            $record->appendLog('Installation completed successfully.');

        } catch (InstallCancelledException $e) {
            $record->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);
            $record->appendLog('Installation cancelled. Queue worker released.');
            Log::info('[ModpackManager] Installation cancelled', [
                'record' => $record->id,
                'reason' => $e->getMessage(),
            ]);
            return;
        } catch (Throwable $e) {
            Log::error('[ModpackManager] Installation failed', [
                'record' => $record->id,
                'error'  => $e->getMessage(),
                'trace'  => $e->getTraceAsString(),
            ]);

            $record->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
            $record->appendLog('FATAL: ' . $e->getMessage());

            throw $e;
        }
    }

    /** Stop work as soon as an install has been cancelled or superseded. */
    private function ensureInstallNotDismissed(ModpackInstall $record): void
    {
        $record->refresh();

        if ($record->status === 'cancelling') {
            throw new InstallCancelledException($record->error_message ?: 'Installation cancelled.');
        }

        // Compatibility with installs cancelled by earlier test builds.
        if ($record->status === 'failed') {
            $reason = strtolower((string) $record->error_message);
            if (str_contains($reason, 'cancel') || str_contains($reason, 'supersed') || str_contains($reason, 'stopping')) {
                throw new InstallCancelledException($record->error_message ?: 'Installation cancelled.');
            }
        }
    }

    /**
     * Mirror the installed modpack artwork onto Pelican's server icon. This is
     * cosmetic and must never turn an otherwise successful install into a failure.
     */
    private function syncServerIcon(ModpackInstall $record): void
    {
        $url = $this->resolveServerIconUrl($record);
        if ($url === null) {
            $record->appendLog('  Server icon was not changed: no artwork URL was available for this pack.');
            return;
        }

        try {
            if (!$this->isPublicHttpUrl($url)) {
                throw new RuntimeException('artwork URL does not point to a public address');
            }

            $response = Http::withHeaders([
                'User-Agent' => 'pelican-modpack-manager/1.6.9',
                'Accept' => 'image/avif,image/webp,image/png,image/jpeg,image/*,*/*;q=0.8',
            ])->withOptions([
                'allow_redirects' => [
                    'max' => 3,
                    'protocols' => ['http', 'https'],
                    'on_redirect' => function ($request, $response, $uri): void {
                        if (!$this->isPublicHttpUrl((string) $uri)) {
                            throw new RuntimeException('artwork redirect does not point to a public address');
                        }
                    },
                ],
            ])->connectTimeout(10)->timeout(30)->retry(2, 250, throw: false)->get($url);

            if ($response->failed()) {
                throw new RuntimeException('image request returned HTTP ' . $response->status());
            }

            $data = $response->body();
            if ($data === '') {
                throw new RuntimeException('image response was empty');
            }
            if (strlen($data) > 5 * 1024 * 1024) {
                throw new RuntimeException('image is larger than 5 MB');
            }

            $detected = @getimagesizefromstring($data);
            $mime = is_array($detected) && !empty($detected['mime'])
                ? strtolower((string) $detected['mime'])
                : strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0] ?? ''));
            $extension = match ($mime) {
                'image/png' => 'png',
                'image/jpeg', 'image/jpg' => 'jpg',
                'image/webp' => 'webp',
                default => null,
            };

            if ($extension === null) {
                throw new RuntimeException('unsupported image format');
            }

            $server = $record->server;
            if (!$server) {
                return;
            }

            // Pelican's native icon URL is stable (uuid + extension). If two
            // consecutive packs are both PNG, a browser can continue displaying
            // the old cached uuid.png even after writeIcon() replaces it. When GD
            // is available, alternate the format so the URL itself changes. The
            // normal writeIcon() method then removes every older icon extension.
            [$extension, $data] = $this->avoidServerIconCacheCollision($server, $extension, $data);

            $server->writeIcon($extension, $data);
            $server->refresh();
            $record->update(['modpack_icon_url' => $url]);
            $record->appendLog('  Updated the Pelican server icon from the modpack artwork.');
        } catch (Throwable $e) {
            Log::info('[ModpackManager] Server icon update skipped', [
                'record' => $record->id,
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
            $record->appendLog('  Server icon was not changed: ' . $e->getMessage());
        }
    }

    /**
     * The Panel fetches pack artwork itself, so refuse anything that resolves to a
     * private, loopback, link-local or reserved address.
     */
    private function isPublicHttpUrl(string $url): bool
    {
        if (!in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            return false;
        }

        $host = trim((string) parse_url($url, PHP_URL_HOST), '[]');
        if ($host === '') {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips = [$host];
        } else {
            $ips = gethostbynamel($host) ?: [];
            foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
                if (!empty($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }

        if (empty($ips)) {
            return false;
        }

        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return false;
            }
        }

        return true;
    }

    private function resolveServerIconUrl(ModpackInstall $record): ?string
    {
        $stored = trim((string) ($record->modpack_icon_url ?? ''));
        if ($stored !== '' && preg_match('#^https?://#i', $stored)) {
            return $stored;
        }

        try {
            $project = match ($record->provider) {
                'curseforge' => app(CurseForgeService::class)->getMod((int) $record->modpack_id),
                'modrinth' => app(ModrinthService::class)->getProject((string) $record->modpack_id),
                'ftb' => app(FtbService::class)->getProject((string) $record->modpack_id),
                'atlauncher' => app(ATLauncherService::class)->getProject((string) $record->modpack_id),
                default => [],
            };

            $url = trim((string) ($project['iconUrl'] ?? ''));
            if ($url !== '' && preg_match('#^https?://#i', $url)) {
                $record->update(['modpack_icon_url' => $url]);
                return $url;
            }
        } catch (Throwable $e) {
            Log::info('[ModpackManager] Could not refresh modpack artwork URL', [
                'record' => $record->id,
                'provider' => $record->provider,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * @return array{0:string,1:string}
     */
    private function avoidServerIconCacheCollision(Server $server, string $extension, string $data): array
    {
        $currentUrl = (string) ($server->icon ?? '');
        $currentPath = (string) (parse_url($currentUrl, PHP_URL_PATH) ?? '');
        $currentExtension = strtolower((string) pathinfo($currentPath, PATHINFO_EXTENSION));

        if ($currentExtension === '' || $currentExtension !== $extension || !function_exists('imagecreatefromstring')) {
            return [$extension, $data];
        }

        $image = @imagecreatefromstring($data);
        if ($image === false) {
            return [$extension, $data];
        }

        try {
            $target = $extension === 'webp' ? 'png' : 'webp';
            if ($target === 'webp' && !function_exists('imagewebp')) {
                $target = 'png';
            }
            if ($target === 'png' && !function_exists('imagepng')) {
                return [$extension, $data];
            }

            ob_start();
            $ok = $target === 'webp'
                ? imagewebp($image, null, 90)
                : imagepng($image, null, 6);
            $converted = (string) ob_get_clean();

            if (!$ok || $converted === '') {
                return [$extension, $data];
            }

            return [$target, $converted];
        } finally {
            if (is_resource($image) || $image instanceof \GdImage) {
                imagedestroy($image);
            }
        }
    }

    // ─── Plan resolution ────────────────────────────────────────────────────

    /**
     * Decide what to download and how to install it.
     *
     * @return array{mode:string, archiveUrl:?string}
     *   mode: 'server_pack' | 'curseforge_build' | 'modrinth' | 'ftb' | 'atlauncher'.
     *   FTB/ATLauncher carry no archive — they ship a file list + loader metadata
     *   that the assemble step downloads directly.
     */
    private function resolvePlan(ModpackInstall $record, array $spec): array
    {
        if ($spec['provider'] === 'modrinth') {
            $url = $spec['mrpack_url'] ?? null;
            if (empty($url)) {
                throw new RuntimeException('No Modrinth download URL in install spec.');
            }
            $record->appendLog('Modrinth pack — will download server-side files from the index.');
            return ['mode' => 'modrinth', 'archiveUrl' => $url];
        }

        if ($spec['provider'] === 'ftb') {
            return $this->ftbPlan($record, (int) $spec['pack_id'], (int) $spec['version_id']);
        }

        if ($spec['provider'] === 'atlauncher') {
            $manifest = app(ATLauncherService::class)->getInstallManifest((string) $spec['safe_name'], (string) $spec['version']);
            $record->appendLog('ATLauncher pack — assembling ' . count($manifest['files']) . ' server mods from the CDN manifest.');

            return [
                'mode'       => 'atlauncher',
                'archiveUrl' => null,
                'files'      => $manifest['files'],
                'configsUrl' => $manifest['configsUrl'],
                'loader'     => $manifest['loader'],
                'mc'         => $manifest['mc'],
                'version'    => $manifest['loaderVersion'],
                'java'       => $manifest['java'],
            ];
        }

        /** @var CurseForgeService $cf */
        $cf     = app(CurseForgeService::class);
        $modId  = (int) $spec['mod_id'];
        $fileId = (int) $spec['file_id'];

        $file = $cf->getFile($modId, $fileId);

        // The loader/MC are listed on the file metadata (e.g. ["1.20.1","NeoForge"]).
        // A downloaded *server* pack often has no manifest.json, so carry this along as
        // the authoritative loader source for the egg switch.
        ['loader' => $cfLoader, 'mc' => $cfMc] = $this->cfLoaderMeta($file['gameVersions'] ?? [], $spec['preferred_loader'] ?? null);

        if (!empty($file['isServerPack'])) {
            $record->appendLog('Selected file is already a server pack — installing directly.');
            return ['mode' => 'server_pack', 'archiveUrl' => $cf->getDownloadUrl($modId, $fileId), 'loader' => $cfLoader, 'mc' => $cfMc, 'version' => null];
        }

        if (!empty($file['serverPackFileId'])) {
            $serverPackId = (int) $file['serverPackFileId'];
            $record->appendLog("Official server pack found (file #{$serverPackId}) — using it.");
            return ['mode' => 'server_pack', 'archiveUrl' => $cf->getDownloadUrl($modId, $serverPackId), 'loader' => $cfLoader, 'mc' => $cfMc, 'version' => null];
        }

        if ($serverPack = $cf->findServerPackForFile($modId, $fileId, $file)) {
            ['loader' => $serverLoader, 'mc' => $serverMc] = $this->cfLoaderMeta($serverPack['gameVersions'] ?? [], $spec['preferred_loader'] ?? null);
            $serverPackId = (int) $serverPack['id'];
            $record->appendLog("Official server pack found as an additional file (#{$serverPackId}) — using it.");

            return [
                'mode'       => 'server_pack',
                'archiveUrl' => $cf->getDownloadUrl($modId, $serverPackId),
                'loader'     => $cfLoader ?: $serverLoader,
                'mc'         => $cfMc ?: $serverMc,
                'version'    => null,
            ];
        }

        $record->appendLog('No official server pack available — building one from the client pack.');
        return ['mode' => 'curseforge_build', 'archiveUrl' => $cf->getDownloadUrl($modId, $fileId), 'loader' => $cfLoader, 'mc' => $cfMc, 'version' => null];
    }

    private function ftbPlan(ModpackInstall $record, int $packId, int $versionId): array
    {
        $detail = app(FtbService::class)->getVersionFiles($packId, $versionId);
        $record->appendLog('FTB pack — assembling ' . count($detail['files']) . ' server files from the API.');

        return [
            'mode'       => 'ftb',
            'archiveUrl' => null,
            'files'      => $detail['files'],
            'loader'     => $detail['loader'],
            'mc'         => $detail['mc'],
            'version'    => $detail['loaderVersion'],
            'java'       => $detail['java'],
        ];
    }

    /**
     * FTB publishes its CurseForge "server pack" as a tiny zip of FTB-Server-Installer
     * scripts (install.sh/install.bat) that fetch the real server at first run. Installed
     * as-is, the server gets no mods; install that exact FTB version from the API instead.
     */
    private function rerouteFtbInstallerPack(ModpackInstall $record, array $plan): array
    {
        if (($plan['mode'] ?? null) !== 'server_pack') {
            return $plan;
        }

        try {
            $script = $this->fileRepo->getContent('/install.sh', 64 * 1024);
        } catch (Throwable) {
            return $plan;
        }

        if (
            !str_contains($script, 'FTB-Server-Installer')
            || !preg_match('/^\s*pack_id="?(\d+)"?\s*$/m', $script, $pack)
            || !preg_match('/^\s*version_id="?(\d+)"?\s*$/m', $script, $version)
        ) {
            return $plan;
        }

        $record->appendLog("  This server pack is FTB's installer script (FTB pack {$pack[1]}, version {$version[1]}) — installing that version from the FTB API instead.");

        foreach (['install.sh', 'install.bat'] as $file) {
            try {
                $this->fileRepo->deleteFiles('/', [$file]);
            } catch (Throwable) {
            }
        }
        try {
            if (str_contains($this->fileRepo->getContent('/README.md', 64 * 1024), 'FTB Server Installer')) {
                $this->fileRepo->deleteFiles('/', ['README.md']);
            }
        } catch (Throwable) {
        }

        return $this->ftbPlan($record, (int) $pack[1], (int) $version[1]);
    }

    // ─── Steps ──────────────────────────────────────────────────────────────

    private function stepDeleteSelectedBackups(ModpackInstall $record, array $options): void
    {
        if (!($options['delete_backups'] ?? false)) {
            return;
        }

        $ids = array_values(array_unique(array_filter(
            array_map('intval', $options['backup_ids'] ?? []),
            fn (int $id) => $id > 0
        )));

        if (empty($ids)) {
            $record->appendLog('Backup deletion enabled, but no backups were selected.');
            return;
        }

        $backups = Backup::query()
            ->where('server_id', $record->server_id)
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        if ($backups->count() !== count($ids)) {
            throw new RuntimeException('One or more selected backups do not belong to this server or no longer exist. No backups were deleted.');
        }

        foreach ($ids as $id) {
            $backup = $backups->get($id);

            if ($backup->is_locked) {
                throw new RuntimeException('Backup "' . $backup->name . '" is locked and cannot be deleted. No backups were deleted.');
            }

            if ($backup->completed_at === null) {
                throw new RuntimeException('Backup "' . $backup->name . '" is still running and cannot be deleted. No backups were deleted.');
            }
        }

        $record->appendLog('Deleting selected backups from this server…');
        $service = app(DeleteBackupService::class);

        foreach ($ids as $id) {
            $backup = $backups->get($id);
            $name = $backup->name;
            $service->handle($backup);
            $record->appendLog('  Deleted backup: ' . $name);
        }
    }

    private function stepDeleteWorlds(ModpackInstall $record, array $options): void
    {
        if (!($options['delete_world'] ?? false)) {
            return;
        }

        try {
            $properties = (string) $this->fileRepo->getContent('/server.properties', 1024 * 1024);
        } catch (Throwable) {
            $record->appendLog('World deletion enabled, but server.properties is not present. Nothing to delete; continuing.');
            return;
        }

        if (!preg_match('/^\s*level-name\s*=\s*(.+?)\s*$/m', $properties, $matches)) {
            $record->appendLog('World deletion enabled, but server.properties has no level-name. Nothing to delete; continuing.');
            return;
        }

        $levelName = trim((string) $matches[1]);

        if (
            $levelName === ''
            || $levelName === '.'
            || $levelName === '..'
            || str_contains($levelName, '/')
            || str_contains($levelName, '\\')
            || str_contains($levelName, "\0")
        ) {
            throw new RuntimeException('World deletion was requested, but level-name is not a safe root-level world folder. Nothing was deleted.');
        }

        $candidates = [$levelName, $levelName . '_nether', $levelName . '_the_end'];
        $targets = [];

        foreach ($this->fileRepo->getDirectory('/') as $entry) {
            $name = (string) ($entry['name'] ?? '');
            $isDirectory = (bool) ($entry['directory'] ?? false);

            if ($isDirectory && in_array($name, $candidates, true)) {
                $targets[] = $name;
            }
        }

        if (empty($targets)) {
            $record->appendLog('World deletion enabled, but no active world folders were found.');
            return;
        }

        $record->appendLog('Deleting active world folders: ' . implode(', ', $targets));
        $this->fileRepo->deleteFiles('/', $targets);

        foreach ($targets as $target) {
            $record->appendLog('  Deleted world folder: /' . $target . '/');
        }
    }

    private function stepSaveConfig(ModpackInstall $record): void
    {
        $record->markStepRunning('save_config');
        $record->appendLog('Saving configuration files before install…');

        $saved = [];
        foreach (config('modpack-manager.preserved_files', []) as $filename) {
            try {
                $content = $this->fileRepo->getContent('/' . $filename, 5 * 1024 * 1024);
                $saved[$filename] = (string) $content;
                $record->appendLog("  Saved: {$filename}");
            } catch (Throwable) {
                $record->appendLog("  Skip (not found): {$filename}");
            }
        }

        Cache::put("modpack-manager:config:{$record->id}", $saved, now()->addHours(2));

        try {
            $properties = (string) $this->fileRepo->getContent('/server.properties', 5 * 1024 * 1024);
            Cache::put("modpack-manager:server-properties:{$record->id}", $properties, now()->addHours(2));
            $record->appendLog('  Saved server.properties for safe settings merge.');
        } catch (Throwable) {
            Cache::forget("modpack-manager:server-properties:{$record->id}");
            $record->appendLog('  Skip (not found): server.properties');
        }

        $record->update(['progress' => 8]);
        $record->markStepDone('save_config');
        $record->appendLog('Configuration saved.');
    }

    private function stepCreateBackup(ModpackInstall $record, array $options = []): void
    {
        $record->markStepRunning('create_backup');

        if (!($options['create_backup'] ?? true)) {
            $record->appendLog('Skipping backup (disabled for this install).');
            $record->update(['progress' => 16]);
            $record->markStepDone('create_backup');
            return;
        }

        $record->appendLog('Creating a panel backup of the current server…');

        // The user explicitly asked for a backup before we wipe the existing
        // installation. If we can't produce a successful one, abort instead of
        // silently destroying their files — installation continues only when a
        // backup is in hand.
        try {
            $name = 'Before modpack install — ' . $record->modpack_name
                  . ' (' . now()->format('Y-m-d H:i') . ')';

            /** @var InitiateBackupService $service */
            $service = app(InitiateBackupService::class);
            $backup  = $service->handle($record->server, $name);

            $record->appendLog('  Backup started on Wings; waiting for it to finish…');

            // Wait for Wings to report completion before we touch any files,
            // otherwise the backup would capture a half-modified server.
            $deadline  = time() + 900; // 15 minutes
            $completed = false;
            while (time() < $deadline) {
                $this->ensureInstallNotDismissed($record);
                sleep(5);
                $this->ensureInstallNotDismissed($record);
                $backup->refresh();
                if ($backup->completed_at !== null) {
                    $completed = true;
                    break;
                }
            }

            if (!$completed) {
                throw new RuntimeException(
                    'Backup did not finish within 15 minutes. Aborting so your current installation is left untouched.'
                );
            }

            if (!$backup->is_successful) {
                throw new RuntimeException(
                    'The backup reported a failure. Aborting so your current installation is left untouched.'
                );
            }

            $size = $backup->bytes ? $this->humanBytes((int) $backup->bytes) : 'unknown size';
            $record->appendLog("  Backup completed successfully ({$size}). It's in the Backups tab.");
        } catch (\App\Exceptions\Service\Backup\TooManyBackupsException) {
            $record->markStepFailed('create_backup');
            throw new RuntimeException(
                'Server backup limit reached — the requested backup could not be created. '
                . 'Free a slot or raise the limit, then try again. Your current installation was left untouched.'
            );
        } catch (RuntimeException $e) {
            // Already a clear, user-facing message from the checks above.
            $record->markStepFailed('create_backup');
            throw $e;
        } catch (Throwable $e) {
            $record->markStepFailed('create_backup');
            throw new RuntimeException(
                'The requested backup could not be created (' . $e->getMessage()
                . '). Aborting so your current installation is left untouched.',
                0,
                $e
            );
        }

        $record->update(['progress' => 16]);
        $record->markStepDone('create_backup');
    }

    private function stepDeleteFiles(ModpackInstall $record, array $options): void
    {
        $record->markStepRunning('delete_files');

        // ALWAYS clear launcher scripts AND loader-metadata files a previous install may have
        // left, even when "Delete existing files" is off. A new pack ships its own, so any
        // pre-existing one is stale. Two failure modes this prevents:
        //   • a stale variables.txt makes a NeoForge pack (ATM10) get read as the previous Forge
        //     pack (ATM9) and keep the wrong egg;
        //   • a stale manifest.json / modrinth.index.json makes detectLoader() report the
        //     PREVIOUS pack's loader — e.g. installing Forge 1.20.1 DeceasedCraft over a leftover
        //     NeoForge 1.21.1 manifest was detected as "neoforge 21.1.221" and stayed on the
        //     NeoForge egg. This runs BEFORE download, so the current pack's own files (extracted
        //     afterwards) are untouched. These are tiny metadata files, never user data.
        foreach (['start.sh', 'startserver.sh', 'run.sh', 'start.bat', 'startserver.bat', 'run.bat', 'start.ps1', 'startserver.ps1', 'run.ps1', 'variables.txt', 'user_jvm_args.txt', 'manifest.json', 'modrinth.index.json', 'unix_args.txt', 'server.jar', 'fabric-server-launch.jar', 'fabric-server-launcher.jar', 'quilt-server-launch.jar', 'installer.jar'] as $file) {
            try {
                $this->fileRepo->deleteFiles('/', [$file]);
                $record->appendLog("  Cleared stale launcher/metadata file: /{$file}");
            } catch (Throwable) {
                // Usually absent; ignore.
            }
        }

        foreach (['libraries', 'versions', '.fabric', '.fabric-installer', '.quilt', '.quilt-installer'] as $dir) {
            try {
                $this->fileRepo->deleteFiles('/', [$dir]);
                $record->appendLog("  Cleared stale loader runtime: /{$dir}/");
            } catch (Throwable) {
            }
        }

        // Also clear stale loader installer jars (forge-/neoforge-/fabric-/quilt- *installer.jar)
        // a previous pack left in the root — otherwise an old ATM9 forge installer can be picked
        // up instead of this pack's (e.g. ATM10's neoforge) installer. Runs before download, so
        // the new pack's own installer (extracted afterwards) is untouched.
        try {
            foreach ($this->fileRepo->getDirectory('/') as $e) {
                $name = (string) ($e['name'] ?? '');
                if (preg_match('/^(neoforge|forge|fabric|quilt)-.*installer\.jar$/i', $name)) {
                    try {
                        $this->fileRepo->deleteFiles('/', [$name]);
                        $record->appendLog("  Cleared stale installer jar: /{$name}");
                    } catch (Throwable) {
                        // ignore
                    }
                }
            }
        } catch (Throwable) {
            // Directory unreadable; ignore.
        }

        if (!($options['delete_existing'] ?? false)) {
            $this->removePreviousPackMods($record);
            $this->preexistingMods = $this->listModFiles() ?? [];
            $record->appendLog('Skipping directory deletion ("Delete existing files" was not enabled).');
            $record->update(['progress' => 24]);
            $record->markStepDone('delete_files');
            return;
        }

        $record->appendLog('Clean install enabled — removing files from the previous server pack…');
        $this->cleanExistingServerRoot($record, $options);
        $this->preexistingMods = [];

        $record->update(['progress' => 24]);
        $record->markStepDone('delete_files');
        $record->appendLog('Old files removed.');
    }

    /**
     * Without a clean wipe, the new pack's mods would be merged next to the previous
     * version's jars (e.g. FarmersDelight 1.2.10 + 1.2.11) and the loader crashes on the
     * duplicates. Remove what the previous install placed; user-added mods are kept.
     */
    private function removePreviousPackMods(ModpackInstall $record): void
    {
        $previous = ModpackInstall::query()
            ->where('server_id', $record->server_id)
            ->where('id', '<', $record->id)
            ->where('status', 'installed')
            ->latest('id')
            ->first();

        if (!$previous) {
            return;
        }

        $present = $this->listModFiles();
        if ($present === null) {
            return;
        }

        if (is_array($previous->pack_mods)) {
            $stale = array_values(array_intersect($present, $previous->pack_mods));
        } elseif ($previous->provider === $record->provider && (string) $previous->modpack_id === (string) $record->modpack_id) {
            $record->appendLog('  Previous install has no mod list (older plugin build) — clearing mod jars so versions cannot mix.');
            $stale = array_values(array_filter($present, fn (string $n) => preg_match('/\.jar(\.disabled)?$/i', $n) === 1));
        } else {
            return;
        }

        foreach (array_chunk($stale, 100) as $chunk) {
            $this->fileRepo->deleteFiles('/mods', $chunk);
        }

        if ($stale) {
            $record->appendLog('  Removed ' . count($stale) . ' mod file(s) from the previous pack version.');
        }
    }

    private function recordPackMods(ModpackInstall $record): void
    {
        $mods = $this->listModFiles();
        if ($mods === null) {
            return;
        }

        $record->update(['pack_mods' => array_values(array_diff($mods, $this->preexistingMods))]);
    }

    /** File names directly in /mods, or null when there is no /mods folder. */
    private function listModFiles(): ?array
    {
        try {
            $names = [];
            foreach ($this->fileRepo->getDirectory('/mods') as $entry) {
                if (!(bool) ($entry['directory'] ?? false) && ($name = (string) ($entry['name'] ?? '')) !== '') {
                    $names[] = $name;
                }
            }

            return $names;
        } catch (Throwable) {
            return null;
        }
    }

    private function cleanExistingServerRoot(ModpackInstall $record, array $options): void
    {
        $keep = [];

        if (!($options['delete_world'] ?? false)) {
            $oldProperties = Cache::get("modpack-manager:server-properties:{$record->id}");
            if (is_string($oldProperties)) {
                $values = $this->parseServerProperties($oldProperties);
                $levelName = trim((string) ($values['level-name'] ?? ''));
                if (
                    $levelName !== ''
                    && $levelName !== '.'
                    && $levelName !== '..'
                    && !str_contains($levelName, '/')
                    && !str_contains($levelName, '\\')
                ) {
                    foreach ([$levelName, $levelName . '_nether', $levelName . '_the_end'] as $worldName) {
                        $keep[$worldName] = true;
                    }
                }
            }
        }

        $entries = $this->fileRepo->getDirectory('/');

        // Never remove an unrelated world folder merely because the user selected a clean
        // pack install. The dedicated Delete world option handles the active world only.
        foreach ($entries as $entry) {
            $name = (string) ($entry['name'] ?? '');
            if ($name === '' || $name === '.' || $name === '..' || !(bool) ($entry['directory'] ?? false)) {
                continue;
            }

            if ($this->remotePathExists('/' . $name . '/level.dat')) {
                $keep[$name] = true;
            }
        }

        $deleted = 0;
        foreach ($entries as $entry) {
            $name = (string) ($entry['name'] ?? '');
            if ($name === '' || $name === '.' || $name === '..' || isset($keep[$name])) {
                continue;
            }

            try {
                $this->fileRepo->deleteFiles('/', [$name]);
                $deleted++;
            } catch (Throwable $e) {
                throw new RuntimeException("Could not remove stale server entry /{$name}: " . $e->getMessage(), 0, $e);
            }
        }

        if ($keep) {
            $record->appendLog('  Preserved world folder(s): ' . implode(', ', array_keys($keep)) . '.');
        }
        $record->appendLog("  Removed {$deleted} old root entr" . ($deleted === 1 ? 'y' : 'ies') . '.');
    }

    private function stepDownload(ModpackInstall $record, string $url): void
    {
        $record->markStepRunning('download');
        $record->appendLog('Telling Wings to download the modpack archive…');
        $record->appendLog("  Source: {$url}");

        $this->fileRepo->pull($url, '/', [
            'filename'   => self::ARCHIVE_NAME,
            'foreground' => false,
        ]);

        $deadline = time() + 600;
        $lastSize = -1;
        $stable   = 0;

        while (time() < $deadline) {
            $this->ensureInstallNotDismissed($record);
            sleep(4);
            $this->ensureInstallNotDismissed($record);
            $size = $this->remoteFileSize('/', self::ARCHIVE_NAME);

            if ($size === null) {
                $record->appendLog('  …waiting for download to start');
                continue;
            }

            if ($size > 0 && $size === $lastSize) {
                if (++$stable >= 2) {
                    break;
                }
            } else {
                $stable = 0;
                $record->appendLog('  Downloaded ' . $this->humanBytes($size));
                $record->update(['progress' => min(45, 26 + (int) ($size / (8 * 1024 * 1024)))]);
            }

            $lastSize = $size;
        }

        if ($lastSize <= 0) {
            throw new RuntimeException('Download did not complete — archive not found on the server.');
        }

        $record->appendLog('  Download complete: ' . $this->humanBytes($lastSize));
        $record->update(['progress' => 48]);
        $record->markStepDone('download');
    }

    private function stepExtract(ModpackInstall $record): void
    {
        $record->markStepRunning('extract');
        $record->appendLog('Extracting archive on the server…');

        // Extract into a staging folder first: many server packs wrap everything in a
        // single "<Pack> Server Files/" folder, which would otherwise land as a
        // subdirectory while the egg installs a bare loader in the root.
        $staging = '/' . self::EXTRACT_DIR;
        $this->removeExtractStaging();
        $this->fileRepo->createDirectory(self::EXTRACT_DIR, '/');
        $this->fileRepo->renameFiles('/', [[
            'from' => self::ARCHIVE_NAME,
            'to'   => self::EXTRACT_DIR . '/' . self::ARCHIVE_NAME,
        ]]);
        $this->fileRepo->decompressFile($staging, self::ARCHIVE_NAME);
        $this->fileRepo->deleteFiles($staging, [self::ARCHIVE_NAME]);
        $record->appendLog('  Extraction complete.');

        $contentRoot = $this->locateExtractedContentRoot($staging);
        if ($contentRoot !== $staging) {
            $wrapper = substr($contentRoot, strlen($staging) + 1);
            $record->appendLog("  Archive wraps the server in \"{$wrapper}/\" — moving its contents into the server root.");
        }

        $moved = $this->moveExtractedIntoRoot($contentRoot, '/');
        $this->removeExtractStaging();
        $record->appendLog("  Placed {$moved} top-level entr" . ($moved === 1 ? 'y' : 'ies') . ' in the server root.');

        $this->repairExtractedPermissions($record);

        $record->update(['progress' => 58]);
        $record->markStepDone('extract');
    }

    private function removeExtractStaging(): void
    {
        try {
            $this->fileRepo->deleteFiles('/', [self::EXTRACT_DIR]);
        } catch (Throwable) {
            // Usually absent.
        }
    }

    /** Descend through lone wrapper folders (at most three levels) to the real server root. */
    private function locateExtractedContentRoot(string $dir): string
    {
        for ($depth = 0; $depth < 3; $depth++) {
            $entries = array_values(array_filter(
                $this->fileRepo->getDirectory($dir),
                fn (array $e) => !in_array((string) ($e['name'] ?? ''), ['', '.', '..', ...self::ARCHIVE_JUNK], true)
            ));

            if (count($entries) !== 1 || !(bool) ($entries[0]['directory'] ?? false)) {
                break;
            }

            $name = (string) $entries[0]['name'];
            if (in_array(strtolower($name), self::SERVER_ROOT_FOLDERS, true)) {
                break;
            }

            $child = rtrim($dir, '/') . '/' . $name;
            if ($this->fileRepo->getDirectory($child) === []) {
                break;
            }

            $dir = $child;
        }

        return $dir;
    }

    /**
     * Move extracted entries into the target, overwriting like an in-place extraction would.
     * Absent targets are moved with a single rename; only colliding directories are merged.
     */
    private function moveExtractedIntoRoot(string $sourceDir, string $targetDir): int
    {
        $existing = [];
        foreach ($this->fileRepo->getDirectory($targetDir) as $entry) {
            $existing[(string) ($entry['name'] ?? '')] = (bool) ($entry['directory'] ?? false);
        }

        $moved = 0;
        foreach ($this->fileRepo->getDirectory($sourceDir) as $entry) {
            $name = (string) ($entry['name'] ?? '');
            if (in_array($name, ['', '.', '..', ...self::ARCHIVE_JUNK], true)) {
                continue;
            }

            $sourcePath = rtrim($sourceDir, '/') . '/' . $name;
            $targetPath = rtrim($targetDir, '/') . '/' . $name;
            $isDirectory = (bool) ($entry['directory'] ?? false);

            if (array_key_exists($name, $existing)) {
                if ($isDirectory && $existing[$name]) {
                    $this->moveExtractedIntoRoot($sourcePath, $targetPath);
                    $moved++;
                    continue;
                }

                $this->fileRepo->deleteFiles($targetDir, [$name]);
            }

            $this->fileRepo->renameFiles('/', [[
                'from' => ltrim($sourcePath, '/'),
                'to'   => ltrim($targetPath, '/'),
            ]]);
            $moved++;
        }

        return $moved;
    }

    /**
     * FTB/ATLauncher have no single archive — files are pulled individually in
     * the assemble step — so the download/extract steps are marked done up front.
     */
    private function skipArchiveSteps(ModpackInstall $record): void
    {
        $record->markStepRunning('download');
        $record->appendLog('This provider has no single archive — files are fetched individually during assembly.');
        $record->update(['progress' => 48]);
        $record->markStepDone('download');

        $record->markStepRunning('extract');
        $record->update(['progress' => 58]);
        $record->markStepDone('extract');
    }

    private function stepRestoreConfig(ModpackInstall $record, array $options = []): void
    {
        $record->markStepRunning('restore_config');
        $record->appendLog('Restoring preserved player/admin files…');

        $saved = Cache::get("modpack-manager:config:{$record->id}", []);

        foreach ($saved as $filename => $content) {
            try {
                $this->fileRepo->putContent('/' . $filename, $content);
                $record->appendLog("  Restored: {$filename}");
            } catch (Throwable $e) {
                $record->appendLog("  WARNING: could not restore {$filename}: " . $e->getMessage());
            }
        }

        $this->restoreSafeServerProperties($record, !($options['delete_world'] ?? false));

        $record->update(['progress' => 96]);
        $record->markStepDone('restore_config');
    }

    private function restoreSafeServerProperties(ModpackInstall $record, bool $keepWorld): void
    {
        $old = Cache::get("modpack-manager:server-properties:{$record->id}");
        if (!is_string($old) || $old === '') {
            return;
        }

        try {
            $current = (string) $this->fileRepo->getContent('/server.properties', 5 * 1024 * 1024);
        } catch (Throwable) {
            $current = '';
        }

        $oldValues = $this->parseServerProperties($old);
        $merged = $current;
        $applied = [];

        foreach (config('modpack-manager.preserved_server_properties', []) as $key) {
            $key = trim((string) $key);
            if ($key === '' || !array_key_exists($key, $oldValues)) {
                continue;
            }

            $merged = $this->setServerProperty($merged, $key, $oldValues[$key]);
            $applied[] = $key;
        }

        // If the existing world is intentionally kept, keep its folder name too.
        // World-generation settings such as level-type, generator-settings, seed,
        // initial datapacks, etc. are intentionally NEVER copied from the old pack.
        if ($keepWorld && isset($oldValues['level-name']) && trim((string) $oldValues['level-name']) !== '') {
            $merged = $this->setServerProperty($merged, 'level-name', $oldValues['level-name']);
            $applied[] = 'level-name';
        }

        if ($merged === '') {
            return;
        }

        try {
            $this->fileRepo->putContent('/server.properties', rtrim($merged, "\r\n") . "\n");
            $record->appendLog(
                $applied
                    ? '  Merged safe server.properties settings: ' . implode(', ', $applied) . '. Pack world-generation settings were left untouched.'
                    : '  Kept the new pack server.properties unchanged so its world-generation settings remain authoritative.'
            );
        } catch (Throwable $e) {
            throw new RuntimeException('Could not write the final server.properties: ' . $e->getMessage(), 0, $e);
        }
    }

    private function parseServerProperties(string $text): array
    {
        $values = [];
        foreach (preg_split('/\r?\n/', $text) ?: [] as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            if ($key !== '') {
                $values[$key] = trim($value);
            }
        }

        return $values;
    }

    private function setServerProperty(string $text, string $key, string $value): string
    {
        $line = $key . '=' . $value;
        $pattern = '/^' . preg_quote($key, '/') . '=.*$/m';

        if (preg_match($pattern, $text)) {
            return (string) preg_replace_callback($pattern, fn () => $line, $text, 1);
        }

        if ($text !== '' && !str_ends_with($text, "\n")) {
            $text .= "\n";
        }

        return $text . $line . "\n";
    }

    /**
     * The "merge_configs" step does the real assembly work:
     *   - server_pack: just move overrides/ into root (usually none).
     *   - curseforge_build: download every mod from manifest.json + overrides.
     *   - modrinth: download every server-side file from modrinth.index.json + overrides.
     */
    private function stepAssembleAndMerge(ModpackInstall $record, array $plan, array $spec): void
    {
        $record->markStepRunning('merge_configs');

        if ($plan['mode'] === 'curseforge_build') {
            $this->assembleCurseForgeBuild($record, app(CurseForgeService::class));
        } elseif ($plan['mode'] === 'modrinth') {
            $this->assembleModrinth($record);
        } elseif ($plan['mode'] === 'ftb') {
            $this->assembleFtb($record, $plan);
        } elseif ($plan['mode'] === 'atlauncher') {
            $this->assembleATLauncher($record, $plan);
        } else {
            $record->appendLog('Server pack installed — no assembly required.');
        }

        // Move an extracted overrides/ folder into the server root (all modes).
        $this->mergeOverrides($record);

        $record->update(['progress' => 94]);
        $record->markStepDone('merge_configs');
    }

    private function stepFinalize(ModpackInstall $record): void
    {
        $record->markStepRunning('finalize');
        $record->appendLog('Finalizing…');

        try {
            $this->fileRepo->deleteFiles('/', [self::ARCHIVE_NAME]);
            $record->appendLog('  Removed download archive.');
        } catch (Throwable) {
            // Non-fatal.
        }

        $this->writeInstalledMetadata($record);
        $this->recordPackMods($record);

        Cache::forget("modpack-manager:config:{$record->id}");
        Cache::forget("modpack-manager:server-properties:{$record->id}");

        $record->update(['progress' => 98]);
        $record->markStepDone('finalize');
        $record->appendLog('Done.');
    }

    /**
     * Archive extraction can preserve mode 000 from a pack. Check permissions
     * immediately after extraction, before manifests, overrides, or launchers are used.
     * Wings performs each subtree search locally so the Panel does not need to make
     * one HTTP request for every directory in a large modpack.
     */
    private function repairExtractedPermissions(ModpackInstall $record): void
    {
        $filesFixed = 0;
        $directoriesFixed = 0;
        $checked = 0;

        try {
            $rootEntries = $this->fileRepo->getDirectory('/');
        } catch (Throwable $e) {
            throw new RuntimeException(
                'Could not inspect extracted file permissions in /: ' . $e->getMessage(),
                0,
                $e
            );
        }

        $scanRoots = [];
        $rootFilesToFix = [];
        $rootDirectoriesToFix = [];

        foreach ($rootEntries as $entry) {
            $name = (string) ($entry['name'] ?? '');
            if ($name === '' || $name === '.' || $name === '..' || (bool) ($entry['symlink'] ?? false)) {
                continue;
            }

            $checked++;
            $isDirectory = (bool) ($entry['directory'] ?? false);

            if ($this->entryHasNoPermissions($entry)) {
                if ($isDirectory) {
                    $rootDirectoriesToFix[] = $name;
                } else {
                    $rootFilesToFix[] = $name;
                }
            }

            if ($isDirectory) {
                $scanRoots[] = '/' . ltrim($name, '/');
            }
        }

        $this->chmodPermissionPaths($rootDirectoriesToFix, '0755', $directoriesFixed);
        $this->chmodPermissionPaths($rootFilesToFix, '0644', $filesFixed);

        $totalRoots = count($scanRoots);
        if ($totalRoots > 0) {
            $record->appendLog("  Permission scan: 0/{$totalRoots} top-level directories, {$checked} entries checked…");
        }

        $reportEvery = max(1, (int) ceil(max(1, $totalRoots) / 5));

        foreach ($scanRoots as $index => $scanRoot) {
            $directoryFixesBefore = $directoriesFixed;

            try {
                $entries = $this->fileRepo->search('*?*', $scanRoot);
                if (!is_array($entries) || !array_is_list($entries)) {
                    throw new RuntimeException('Wings returned an invalid recursive search response.');
                }

                $filesToFix = [];
                $directoriesToFix = [];

                foreach ($entries as $entry) {
                    if (!is_array($entry)) {
                        continue;
                    }

                    $name = (string) ($entry['name'] ?? '');
                    if ($name === '' || $name === '.' || $name === '..' || (bool) ($entry['symlink'] ?? false)) {
                        continue;
                    }

                    $checked++;
                    if (!$this->entryHasNoPermissions($entry)) {
                        continue;
                    }

                    $relativePath = ltrim(str_replace('\\', '/', $name), '/');
                    if ($relativePath === '') {
                        continue;
                    }

                    if ((bool) ($entry['directory'] ?? false)) {
                        $directoriesToFix[] = $relativePath;
                    } else {
                        $filesToFix[] = $relativePath;
                    }
                }

                $this->chmodPermissionPaths($directoriesToFix, '0755', $directoriesFixed);
                $this->chmodPermissionPaths($filesToFix, '0644', $filesFixed);

                // If a broken directory was repaired, make one exhaustive pass through
                // this subtree so entries hidden behind that directory are not missed.
                if ($directoriesFixed > $directoryFixesBefore) {
                    $this->repairPermissionsRecursively(
                        $scanRoot,
                        $filesFixed,
                        $directoriesFixed,
                        $checked
                    );
                }
            } catch (Throwable $e) {
                // Older Wings builds may not support the recursive search endpoint.
                // Keep correctness by falling back only for this top-level subtree.
                $record->appendLog("    Fast permission scan unavailable for {$scanRoot}; using compatibility scan.");
                $this->repairPermissionsRecursively(
                    $scanRoot,
                    $filesFixed,
                    $directoriesFixed,
                    $checked
                );
            }

            $done = $index + 1;
            if ($done === $totalRoots || $done % $reportEvery === 0) {
                $record->appendLog("  Permission scan: {$done}/{$totalRoots} top-level directories, {$checked} entries checked…");
            }
        }

        if ($filesFixed > 0 || $directoriesFixed > 0) {
            $record->appendLog(
                "  Permission check complete: {$checked} entries checked; repaired {$filesFixed} file(s), {$directoriesFixed} "
                . ($directoriesFixed === 1 ? 'directory' : 'directories')
                . '.'
            );
        } else {
            $record->appendLog("  Permission check complete: {$checked} entries checked; no unusable permissions found.");
        }
    }

    private function repairPermissionsRecursively(
        string $directory,
        int &$filesFixed,
        int &$directoriesFixed,
        int &$checked
    ): void {
        try {
            $entries = $this->fileRepo->getDirectory($directory);
        } catch (Throwable $e) {
            throw new RuntimeException(
                "Could not inspect extracted file permissions in {$directory}: " . $e->getMessage(),
                0,
                $e
            );
        }

        $filesToFix = [];

        foreach ($entries as $entry) {
            $name = (string) ($entry['name'] ?? '');
            if ($name === '' || $name === '.' || $name === '..' || (bool) ($entry['symlink'] ?? false)) {
                continue;
            }

            $checked++;
            $path = ($directory === '/' ? '' : rtrim($directory, '/')) . '/' . $name;
            $relativePath = ltrim($path, '/');
            $isDirectory = (bool) ($entry['directory'] ?? false);

            if ($this->entryHasNoPermissions($entry)) {
                if ($isDirectory) {
                    try {
                        $this->fileRepo->chmodFiles('/', [[
                            'file' => $relativePath,
                            'mode' => '0755',
                        ]]);
                    } catch (Throwable $e) {
                        throw new RuntimeException(
                            "Could not repair permissions on directory {$path}: " . $e->getMessage(),
                            0,
                            $e
                        );
                    }
                    $directoriesFixed++;
                } else {
                    $filesToFix[] = $relativePath;
                }
            }

            if ($isDirectory) {
                $this->repairPermissionsRecursively(
                    $path,
                    $filesFixed,
                    $directoriesFixed,
                    $checked
                );
            }

            if (count($filesToFix) >= 200) {
                $this->chmodPermissionPaths($filesToFix, '0644', $filesFixed);
                $filesToFix = [];
            }
        }

        if ($filesToFix !== []) {
            $this->chmodPermissionPaths($filesToFix, '0644', $filesFixed);
        }
    }

    private function entryHasNoPermissions(array $entry): bool
    {
        $modeBits = trim((string) ($entry['mode_bits'] ?? ''));
        if ($modeBits !== '') {
            return (bool) preg_match('/^0+$/', $modeBits);
        }

        $mode = trim((string) ($entry['mode'] ?? ''));
        return $mode === '----------' || $mode === 'd---------';
    }

    /**
     * @param array<int, string> $paths
     */
    private function chmodPermissionPaths(array $paths, string $mode, int &$fixed): void
    {
        foreach (array_chunk(array_values(array_unique($paths)), 200) as $chunk) {
            if ($chunk === []) {
                continue;
            }

            $files = array_map(
                fn (string $path) => ['file' => ltrim($path, '/'), 'mode' => $mode],
                $chunk
            );

            try {
                $this->fileRepo->chmodFiles('/', $files);
            } catch (Throwable $e) {
                throw new RuntimeException(
                    'Could not repair unusable extracted file permissions: ' . $e->getMessage(),
                    0,
                    $e
                );
            }

            $fixed += count($chunk);
        }
    }

    /**
     * When `store_metadata` is enabled, persist the installed-pack info into the
     * server's own files (a dotfile in the server root) so the Modpacks page can
     * recover the current pack even if the database record is lost. Best-effort:
     * a write failure never fails the install.
     */
    private function writeInstalledMetadata(ModpackInstall $record): void
    {
        if (!config('modpack-manager.store_metadata', false)) {
            return;
        }

        try {
            $this->fileRepo->putContent(
                ModpackInstall::METADATA_FILE,
                (string) json_encode($record->toMetadata(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            );
            $record->appendLog('  Wrote installed-pack metadata to ' . ModpackInstall::METADATA_FILE . '.');
        } catch (Throwable $e) {
            $record->appendLog('  Could not write metadata file (non-fatal): ' . $e->getMessage());
        }
    }

    /**
     * Best-effort: detect the pack's mod loader, switch the server to a matching
     * Forge/NeoForge/Fabric egg, set its MC + loader-version variables, and trigger
     * a reinstall so the egg installs the loader server on top of the files we placed.
     *
     * Never fatal — any problem just logs a warning and leaves the egg unchanged.
     */
    private function stepConfigureLoader(ModpackInstall $record, array $plan): void
    {
        $record->markStepRunning('configure_loader');
        $record->appendLog('Configuring startup / mod loader…');
        try {
            // Self-contained server packs (ServerPackCreator's start.sh, or a Forge/NeoForge
            // MDK run.sh) install AND launch their own loader. For those we just point the
            // server's startup command at the bundled launcher — no egg switch, no reinstall.
            //
            // ONLY a CurseForge official server pack can ship its own launcher. Every other
            // mode (modrinth, curseforge_build, ftb, atlauncher) is assembled by us with no
            // launcher, so we must NOT run this detection — otherwise a stale start.sh/run.sh
            // left behind by a *previous* server-pack install gets mistaken for this pack's
            // launcher and the egg is never switched (e.g. a Fabric pack landing on Forge).
            if ($plan['mode'] === 'server_pack') {
                // 1. Genuine bundled launcher (ServerPackCreator start.sh / MDK run.sh) that
                //    installs AND launches the loader itself — use it as-is, no reinstall.
                if ($this->configureSelfContainedPack($record, $plan)) {
                    $record->markStepDone('configure_loader');
                    return;
                }

                // 2. Installer-based server pack (AllTheMods-style: startserver.sh + a
                //    neoforge/forge installer jar, no run-ready launcher). The exact loader
                //    version is in the installer jar's filename — feed it to the egg path so
                //    the matching egg installs precisely that version on top of the mods.
                $launcherMeta = $this->detectServerPackLauncherMeta($record);
                if ($launcherMeta) {
                    $plan['loader'] = $launcherMeta['loader'];
                    $plan['version'] = $launcherMeta['version'] ?? ($plan['version'] ?? null);
                    $plan['mc'] = $launcherMeta['mc'] ?? ($plan['mc'] ?? null);
                    $record->appendLog("  Pack launcher requires {$launcherMeta['loader']} {$launcherMeta['version']}" . ($launcherMeta['mc'] ? " / MC {$launcherMeta['mc']}" : '') . ' — installing that exact runtime via the matching egg.');
                } else {
                    $installer = $this->detectInstallerJarMeta($record, $plan['loader'] ?? null);
                    if ($installer) {
                        $plan['loader']  = $installer['loader'];
                        $plan['version'] = $installer['version'] ?? ($plan['version'] ?? null);
                        $plan['mc']      = $plan['mc'] ?: ($installer['mc'] ?? null);
                        $record->appendLog("  Installer-based server pack — will install {$installer['loader']} {$installer['version']} via the matching egg.");
                    }
                }
            }

            // Otherwise we assembled the files ourselves (only mods/ + overrides, no launcher),
            // so switch to a matching loader egg and reinstall to install the loader server.
            $this->configureLoaderEggAndReinstall($record, $plan);
        } catch (Throwable $e) {
            $record->markStepFailed('configure_loader');
            throw new RuntimeException('Startup/loader configuration failed: ' . $e->getMessage(), 0, $e);
        }

        $record->markStepDone('configure_loader');
    }

    /**
     * Detect a self-contained server pack and configure the server to launch its bundled
     * installer/launcher. It switches the server to the matching loader egg (so the panel shows
     * the right egg and the correct Java images) but does NOT reinstall — reinstalling would
     * clobber the loader/files the pack already ships. The egg's startup is then overridden to
     * the bundled launcher.
     *
     *   - ServerPackCreator (start.sh + variables.txt): launch via `bash start.sh`. The
     *     ServerStarterJar installs/runs the loader from variables.txt on first boot.
     *   - Forge/NeoForge MDK server pack (run.sh referencing @libraries/.../unix_args.txt):
     *     launch via `bash run.sh nogui`.
     *
     * Returns true if it handled the pack (caller should stop); false to fall through to the
     * loader-egg + reinstall path.
     */
    private function configureSelfContainedPack(ModpackInstall $record, array $plan = []): bool
    {
        $root = [];
        try {
            foreach ($this->fileRepo->getDirectory('/') as $e) {
                $root[strtolower((string) ($e['name'] ?? ''))] = true;
            }
        } catch (Throwable) {
            return false;
        }

        $hasSpc = isset($root['start.sh']) && isset($root['variables.txt']);
        $candidates = [];
        if (isset($root['startserver.sh'])) {
            $candidates[] = 'startserver.sh';
        }
        if (isset($root['run.sh'])) {
            $candidates[] = 'run.sh';
        }

        if (!$hasSpc && !$candidates) {
            return false;
        }

        $server = $record->server->refresh();
        $loader = $mc = $loaderVer = null;
        $java = null;
        $startup = null;
        $launcher = null;
        $launcherMeta = null;

        if ($hasSpc) {
            $vars = $this->parseVarsTxt((string) $this->fileRepo->getContent('/variables.txt', 1024 * 1024));
            $mc = $vars['MINECRAFT_VERSION'] ?? null;
            $loader = isset($vars['MODLOADER']) ? strtolower($vars['MODLOADER']) : null;
            $loaderVer = $vars['MODLOADER_VERSION'] ?? null;
            $java = isset($vars['RECOMMENDED_JAVA_VERSION']) && is_numeric($vars['RECOMMENDED_JAVA_VERSION'])
                ? (int) $vars['RECOMMENDED_JAVA_VERSION']
                : null;
            $startup = 'bash start.sh';
            $launcher = 'start.sh';
            $record->appendLog('  ServerPackCreator pack detected — launching via start.sh.');
            $this->applyServerPackCreatorTweaks($record, $this->normalizeLoader($loader) ?? '');
        } else {
            foreach ($candidates as $candidate) {
                try {
                    $launcherText = (string) $this->fileRepo->getContent('/' . $candidate, 512 * 1024);
                } catch (Throwable) {
                    continue;
                }

                $meta = $this->parseLauncherSh($launcherText);
                $ready = !empty($meta['target']) && $this->remotePathExists((string) $meta['target']);
                $selfInstalling = $this->launcherCanInstallLoader($launcherText);

                if (!$meta['loader'] || (!$ready && !$selfInstalling)) {
                    continue;
                }

                $launcher = $candidate;
                $launcherMeta = $meta;
                $loader = $meta['loader'];
                $loaderVer = $meta['version'];
                $mc = $meta['mc'];
                $startup = $candidate === 'run.sh'
                    ? 'bash run.sh nogui'
                    : ($this->launcherAcceptsArguments($launcherText) ? 'bash startserver.sh nogui' : 'bash startserver.sh');

                $runtime = $ready ? 'existing runtime verified' : 'launcher installs its runtime on first start';
                $record->appendLog("  Self-contained {$candidate} detected — {$runtime}.");
                break;
            }

            if (!$launcher) {
                return false;
            }

            if (in_array($this->normalizeLoader($loader), ['forge', 'neoforge'], true)) {
                $this->writeUserJvmArgs($record);
            }
        }

        $loader = $loader ?: ($plan['loader'] ?? null);
        $mc = $mc ?: ($plan['mc'] ?? null);
        $loaderVer = $loaderVer ?: ($plan['version'] ?? null);

        $planLoader = $this->normalizeLoader($plan['loader'] ?? null);
        $diskLoader = $this->normalizeLoader($loader);
        if ($planLoader && $diskLoader && $planLoader !== $diskLoader) {
            $record->appendLog("  Pack metadata says “{$planLoader}”, but its launcher says “{$diskLoader}” — using the launcher.");
        }

        if (!$diskLoader) {
            $record->appendLog('  Bundled launcher found, but its loader could not be identified.');
            return false;
        }

        $egg = $this->switchToLoaderEgg($record, $server, $diskLoader, $mc, $loaderVer);
        if (!$egg) {
            throw new RuntimeException("No {$diskLoader} egg could be selected for this pack.");
        }
        $server = $server->refresh();

        $java = $java ?: $this->javaForMc($mc);
        $image = $this->pickJavaImageForVersion($server, $java);

        $update = ['startup' => $startup];
        if ($image) {
            $update['image'] = $image;
        }
        $server->update($update);
        $server = $server->refresh();

        if (!$this->remotePathExists('/' . $launcher)) {
            throw new RuntimeException("Configured launcher {$launcher} disappeared before startup was saved.");
        }

        if ($launcherMeta && !empty($launcherMeta['target'])) {
            try {
                $launcherText = (string) $this->fileRepo->getContent('/' . $launcher, 512 * 1024);
                if (!$this->remotePathExists((string) $launcherMeta['target']) && !$this->launcherCanInstallLoader($launcherText)) {
                    throw new RuntimeException("{$launcher} points to missing runtime {$launcherMeta['target']}.");
                }
            } catch (RuntimeException $e) {
                throw $e;
            } catch (Throwable $e) {
                throw new RuntimeException("Could not verify {$launcher}: " . $e->getMessage(), 0, $e);
            }
        }

        $detail = trim($diskLoader . ' ' . ($loaderVer ?? '') . ($mc ? " / MC {$mc}" : ''));
        $record->appendLog("  Egg: {$egg->name}; startup: {$startup}; Java {$java}; {$detail}.");
        $record->appendLog('  Startup preflight passed. The server should be ready to start without a manual loader change.');

        return true;
    }

    private function configureLoaderEggAndReinstall(ModpackInstall $record, array $plan): void
    {
        ['loader' => $loader, 'mc' => $mc, 'version' => $ver] = $this->detectLoader($record, $plan);

        if (!$loader) {
            throw new RuntimeException('Could not determine the mod loader from the pack metadata or files.');
        }

        $record->appendLog("  Detected loader: {$loader}" . ($ver ? " {$ver}" : '') . ($mc ? " (Minecraft {$mc})" : ''));

        $loaderKey = $this->normalizeLoader($loader);
        if (!$loaderKey) {
            throw new RuntimeException("No automatic Pelican egg mapping is available for loader {$loader}.");
        }

        // Normalize Forge's exact Maven coordinate before it reaches the egg.  The official
        // Forge egg treats a non-empty FORGE_VERSION as the literal Maven artifact version.
        // Legacy 1.7.10/1.8.9 artifacts repeat the Minecraft version at the end, so the pack's
        // build-only metadata (10.13.4.1614) must become
        // 1.7.10-10.13.4.1614-1.7.10.  Do this here, before the egg/variable layer, so every
        // provider follows the same rule and no later variable-name logic can bypass it.
        $eggLoaderVersion = $ver;
        if ($loaderKey === 'forge') {
            $eggLoaderVersion = $this->forgeVersionForEgg($mc, $ver);
            if ($eggLoaderVersion !== null && $eggLoaderVersion !== '' && $eggLoaderVersion !== trim((string) $ver)) {
                $record->appendLog("  Legacy Forge coordinate: {$ver} → {$eggLoaderVersion}");
            }
        }

        $server = $record->server->refresh();
        $egg = $this->switchToLoaderEgg($record, $server, $loaderKey, $mc, $eggLoaderVersion);
        if (!$egg) {
            throw new RuntimeException("No usable official Pelican {$loaderKey} egg is available.");
        }

        $server = $server->refresh();
        if ((bool) $server->skip_scripts) {
            $server->forceFill(['skip_scripts' => false])->saveOrFail();
            $server = $server->refresh();
            $record->appendLog('  Enabled egg installation scripts for the loader reinstall.');
        }

        $java = $plan['java'] ?? $this->javaForMc($mc);
        $image = $this->pickJavaImageForVersion($server, $java);
        $commands = array_values((array) ($egg->startup_commands ?? []));
        $defaultStartup = $commands[0] ?? null;
        if ((int) $server->memory === 0 && is_string($defaultStartup) && str_contains($defaultStartup, '-Xmx{{SERVER_MEMORY}}M')) {
            $defaultStartup = str_replace('-Xmx{{SERVER_MEMORY}}M', '-XX:MaxRAMPercentage=92.5', $defaultStartup);
            $record->appendLog('  Unlimited server memory detected — replaced -Xmx0M-prone startup with container-aware MaxRAMPercentage.');
        }

        $update = [];
        if ($image) {
            $update['image'] = $image;
        }
        if ($defaultStartup) {
            $update['startup'] = $defaultStartup;
        }
        if ($update) {
            $server->update($update);
            $server = $server->refresh();
        }

        if ($image) {
            $record->appendLog("  Set Java image: {$image} (Java {$java}).");
        }
        if ($defaultStartup) {
            $record->appendLog('  Reset startup to the selected egg default before reinstall.');
        }

        try {
            $server = app(ReinstallServerService::class)->handle($server->refresh());
            $record->appendLog("  Triggered {$loaderKey} egg reinstall; waiting for the loader runtime to finish installing…");

            $deadline = time() + 900;
            do {
                $this->ensureInstallNotDismissed($record);
                sleep(5);
                $this->ensureInstallNotDismissed($record);
                $server = $server->refresh();
                $status = $server->status;
                $statusValue = $status instanceof \BackedEnum ? $status->value : (string) $status;
                if ($statusValue !== 'installing') {
                    break;
                }
            } while (time() < $deadline);

            $status = $server->status;
            $statusValue = $status instanceof \BackedEnum ? $status->value : (string) $status;
            if ($statusValue === 'installing') {
                throw new RuntimeException('Pelican did not finish the loader reinstall within 15 minutes.');
            }

            $server = $server->refresh();
            $this->fileRepo->setServer($server);
            $this->verifyEggRuntime($record, $loaderKey, $mc);
            $this->configurePostReinstallLauncher($record, $server, $loaderKey);
            $record->appendLog("  {$egg->name} runtime verified; Java {$java}; server is ready to start.");
        } catch (Throwable $e) {
            throw new RuntimeException('Automatic loader installation failed: ' . $e->getMessage(), 0, $e);
        }
    }

    private function switchToLoaderEgg(ModpackInstall $record, Server $server, string $loader, ?string $mc, ?string $ver): ?Egg
    {

        // Prefer the canonical Pelican egg from pelican-eggs/minecraft. If it is not
        // installed yet, fetch the current upstream export directly from GitHub and import it.
        // This preserves Pelican's own name, icon, Docker images, settings, variables and update URL.
        $egg = $this->findOfficialPelicanEgg($loader);
        if (!$egg) {
            $egg = $this->importOfficialPelicanEgg($record, $loader);
        }

        if ($loader === 'quilt' && $egg) {
            // The upstream Quilt egg intentionally exposes MC_VERSION but not a Quilt Loader
            // version. Keep the official egg itself and add only the optional exact-version
            // hook required by modpacks that pin quilt-loader. The visible egg remains the
            // canonical Pelican Quilt egg with its upstream icon/settings.
            if (!$this->prepareOfficialQuiltEgg($record, $egg)) {
                $record->appendLog('  Could not prepare the official Quilt egg for the requested loader version — refusing to create a separate custom Quilt egg.');
                return null;
            }
        }

        if (!$egg) {
            $record->appendLog("  No canonical official Pelican {$loader} egg is available. Loader switching was stopped rather than using an unrelated/custom egg.");
            return null;
        }

        $tags = is_array($egg->tags) ? $egg->tags : [];
        $requiredTags = array_values(array_filter(
            array_map(fn ($tag) => trim((string) $tag), config('modpack-manager.required_egg_tags', ['minecraft'])),
            fn ($tag) => $tag !== ''
        ));
        $normalizedTags = array_map(fn ($tag) => strtolower(trim((string) $tag)), $tags);
        $changedTags = false;

        foreach ($requiredTags as $requiredTag) {
            $normalized = strtolower($requiredTag);
            if (!in_array($normalized, $normalizedTags, true)) {
                $tags[] = $requiredTag;
                $normalizedTags[] = $normalized;
                $changedTags = true;
            }
        }

        if ($changedTags) {
            $egg->tags = array_values(array_unique($tags));
            $egg->save();
            $egg->refresh();
        }

        if ((int) $server->egg_id === (int) $egg->id) {
            $record->appendLog("  Server already on the “{$egg->name}” egg.");
            $this->applyLoaderVariables($record, $server, $egg, $loader, $mc, $ver);
            return $egg;
        }

        // Use Pelican's canonical egg changer: it switches egg_id (+ default image/startup)
        // AND deletes the old server variables before recreating the new egg's set, so we
        // never leave stale Forge/Fabric/NeoForge vars piled on the server. (The old
        // StartupModificationService path only upserted variables — it never removed the
        // previous egg's ones, which is what caused the variable accumulation.)
        app(EggChangerService::class)->handle($server, $egg, keepOldVariables: false);
        $server->refresh();

        $this->applyLoaderVariables($record, $server, $egg, $loader, $mc, $ver);

        $record->appendLog("  Switched egg to “{$egg->name}”.");

        return $egg;
    }

    /**
     * Override the server's MC + loader-version variables on the egg it's currently on.
     * Writes straight to ServerVariable (the rows EggChangerService just created), picking
     * whichever variable name the egg actually defines.
     */
    private function applyLoaderVariables(ModpackInstall $record, Server $server, Egg $egg, string $loader, ?string $mc, ?string $ver): void
    {
        $applied = [];

        // Write $val into the FIRST candidate (by the candidates' own priority order) that
        // the egg actually defines. Iterating candidates — NOT the egg's variable list — is
        // essential: the loader version must land in the RIGHT variable when an egg defines
        // several of them. The official Fabric egg is the trap — it lists FABRIC_VERSION (the
        // *installer* version) BEFORE LOADER_VERSION (the loader), so matching by egg order
        // wrote the loader version into FABRIC_VERSION and the install script then fetched
        // fabric-installer/<loader>/… which 404s. Candidate priority fixes that.
        $set = function (array $candidates, ?string $val) use ($server, $egg, &$applied) {
            if ($val === null || $val === '') {
                return;
            }
            foreach ($candidates as $candidate) {
                foreach ($egg->variables as $v) {
                    if (strtoupper((string) $v->env_variable) === $candidate) {
                        ServerVariable::query()->updateOrCreate(
                            ['server_id' => $server->id, 'variable_id' => $v->id],
                            ['variable_value' => $val]
                        );
                        $applied[$v->env_variable] = $val;
                        return; // highest-priority candidate the egg defines wins
                    }
                }
            }
        };

        $set(['MC_VERSION', 'MINECRAFT_VERSION'], $mc);

        if ($loader === 'fabric') {
            foreach ($egg->variables as $v) {
                $env = strtoupper((string) $v->env_variable);
                if ($env === 'FABRIC_VERSION') {
                    $value = 'latest';
                } elseif ($env === 'LOADER_VERSION') {
                    $value = $ver ?: 'latest';
                } else {
                    continue;
                }

                ServerVariable::query()->updateOrCreate(
                    ['server_id' => $server->id, 'variable_id' => $v->id],
                    ['variable_value' => $value]
                );
                $applied[$v->env_variable] = $value;
            }
        } elseif ($loader === 'quilt') {
            $set(['QUILT_LOADER_VERSION', 'LOADER_VERSION'], $ver ?: 'latest');
        } else {
            // The Forge egg wants the FULL Maven version in FORGE_VERSION — it downloads
            // .../forge/${FORGE_VERSION}/forge-${FORGE_VERSION}-installer.jar, and Forge's artifact
            // is named "<mc>-<build>" (e.g. "1.20.1-47.4.0"). We carry the build number (47.4.0) and
            // the MC version separately, so stitch them together. Without this the installer URL 404s
            // and Forge never installs — the server then falls back to "-jar server.jar" (absent) and
            // dies with a Java "unable to access jarfile" error. NeoForge is the opposite: its egg
            // wants just the build number (21.1.221) in NEOFORGE_VERSION, so leave that untouched.
            $loaderVer = $ver;
            if ($loader === 'forge' && $ver !== null && $ver !== '' && $mc) {
                // Forge 1.7.10 and 1.8.9 use legacy Maven coordinates with the
                // Minecraft version repeated at the end (for example
                // 1.7.10-10.13.4.1614-1.7.10). The official Pelican Forge egg
                // handles this when it resolves a build itself, but when we
                // provide FORGE_VERSION explicitly we must provide that exact
                // coordinate or the reinstall can finish without server.jar.
                if (in_array($mc, ['1.7.10', '1.8.9'], true)) {
                    if (preg_match('/^' . preg_quote($mc, '/') . '-([0-9][0-9.]*)$/', $ver, $legacy)) {
                        $loaderVer = "{$mc}-{$legacy[1]}-{$mc}";
                    } elseif (preg_match('/^[0-9][0-9.]*$/', $ver)) {
                        $loaderVer = "{$mc}-{$ver}-{$mc}";
                    }
                } elseif (!str_contains($ver, '-')) {
                    $loaderVer = "{$mc}-{$ver}";
                }
            }

            $candidates = match ($loader) {
                'forge'    => ['FORGE_VERSION', 'BUILD_VERSION'],
                'neoforge' => ['NEOFORGE_VERSION', 'FORGE_VERSION', 'BUILD_VERSION'],
                default    => [],
            };

            if (($loaderVer === null || $loaderVer === '') && $loader === 'neoforge') {
                $loaderVer = $this->latestNeoForgeForMc($mc);
                if ($loaderVer !== null) {
                    $record->appendLog("  Pack does not state its NeoForge version; using the newest build for Minecraft {$mc}: {$loaderVer}.");
                }
            }

            if ($loaderVer === null || $loaderVer === '') {
                // Never keep the previous pack's loader version: the egg prefers it over
                // MC_VERSION, so e.g. ATM10's 21.1.x would install Minecraft 1.21.1 again.
                foreach ($candidates as $candidate) {
                    $variable = $egg->variables->first(fn ($v) => strtoupper((string) $v->env_variable) === $candidate);
                    if ($variable) {
                        ServerVariable::query()->updateOrCreate(
                            ['server_id' => $server->id, 'variable_id' => $variable->id],
                            ['variable_value' => (string) $variable->default_value]
                        );
                        $applied[$variable->env_variable] = (string) $variable->default_value;
                        $record->appendLog("  Loader version unknown — reset {$variable->env_variable} to the egg default so it follows MC_VERSION.");
                        break;
                    }
                }
            } else {
                $set($candidates, $loaderVer);
            }
        }

        if ($applied) {
            $record->appendLog('  Set variables: ' . json_encode($applied));
        }
    }

    /**
     * Newest NeoForge build for a Minecraft version, from NeoForge's Maven metadata.
     * 1.x versions map to "<minor>.<patch>." (1.21.1 → 21.1.x); from 26.x on the build is
     * prefixed by the full Minecraft version (26.1.2 → 26.1.2.x). Stable builds win over betas.
     */
    private function latestNeoForgeForMc(?string $mc): ?string
    {
        $mc = trim((string) $mc);
        if ($mc === '' || $mc === 'latest' || $mc === '1.20.1') {
            return null;
        }

        $prefix = str_starts_with($mc, '1.') ? substr($mc, 2) : $mc;
        if (substr_count($prefix, '.') === 0) {
            $prefix .= '.0';
        }
        $prefix .= '.';

        try {
            $xml = Cache::remember('modpack-manager:neoforge-maven-metadata', now()->addHour(), fn () => Http::timeout(15)
                ->get('https://maven.neoforged.net/releases/net/neoforged/neoforge/maven-metadata.xml')
                ->throw()
                ->body());
        } catch (Throwable) {
            return null;
        }

        preg_match_all('#<version>([^<]+)</version>#', $xml, $m);
        $matching = array_values(array_filter($m[1] ?? [], fn (string $v) => str_starts_with($v, $prefix)));
        if (!$matching) {
            return null;
        }

        $stable = array_values(array_filter($matching, fn (string $v) => !preg_match('/alpha|beta|rc/i', $v)));
        $pool = $stable ?: $matching;
        usort($pool, 'version_compare');

        return end($pool) ?: null;
    }

    /**
     * Convert pack Forge metadata into the exact version string expected by the official
     * Pelican Forge egg when FORGE_VERSION is explicitly populated.
     */
    private function forgeVersionForEgg(?string $mc, ?string $version): ?string
    {
        $mc = trim((string) $mc);
        $version = trim((string) $version);

        if ($version === '') {
            return null;
        }

        if ($mc === '') {
            return $version;
        }

        if (in_array($mc, ['1.7.10', '1.8.9'], true)) {
            // Already the complete legacy coordinate.
            if (str_starts_with($version, $mc . '-') && str_ends_with($version, '-' . $mc)) {
                return $version;
            }

            // Providers may give either just the Forge build (10.13.4.1614) or the normal
            // modern-style coordinate (1.7.10-10.13.4.1614).  Strip the leading MC version,
            // then rebuild the legacy coordinate deterministically.
            $build = str_starts_with($version, $mc . '-')
                ? substr($version, strlen($mc) + 1)
                : $version;

            if (str_ends_with($build, '-' . $mc)) {
                return $mc . '-' . $build;
            }

            return $mc . '-' . $build . '-' . $mc;
        }

        // Modern Forge uses <minecraft>-<forge-build> unless the provider already supplied
        // the complete coordinate.
        if (!str_starts_with($version, $mc . '-')) {
            return $mc . '-' . $version;
        }

        return $version;
    }

    /**
     * Normalise a loader name (from variables.txt MODLOADER or a manifest id) to one of
     * forge / neoforge / fabric / quilt, or null if unrecognised. LegacyFabric maps to fabric.
     */
    private function normalizeLoader(?string $loader): ?string
    {
        return match (strtolower(trim((string) $loader))) {
            'forge'                  => 'forge',
            'neoforge'               => 'neoforge',
            'fabric', 'legacyfabric' => 'fabric',
            'quilt'                  => 'quilt',
            default                  => null,
        };
    }

    /**
     * Pull the loader + Minecraft version out of a CurseForge file's gameVersions list
     * (a flat mix of MC versions and loader names, e.g. ["1.20.1", "NeoForge", "Client"]).
     * NeoForge is checked before Forge so a pack tagged with both resolves to neoforge.
     *
     * @param array<int, string> $gameVersions
     * @return array{loader:?string, mc:?string}
     */
    private function cfLoaderMeta(array $gameVersions, ?string $preferredLoader = null): array
    {
        $loader = $mc = null;
        $found  = [];

        foreach ($gameVersions as $gv) {
            $s = strtolower(trim((string) $gv));
            if ($l = $this->normalizeLoader($s)) {
                $found[$l] = true;
            } elseif (!$mc && preg_match('/^\d+\.\d+(?:\.\d+)?$/', $s)) {
                $mc = $s;
            }
        }

        $preferred = $this->normalizeLoader($preferredLoader);
        if ($preferred && !empty($found[$preferred])) {
            $loader = $preferred;
        } else {
            foreach (['neoforge', 'forge', 'quilt', 'fabric'] as $candidate) {
                if (!empty($found[$candidate])) {
                    $loader = $candidate;
                    break;
                }
            }
        }

        return ['loader' => $loader, 'mc' => $mc];
    }

    /**
     * Parse a ServerPackCreator variables.txt (KEY=value, # comments) into a map.
     *
     * @return array<string, string>
     */
    private function parseVarsTxt(string $txt): array
    {
        $out = [];
        foreach (preg_split('/\r?\n/', $txt) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $line, 2);
            $out[trim($k)] = trim(trim($v), "\"'");
        }
        return $out;
    }

    /**
     * The JVM args we hand modded servers: let the JVM size its heap from the container's
     * cgroup memory limit (the RAM Pelican assigned), instead of a hardcoded -Xmx.
     */
    private const JVM_MEMORY_ARGS = '-Xms128M -XX:MaxRAMPercentage=92.5';

    /**
     * Make a ServerPackCreator pack play nicely with Pelican: non-interactive, let Pelican
     * manage restarts, and let the JVM use the container's allocated memory.
     */
    private function applyServerPackCreatorTweaks(ModpackInstall $record, string $loader): void
    {
        try {
            $txt = (string) $this->fileRepo->getContent('/variables.txt', 1024 * 1024);
            $txt = preg_replace('/^\s*WAIT_FOR_USER_INPUT\s*=.*$/m', 'WAIT_FOR_USER_INPUT=false', $txt);
            $txt = preg_replace('/^\s*RESTART\s*=.*$/m', 'RESTART=false', $txt);
            // Fabric / Quilt / LegacyFabric launch with JAVA_ARGS from variables.txt.
            $txt = preg_replace('/^\s*JAVA_ARGS\s*=.*$/m', 'JAVA_ARGS="' . self::JVM_MEMORY_ARGS . '"', $txt);

            $this->fileRepo->putContent('/variables.txt', $txt);
            $record->appendLog('  Patched variables.txt (non-interactive, Pelican-managed restarts, container-aware memory).');
        } catch (Throwable $e) {
            $record->appendLog('  WARNING: could not patch variables.txt (' . $e->getMessage() . ').');
        }

        // Forge / NeoForge launch via run scripts that read user_jvm_args.txt.
        if (in_array($loader, ['forge', 'neoforge'], true)) {
            $this->writeUserJvmArgs($record);
        }
    }

    private function writeUserJvmArgs(ModpackInstall $record): void
    {
        try {
            $this->fileRepo->putContent(
                '/user_jvm_args.txt',
                "# Managed by Modpack Manager — lets the JVM use the container's allocated memory.\n" . self::JVM_MEMORY_ARGS . "\n"
            );
            $record->appendLog('  Set user_jvm_args.txt to use the container memory (MaxRAMPercentage=92.5).');
        } catch (Throwable $e) {
            $record->appendLog('  WARNING: could not write user_jvm_args.txt (' . $e->getMessage() . ').');
        }
    }

    /**
     * Work out loader/version/MC from a Forge/NeoForge run.sh that references
     * @libraries/net/<vendor>/.../unix_args.txt.
     *
     * @return array{loader:?string, version:?string, mc:?string}
     */
    private function detectServerPackLauncherMeta(ModpackInstall $record): ?array
    {
        foreach (['startserver.sh', 'run.sh'] as $launcher) {
            if (!$this->remotePathExists('/' . $launcher)) {
                continue;
            }

            try {
                $text = (string) $this->fileRepo->getContent('/' . $launcher, 512 * 1024);
                $meta = $this->parseLauncherSh($text);
            } catch (Throwable) {
                continue;
            }

            if (!empty($meta['loader'])) {
                $record->appendLog("  Found {$launcher} metadata: {$meta['loader']}" . (!empty($meta['version']) ? " {$meta['version']}" : '') . (!empty($meta['mc']) ? " / MC {$meta['mc']}" : '') . '.');
                $meta['launcher'] = $launcher;
                return $meta;
            }
        }

        return null;
    }

    private function configurePostReinstallLauncher(ModpackInstall $record, Server $server, string $loader): void
    {
        foreach (['startserver.sh', 'run.sh'] as $launcher) {
            if (!$this->remotePathExists('/' . $launcher)) {
                continue;
            }

            try {
                $text = (string) $this->fileRepo->getContent('/' . $launcher, 512 * 1024);
                $meta = $this->parseLauncherSh($text);
            } catch (Throwable) {
                continue;
            }

            if ($this->normalizeLoader($meta['loader'] ?? null) !== $loader) {
                continue;
            }
            if (empty($meta['target']) || !$this->remotePathExists((string) $meta['target'])) {
                continue;
            }

            $startup = $launcher === 'run.sh'
                ? 'bash run.sh nogui'
                : ($this->launcherAcceptsArguments($text) ? 'bash startserver.sh nogui' : 'bash startserver.sh');

            $server->update(['startup' => $startup]);
            $record->appendLog("  Pack launcher verified after reinstall; startup set to “{$startup}”.");
            return;
        }

        $record->appendLog('  No verified bundled launcher after reinstall — keeping the egg default startup.');
    }

    private function parseLauncherSh(string $txt): array
    {

        if (preg_match('#libraries/net/neoforged/neoforge/([0-9][0-9.]*)/unix_args\.txt#', $txt, $m)) {
            return [
                'loader' => 'neoforge',
                'version' => $m[1],
                'mc' => $this->mcFromNeoforge($m[1]),
                'target' => '/libraries/net/neoforged/neoforge/' . $m[1] . '/unix_args.txt',
            ];
        }

        if (preg_match('#libraries/net/neoforged/forge/([0-9]+\.[0-9]+(?:\.[0-9]+)?)-([0-9][0-9.]*)/unix_args\.txt#', $txt, $m)) {
            $fullVersion = $m[1] . '-' . $m[2];
            return [
                'loader' => 'neoforge',
                'version' => $fullVersion,
                'mc' => $m[1],
                'target' => '/libraries/net/neoforged/forge/' . $fullVersion . '/unix_args.txt',
            ];
        }

        if (preg_match('#libraries/net/minecraftforge/forge/([0-9]+\.[0-9]+(?:\.[0-9]+)?)-([0-9][0-9.]*)/unix_args\.txt#', $txt, $m)) {
            return [
                'loader' => 'forge',
                'version' => $m[2],
                'mc' => $m[1],
                'target' => '/libraries/net/minecraftforge/forge/' . $m[1] . '-' . $m[2] . '/unix_args.txt',
            ];
        }

        if (preg_match('/^\s*NEOFORGE_VERSION\s*=\s*["\']?([0-9][0-9.]*)["\']?\s*$/mi', $txt, $m)) {
            $version = $m[1];
            return [
                'loader' => 'neoforge',
                'version' => $version,
                'mc' => $this->mcFromNeoforge($version),
                'target' => '/libraries/net/neoforged/neoforge/' . $version . '/unix_args.txt',
            ];
        }

        $mc = null;
        if (preg_match('/^\s*(?:MINECRAFT_VERSION|MC_VERSION)\s*=\s*["\']?([0-9]+\.[0-9]+(?:\.[0-9]+)?)["\']?\s*$/mi', $txt, $m)) {
            $mc = $m[1];
        }

        if (preg_match('/^\s*FORGE_VERSION\s*=\s*["\']?([0-9]+\.[0-9]+(?:\.[0-9]+)?)-([0-9][0-9.]*)["\']?\s*$/mi', $txt, $m)) {
            return [
                'loader' => 'forge',
                'version' => $m[2],
                'mc' => $m[1],
                'target' => '/libraries/net/minecraftforge/forge/' . $m[1] . '-' . $m[2] . '/unix_args.txt',
            ];
        }

        if ($mc && preg_match('/^\s*FORGE_VERSION\s*=\s*["\']?([0-9][0-9.]*)["\']?\s*$/mi', $txt, $m)) {
            return [
                'loader' => 'forge',
                'version' => $m[1],
                'mc' => $mc,
                'target' => '/libraries/net/minecraftforge/forge/' . $mc . '-' . $m[1] . '/unix_args.txt',
            ];
        }

        if (stripos($txt, 'fabric-server-launch') !== false) {
            $loaderVersion = null;
            if (preg_match('/^\s*LOADER_VERSION\s*=\s*["\']?([^\s"\']+)["\']?\s*$/mi', $txt, $m)) {
                $loaderVersion = $m[1];
            }
            $jar = str_contains($txt, 'fabric-server-launcher.jar') ? 'fabric-server-launcher.jar' : 'fabric-server-launch.jar';
            return [
                'loader' => 'fabric',
                'version' => $loaderVersion,
                'mc' => $mc,
                'target' => '/' . $jar,
            ];
        }

        if (stripos($txt, 'quilt-server-launch') !== false) {
            $loaderVersion = null;
            if (preg_match('/^\s*(?:QUILT_LOADER_VERSION|LOADER_VERSION)\s*=\s*["\']?([^\s"\']+)["\']?\s*$/mi', $txt, $m)) {
                $loaderVersion = $m[1];
            }
            $jar = str_contains($txt, 'quilt-server-launcher.jar') ? 'quilt-server-launcher.jar' : 'quilt-server-launch.jar';
            return [
                'loader' => 'quilt',
                'version' => $loaderVersion,
                'mc' => $mc,
                'target' => '/' . $jar,
            ];
        }

        return ['loader' => null, 'version' => null, 'mc' => $mc, 'target' => null];
    }

    private function parseRunSh(string $txt): array
    {
        $meta = $this->parseLauncherSh($txt);
        return [
            'loader' => $meta['loader'],
            'version' => $meta['version'],
            'mc' => $meta['mc'],
        ];
    }

    private function launcherCanInstallLoader(string $txt): bool
    {
        return (bool) preg_match(
            '/(?:--installServer|-installServer|fabric-installer|quilt-installer|server\.jar\s+--installer|(?:neo)?forge[^\n]*installer\.jar)/i',
            $txt
        );
    }

    private function launcherAcceptsArguments(string $txt): bool
    {
        return str_contains($txt, '$@') || str_contains($txt, '$*');
    }

    private function remotePathExists(string $path): bool
    {
        $path = '/' . ltrim($path, '/');
        $dir = dirname($path);
        $name = basename($path);
        if ($name === '' || $name === '.' || $name === '..') {
            return false;
        }

        try {
            foreach ($this->fileRepo->getDirectory($dir === '.' ? '/' : $dir) as $entry) {
                if (($entry['name'] ?? null) === $name) {
                    return true;
                }
            }
        } catch (Throwable) {
        }

        return false;
    }

    private function verifyEggRuntime(ModpackInstall $record, string $loader, ?string $mc): void
    {
        if ($loader === 'fabric') {
            if (!$this->remotePathExists('/server.jar')) {
                throw new RuntimeException('Fabric egg reinstall finished, but server.jar was not created.');
            }
            $record->appendLog('  Preflight: Fabric launcher server.jar found.');
            return;
        }

        if ($loader === 'quilt') {
            if (!$this->remotePathExists('/server.jar')) {
                throw new RuntimeException('Quilt egg reinstall finished, but server.jar was not created.');
            }
            if (!$this->remotePathExists('/quilt-server-launcher.properties')) {
                throw new RuntimeException('Quilt egg reinstall finished, but quilt-server-launcher.properties was not created.');
            }
            $record->appendLog('  Preflight: Quilt launcher server.jar and launcher properties found.');
            return;
        }

        if ($loader === 'forge' && $mc && version_compare($mc, '1.17', '<')) {
            if (!$this->remotePathExists('/server.jar')) {
                $legacyJar = $this->findLegacyForgeServerJar($mc);
                if ($legacyJar !== null) {
                    $this->replaceRemoteEntry('/' . $legacyJar, '/server.jar');
                    $record->appendLog("  Legacy Forge compatibility: renamed {$legacyJar} to server.jar.");
                }
            }

            if (!$this->remotePathExists('/server.jar')) {
                throw new RuntimeException('Forge egg reinstall finished, but server.jar was not created.');
            }
            $record->appendLog('  Preflight: legacy Forge server.jar found.');
            return;
        }

        if (!$this->remotePathExists('/unix_args.txt')) {
            throw new RuntimeException("{$loader} egg reinstall finished, but unix_args.txt was not created.");
        }

        $record->appendLog("  Preflight: {$loader} unix_args.txt found.");
    }

    private function findLegacyForgeServerJar(string $mc): ?string
    {
        try {
            $entries = $this->fileRepo->getDirectory('/');
        } catch (Throwable) {
            return null;
        }

        $matches = [];
        foreach ($entries as $entry) {
            $name = (string) ($entry['name'] ?? '');
            if ($name === '' || !preg_match('/^forge-' . preg_quote($mc, '/') . '-.+\.jar$/i', $name)) {
                continue;
            }

            if (preg_match('/(?:installer|sources|javadoc|userdev|changelog)/i', $name)) {
                continue;
            }

            $priority = str_contains(strtolower($name), 'universal') ? 0 : 1;
            $matches[] = [$priority, $name];
        }

        if ($matches === []) {
            return null;
        }

        usort($matches, static fn (array $a, array $b): int => $a[0] <=> $b[0] ?: strnatcasecmp($a[1], $b[1]));

        return $matches[0][1];
    }

    private function detectInstallerJarMeta(ModpackInstall $record, ?string $preferLoader = null): ?array
    {
        try {
            $entries = $this->fileRepo->getDirectory('/');
        } catch (Throwable) {
            return null;
        }

        $found = [];
        foreach ($entries as $e) {
            $name = (string) ($e['name'] ?? '');

            if (preg_match('/^neoforge-([0-9][0-9.]*)-installer\.jar$/i', $name, $m)) {
                $found['neoforge'] = ['loader' => 'neoforge', 'version' => $m[1], 'mc' => $this->mcFromNeoforge($m[1])];
            } elseif (preg_match('/^forge-([0-9]+\.[0-9]+(?:\.[0-9]+)?)-([0-9][0-9.]*)-installer\.jar$/i', $name, $m)) {
                // forge-<mc>-<forgever>-installer.jar (e.g. forge-1.20.1-47.4.20-installer.jar)
                $found['forge'] = ['loader' => 'forge', 'version' => $m[2], 'mc' => $m[1]];
            }
        }

        if (!$found) {
            return null;
        }

        // Prefer the loader the pack metadata says it is (CurseForge gameVersions, carried on
        // the plan) — so a stale installer jar from a previous pack (an old ATM9 forge installer)
        // can never win over this pack's real (neoforge) one.
        $prefer = $this->normalizeLoader($preferLoader);
        if ($prefer && isset($found[$prefer])) {
            return $found[$prefer];
        }

        return $found['neoforge'] ?? $found['forge'] ?? reset($found);
    }

    /**
     * NeoForge versions track the MC version: 21.1.228 → 1.21.1, 20.4.x → 1.20.4.
     */
    private function mcFromNeoforge(string $ver): ?string
    {
        if (preg_match('/^(\d+)\.(\d+)\./', $ver, $m)) {
            return '1.' . $m[1] . ($m[2] !== '0' ? '.' . $m[2] : '');
        }
        return null;
    }

    /**
     * Pick a Java docker image for a specific major version, preferring one the server's
     * current egg already allows, else falling back to Pelican's yolks image.
     */
    private function pickJavaImageForVersion(Server $server, int $java): ?string
    {
        try {
            $images = $server->egg?->docker_images ?? [];
            foreach ($images as $label => $img) {
                if (stripos((string) $img, "java_{$java}") !== false
                    || stripos((string) $label, "java {$java}") !== false
                    || stripos((string) $label, "java_{$java}") !== false) {
                    return $img;
                }
            }
        } catch (Throwable) {
            // fall through to the default image
        }

        return "ghcr.io/pelican-eggs/yolks:java_{$java}";
    }

    /**
     * Work out the mod loader + versions from the files already on the server.
     *
     * @return array{loader:?string, mc:?string, version:?string}
     */
    private function detectLoader(ModpackInstall $record, array $plan): array
    {
        $loader = $mc = $version = null;

        // FTB/ATLauncher loader metadata comes from the provider API (carried in the plan),
        // not from a manifest written to disk.
        if (in_array($plan['mode'], ['ftb', 'atlauncher'], true)) {
            return [
                'loader'  => $plan['loader'] ?? null,
                'mc'      => $plan['mc'] ?? null,
                'version' => $plan['version'] ?? null,
            ];
        }

        try {
            if ($plan['mode'] === 'modrinth') {
                $index = json_decode((string) $this->fileRepo->getContent('/modrinth.index.json', 5 * 1024 * 1024), true);
                $deps  = is_array($index) ? ($index['dependencies'] ?? []) : [];
                $mc    = $deps['minecraft'] ?? null;

                foreach (['neoforge' => 'neoforge', 'forge' => 'forge', 'fabric-loader' => 'fabric', 'quilt-loader' => 'quilt'] as $key => $type) {
                    if (!empty($deps[$key])) {
                        $loader  = $type;
                        $version = (string) $deps[$key];
                        break;
                    }
                }
            } else {
                // curseforge_build always has manifest.json; some server packs do too.
                $manifest = json_decode((string) $this->fileRepo->getContent('/manifest.json', 5 * 1024 * 1024), true);
                if (is_array($manifest)) {
                    $mc = $manifest['minecraft']['version'] ?? null;
                    $id = $manifest['minecraft']['modLoaders'][0]['id'] ?? null;
                    if (is_string($id) && str_contains($id, '-')) {
                        [$type, $version] = explode('-', $id, 2);
                        $type   = strtolower($type);
                        $loader = in_array($type, ['forge', 'neoforge', 'fabric', 'quilt'], true) ? $type : null;
                    }
                }
            }
        } catch (Throwable) {
            // No manifest / unreadable — caller treats null loader as "leave egg alone".
        }

        // Fall back to the loader/MC carried on the plan (CurseForge file metadata).
        // A downloaded server pack usually has no manifest.json, so this is what lets
        // the egg switch for official server packs.
        return [
            'loader'  => $loader  ?? ($plan['loader']  ?? null),
            'mc'      => $mc      ?? ($plan['mc']       ?? null),
            'version' => $version ?? ($plan['version']  ?? null),
        ];
    }

    /**
     * The official Quilt egg exposes MC_VERSION but not an exact Quilt Loader variable.
     * Add that optional variable to the same official egg and teach its install script to
     * honor it without creating a second/custom egg.
     */
    private function prepareOfficialQuiltEgg(ModpackInstall $record, Egg $egg): bool
    {
        if (strtolower(trim((string) $egg->name)) !== 'quilt') {
            return false;
        }

        try {
            $script = str_replace("\r\n", "\n", (string) $egg->script_install);

            if (!str_contains($script, 'QUILT_LOADER_VERSION')) {
                $replacement = <<<'SCRIPT'
if [ -n "${QUILT_LOADER_VERSION:-}" ] && [ "${QUILT_LOADER_VERSION}" != "latest" ]; then
  java -jar quilt.jar install server "${MC_VERSION}" "${QUILT_LOADER_VERSION}" --download-server
else
  java -jar quilt.jar install server "${MC_VERSION}" --download-server
fi
SCRIPT;

                $multiline = "java -jar quilt.jar \\\n  install server \$MC_VERSION \\\n  --download-server";
                $singleLine = 'java -jar quilt.jar install server $MC_VERSION --download-server';

                if (str_contains($script, $multiline)) {
                    $script = str_replace($multiline, $replacement, $script, $count);
                } elseif (str_contains($script, $singleLine)) {
                    $script = str_replace($singleLine, $replacement, $script, $count);
                } else {
                    throw new RuntimeException('The official Quilt egg uses an unrecognized install script.');
                }

                if (($count ?? 0) !== 1) {
                    throw new RuntimeException('Could not safely add exact Quilt Loader support to the official egg.');
                }

                $egg->script_install = $script;
                $egg->saveOrFail();
            }

            EggVariable::query()->firstOrCreate(
                [
                    'egg_id' => $egg->id,
                    'env_variable' => 'QUILT_LOADER_VERSION',
                ],
                [
                    'name' => 'Quilt Loader Version',
                    'description' => 'Exact Quilt Loader version required by the selected modpack.',
                    'default_value' => 'latest',
                    'user_viewable' => true,
                    'user_editable' => true,
                    'rules' => ['required', 'string', 'max:32'],
                    'sort' => ((int) $egg->variables()->max('sort')) + 1,
                ]
            );

            $egg->load('variables');
            $record->appendLog('  Using the official “Quilt” egg with exact Quilt Loader support enabled.');

            return true;
        } catch (Throwable $e) {
            $record->appendLog('  WARNING: could not prepare the official Quilt egg (' . $e->getMessage() . ').');
            return false;
        }
    }

    /**
     * Canonical Pelican-native Minecraft loader eggs. These are the same exports offered by
     * pelican-eggs/minecraft, not plugin-maintained copies.
     *
     * @return array{url:string,format:EggFormat,uuid:string,path:string}|null
     */
    private function officialPelicanEggSource(string $loader): ?array
    {
        return match ($loader) {
            'forge' => [
                'url' => 'https://raw.githubusercontent.com/pelican-eggs/minecraft/refs/heads/main/java/forge/egg-forge-minecraft.yaml',
                'format' => EggFormat::YAML,
                'uuid' => 'ed072427-f209-4603-875c-f540c6dd5a65',
                'path' => '/java/forge/egg-forge-minecraft.yaml',
            ],
            'neoforge' => [
                'url' => 'https://raw.githubusercontent.com/pelican-eggs/minecraft/refs/heads/main/java/neoforge/egg-neo-forge.json',
                'format' => EggFormat::JSON,
                'uuid' => 'e23e092f-b803-4f34-82cf-2d6518c6351a',
                'path' => '/java/neoforge/egg-neo-forge.json',
            ],
            'fabric' => [
                'url' => 'https://raw.githubusercontent.com/pelican-eggs/minecraft/refs/heads/main/java/fabric/egg-fabric.yaml',
                'format' => EggFormat::YAML,
                'uuid' => '78b02ebb-fec8-49c5-943c-ca4aa117b693',
                'path' => '/java/fabric/egg-fabric.yaml',
            ],
            'quilt' => [
                'url' => 'https://raw.githubusercontent.com/pelican-eggs/minecraft/refs/heads/main/java/quilt/egg-quilt.yaml',
                'format' => EggFormat::YAML,
                'uuid' => 'dff33655-6e6a-4430-accf-e5aea04c2912',
                'path' => '/java/quilt/egg-quilt.yaml',
            ],
            default => null,
        };
    }

    private function findOfficialPelicanEgg(string $loader): ?Egg
    {
        $source = $this->officialPelicanEggSource($loader);
        if (!$source) {
            return null;
        }

        foreach (Egg::with('variables')->get() as $egg) {
            if (strtolower((string) $egg->uuid) === strtolower($source['uuid'])) {
                return $egg;
            }

            $updateUrl = strtolower((string) $egg->update_url);
            if ($updateUrl !== '' && str_contains($updateUrl, strtolower($source['path']))) {
                return $egg;
            }
        }

        return null;
    }

    private function importOfficialPelicanEgg(ModpackInstall $record, string $loader): ?Egg
    {
        $source = $this->officialPelicanEggSource($loader);
        if (!$source) {
            return null;
        }

        try {
            $record->appendLog("  No current official Pelican {$loader} egg is installed — downloading it from pelican-eggs/minecraft…");

            $response = Http::timeout(20)->retry(2, 300)->get($source['url']);
            if (!$response->successful() || trim($response->body()) === '') {
                throw new RuntimeException('GitHub returned HTTP ' . $response->status());
            }

            $imported = app(EggImporterService::class)->fromContent($response->body(), $source['format']);
            $record->appendLog("  Imported official Pelican egg “{$imported->name}” (id {$imported->id}) directly from pelican-eggs/minecraft.");

            return $imported->load('variables');
        } catch (Throwable $e) {
            $record->appendLog('  WARNING: could not download/import the official Pelican egg (' . $e->getMessage() . ').');
            return null;
        }
    }

    private function javaForMc(?string $mc): int
    {
        if (!$mc || !preg_match('/^(\d+)\.(\d+)(?:\.(\d+))?/', $mc, $m)) {
            return 17;
        }

        $major = (int) $m[1];
        $minor = (int) $m[2];
        $patch = (int) ($m[3] ?? 0);

        if ($major >= 26) return 25;
        if ($major !== 1) return 17;
        if ($minor >= 21) return 21;
        if ($minor === 20) return $patch >= 5 ? 21 : 17;
        if ($minor >= 17) return 17;

        return 8;
    }

    // ─── Assembly ───────────────────────────────────────────────────────────

    private function assembleCurseForgeBuild(ModpackInstall $record, CurseForgeService $cf): void
    {
        $record->appendLog('Reading manifest.json…');
        $manifest = json_decode((string) $this->fileRepo->getContent('/manifest.json', 5 * 1024 * 1024), true);

        if (!is_array($manifest) || empty($manifest['files'])) {
            throw new RuntimeException('Could not read manifest.json from the client pack.');
        }

        $mcVer  = $manifest['minecraft']['version'] ?? 'unknown';
        $loader = $manifest['minecraft']['modLoaders'][0]['id'] ?? 'unknown';
        $count  = count($manifest['files']);
        $record->appendLog("  Minecraft {$mcVer} / loader {$loader} — {$count} mods to download.");

        try { $this->fileRepo->createDirectory('mods', '/'); } catch (Throwable) {}

        $infos = $cf->getFilesByIds(array_map(fn ($f) => (int) $f['fileID'], $manifest['files']));

        // Resolve each project's type so resource packs / shaders / data packs go to
        // their own folder instead of all landing in /mods.
        $classes = [];
        try {
            $classes = $cf->getModClasses(array_map(fn ($f) => (int) ($f['projectID'] ?? 0), $manifest['files']));
        } catch (Throwable $e) {
            $record->appendLog('  NOTE: could not resolve project types (' . $e->getMessage() . ') — placing everything in /mods.');
        }

        $downloads   = [];
        $createdDirs  = ['/mods' => true];
        $folderCounts = [];
        foreach ($manifest['files'] as $f) {
            $fid  = (int) ($f['fileID'] ?? 0);
            $pid  = (int) ($f['projectID'] ?? 0);
            $info = $infos[$fid] ?? null;
            $url  = $info['url'] ?? null;
            $name = $info['name'] ?? null;

            if (empty($url)) {
                try {
                    $url  = $cf->getDownloadUrl($pid, $fid);
                    $name = $name ?: ('mod-' . $fid . '.jar');
                } catch (Throwable $e) {
                    throw new RuntimeException("Required CurseForge file #{$fid} could not be resolved: " . $e->getMessage(), 0, $e);
                }
            }

            $name      = $name ?: ('mod-' . $fid . '.jar');
            $folder    = $this->folderForCurseClass($classes[$pid] ?? null);
            $remoteDir = '/' . $folder;

            if (!isset($createdDirs[$remoteDir])) {
                try { $this->fileRepo->createDirectory($folder, '/'); } catch (Throwable) {}
                $createdDirs[$remoteDir] = true;
            }

            $downloads[] = ['url' => $url, 'dir' => $remoteDir, 'name' => $name, 'size' => $info['size'] ?? null];
            $folderCounts[$folder] = ($folderCounts[$folder] ?? 0) + 1;
        }

        $breakdown = implode(', ', array_map(fn ($d, $n) => "{$n} → {$d}/", array_keys($folderCounts), $folderCounts));
        $record->appendLog('  Prepared ' . count($downloads) . ' downloads for Wings' . ($breakdown ? " ({$breakdown})" : '') . '…');
        $this->pullFilesThrottled($record, $downloads);

        $note = "Assembled from a CurseForge CLIENT pack by Modpack Manager.\n"
              . "Minecraft version: {$mcVer}\nMod loader: {$loader}\n\n"
              . "Modpack Manager will try to switch this server to a matching loader egg\n"
              . "and reinstall so the loader server is installed automatically. If that\n"
              . "step is skipped (no matching egg), set your loader to the version above.\n";
        try { $this->fileRepo->putContent('/modpack-manager-README.txt', $note); } catch (Throwable) {}
    }

    private function assembleModrinth(ModpackInstall $record): void
    {
        $record->appendLog('Reading modrinth.index.json…');
        $index = json_decode((string) $this->fileRepo->getContent('/modrinth.index.json', 5 * 1024 * 1024), true);

        if (!is_array($index) || empty($index['files'])) {
            throw new RuntimeException('Could not read modrinth.index.json from the .mrpack.');
        }

        $deps = $index['dependencies'] ?? [];
        $record->appendLog('  Dependencies: ' . json_encode($deps));

        $downloads  = [];
        $createdDirs = [];
        foreach ($index['files'] as $f) {
            if (($f['env']['server'] ?? 'required') === 'unsupported') {
                continue;
            }

            $path = $f['path'] ?? null;
            $url  = $f['downloads'][0] ?? null;
            if (empty($path) || empty($url)) {
                throw new RuntimeException('A server-supported Modrinth file entry is missing its path or download URL.');
            }

            $dir  = trim(str_replace('\\', '/', dirname($path)), '/');
            $name = basename($path);
            $remoteDir = $dir === '' || $dir === '.' ? '/' : '/' . $dir;

            if ($remoteDir !== '/' && !isset($createdDirs[$remoteDir])) {
                try { $this->fileRepo->createDirectory($dir, '/'); } catch (Throwable) {}
                $createdDirs[$remoteDir] = true;
            }

            $downloads[] = [
                'url'  => $url,
                'dir'  => $remoteDir,
                'name' => $name,
                'size' => isset($f['fileSize']) ? (int) $f['fileSize'] : null,
            ];
        }

        $record->appendLog('  Prepared ' . count($downloads) . ' file downloads for Wings…');
        $this->pullFilesThrottled($record, $downloads);

        $note = "Installed from a Modrinth .mrpack by Modpack Manager.\n"
              . 'Dependencies: ' . json_encode($deps) . "\n\n"
              . "Modpack Manager will try to switch this server to a matching loader egg\n"
              . "and reinstall so the loader server is installed automatically. If that\n"
              . "step is skipped (no matching egg), set your loader per the dependencies above.\n";
        try { $this->fileRepo->putContent('/modpack-manager-README.txt', $note); } catch (Throwable) {}
    }

    /**
     * FTB: download each server-side file from its FTB-hosted URL. Files that only
     * carry a CurseForge {project,file} reference are resolved in one batch via
     * CurseForgeService (needs the CF key). Missing required files fail the install visibly.
     */
    private function assembleFtb(ModpackInstall $record, array $plan): void
    {
        $files = $plan['files'] ?? [];
        if (empty($files)) {
            throw new RuntimeException('FTB returned no installable files for this version.');
        }

        // Batch-resolve the CurseForge-referenced files (no direct URL).
        $cfFileIds = [];
        foreach ($files as $f) {
            if (empty($f['url']) && !empty($f['cfFile'])) {
                $cfFileIds[] = (int) $f['cfFile'];
            }
        }

        $cfInfos = [];
        if (!empty($cfFileIds)) {
            try {
                $cfInfos = app(CurseForgeService::class)->getFilesByIds($cfFileIds);
            } catch (Throwable $e) {
                $record->appendLog('  NOTE: ' . count($cfFileIds) . ' file(s) are CurseForge-hosted and need the CurseForge API key — they will be skipped (' . $e->getMessage() . ').');
            }
        }

        $downloads  = [];
        $createdDirs = [];
        $emptyFiles = [];
        foreach ($files as $f) {
            $name = $f['name'];
            $url  = $f['url'] ?? null;

            if (empty($url) && !empty($f['cfFile'])) {
                $url = $cfInfos[(int) $f['cfFile']]['url'] ?? null;
                if (empty($url) && !empty($f['cfProject'])) {
                    try {
                        $url = app(CurseForgeService::class)->getDownloadUrl((int) $f['cfProject'], (int) $f['cfFile']);
                    } catch (Throwable) {
                        $url = null;
                    }
                }
            }

            if (empty($url)) {
                throw new RuntimeException('Required FTB server file “' . $name . '” has no resolvable download URL.');
            }

            $dir       = trim((string) ($f['dir'] ?? ''), '/');
            $remoteDir = $dir === '' ? '/' : '/' . $dir;

            if ($remoteDir !== '/' && !isset($createdDirs[$remoteDir])) {
                try { $this->fileRepo->createDirectory($dir, '/'); } catch (Throwable) {}
                $createdDirs[$remoteDir] = true;
            }

            // Zero-byte placeholders (.gitkeep) cannot pass the remote download size check.
            if (($f['size'] ?? null) === 0) {
                $emptyFiles[] = rtrim($remoteDir, '/') . '/' . $name;
                continue;
            }

            $downloads[] = [
                'url'  => $url,
                'dir'  => $remoteDir,
                'name' => $name,
                'size' => $f['size'] ?? null,
                'sha1' => $f['sha1'] ?? null,
            ];
        }

        foreach ($emptyFiles as $path) {
            $this->fileRepo->putContent($path, '');
        }

        // FTB packs are mostly thousands of tiny config/datapack files. One Wings request
        // per file (three at a time) is the bottleneck, so small files are fetched by the
        // Panel in parallel and shipped as zip parts; large files still go Wings-direct.
        $small = [];
        $large = [];
        foreach ($downloads as $download) {
            $size = $download['size'] ?? null;
            if (($size !== null && $size <= self::BUNDLE_MAX_FILE_BYTES) || $this->requiresPanelDownloadFallback($download['url'])) {
                $small[] = $download;
            } else {
                $large[] = $download;
            }
        }

        $record->appendLog('  Prepared ' . count($downloads) . ' file downloads (' . count($small) . ' small via Panel bundles, ' . count($large) . ' large via Wings)…');
        $large = array_merge($this->installFilesViaBundles($record, $small), $large);
        $this->pullFilesThrottled($record, $large);

        $note = "Installed from an FTB pack by Modpack Manager.\n"
              . 'Minecraft: ' . ($plan['mc'] ?? 'unknown') . ' / loader: ' . ($plan['loader'] ?? 'unknown') . ' ' . ($plan['version'] ?? '') . "\n\n"
              . "Modpack Manager will try to switch this server to a matching loader egg and reinstall.\n";
        try { $this->fileRepo->putContent('/modpack-manager-README.txt', $note); } catch (Throwable) {}
    }

    /**
     * ATLauncher: every server mod is re-hosted on ATLauncher's CDN, so pull each
     * one directly (no CurseForge key needed), then download + extract the pack's
     * config zip if it has one.
     */
    private function assembleATLauncher(ModpackInstall $record, array $plan): void
    {
        $files = $plan['files'] ?? [];
        if (empty($files)) {
            throw new RuntimeException('ATLauncher returned no installable server mods for this version.');
        }

        try { $this->fileRepo->createDirectory('mods', '/'); } catch (Throwable) {}

        $downloads = [];
        foreach ($files as $f) {
            $url = $f['url'] ?? null;
            $name = $f['name'] ?? null;
            if (empty($url) || empty($name)) {
                throw new RuntimeException('ATLauncher returned a required server file without a download URL or filename.');
            }

            $remoteDir = '/' . trim((string) ($f['dir'] ?? 'mods'), '/');
            $downloads[] = ['url' => $url, 'dir' => $remoteDir, 'name' => $name];
        }

        $record->appendLog('  Prepared ' . count($downloads) . ' mod downloads for Wings…');
        $this->pullFilesThrottled($record, $downloads);

        if (!empty($plan['configsUrl'])) {
            $record->appendLog('  Downloading the pack config bundle…');
            $this->fileRepo->pull($plan['configsUrl'], '/', ['filename' => self::ARCHIVE_NAME, 'foreground' => false]);

            $deadline = time() + 300;
            $last = -1;
            $stable = 0;
            while (time() < $deadline) {
                $this->ensureInstallNotDismissed($record);
                sleep(4);
                $this->ensureInstallNotDismissed($record);
                $size = $this->remoteFileSize('/', self::ARCHIVE_NAME);
                if ($size === null) {
                    continue;
                }
                if ($size > 0 && $size === $last) {
                    if (++$stable >= 2) {
                        break;
                    }
                } else {
                    $stable = 0;
                }
                $last = $size;
            }

            if ($last > 0) {
                try {
                    $this->fileRepo->decompressFile('/', self::ARCHIVE_NAME);
                    $record->appendLog('  Extracted the config bundle.');
                    $this->repairExtractedPermissions($record);
                } catch (Throwable $e) {
                    $record->appendLog('  WARNING: could not extract config bundle (' . $e->getMessage() . ').');
                }
            }
        }

        $note = "Installed from an ATLauncher pack by Modpack Manager.\n"
              . 'Minecraft: ' . ($plan['mc'] ?? 'unknown') . ' / loader: ' . ($plan['loader'] ?? 'unknown') . ' ' . ($plan['version'] ?? '') . "\n\n"
              . "Modpack Manager will try to switch this server to a matching loader egg and reinstall.\n";
        try { $this->fileRepo->putContent('/modpack-manager-README.txt', $note); } catch (Throwable) {}
    }

    /**
     * Map a CurseForge class (project type) ID to the server folder its files belong in.
     * Unknown/mod types fall back to mods/.
     *
     * CurseForge Minecraft class IDs:
     *   6 = Mc Mods · 12 = Resource Packs · 6552 = Shaders · 6945 = Data Packs · 6541 = Customization
     */
    private function folderForCurseClass(?int $classId): string
    {
        return match ($classId) {
            12   => 'resourcepacks',
            6552 => 'shaderpacks',
            6945 => 'datapacks',
            default => 'mods',
        };
    }

    /**
     * Move the contents of an extracted overrides/ folder into the server root.
     */
    private function mergeOverrides(ModpackInstall $record): void
    {
        try {
            $moved = $this->mergeRemoteDirectory('/overrides', '/');
            $this->fileRepo->deleteFiles('/', ['overrides']);

            if ($moved > 0) {
                $record->appendLog('  Merged ' . $moved . ' file(s) from overrides/ into the server root.');
            }
        } catch (Throwable $e) {
            $record->appendLog('  Overrides merge skipped (' . $e->getMessage() . ')');
        }
    }

    private function mergeRemoteDirectory(string $sourceDir, string $targetDir): int
    {
        $entries = $this->fileRepo->getDirectory($sourceDir);
        $moved = 0;

        foreach ($entries as $entry) {
            $name = (string) ($entry['name'] ?? '');
            if ($name === '' || $name === '.' || $name === '..') {
                continue;
            }

            if ($targetDir === '/' && in_array($name, config('modpack-manager.preserved_files', []), true)) {
                continue;
            }

            $sourcePath = rtrim($sourceDir, '/') . '/' . $name;
            $targetPath = rtrim($targetDir, '/') . '/' . $name;

            if ((bool) ($entry['directory'] ?? false)) {
                $this->ensureRemoteDirectory($targetDir, $name);
                $moved += $this->mergeRemoteDirectory($sourcePath, $targetPath);
                continue;
            }

            $this->replaceRemoteEntry($sourcePath, $targetPath);
            $moved++;
        }

        return $moved;
    }

    private function ensureRemoteDirectory(string $parentDir, string $name): void
    {
        foreach ($this->fileRepo->getDirectory($parentDir) as $entry) {
            if (($entry['name'] ?? null) !== $name) {
                continue;
            }

            if ((bool) ($entry['directory'] ?? false)) {
                return;
            }

            $this->fileRepo->deleteFiles($parentDir, [$name]);
            break;
        }

        $this->fileRepo->createDirectory($name, $parentDir);
    }

    private function replaceRemoteEntry(string $sourcePath, string $targetPath): void
    {
        $targetParent = dirname($targetPath);
        $targetName = basename($targetPath);

        foreach ($this->fileRepo->getDirectory($targetParent) as $entry) {
            if (($entry['name'] ?? null) === $targetName) {
                $this->fileRepo->deleteFiles($targetParent, [$targetName]);
                break;
            }
        }

        $this->fileRepo->renameFiles('/', [[
            'from' => ltrim($sourcePath, '/'),
            'to'   => ltrim($targetPath, '/'),
        ]]);
    }

    // ─── Wings helpers ──────────────────────────────────────────────────────

    /**
     * Wings rejects the fourth simultaneous remote download for a server by
     * default. Submit downloads through a small moving window so large packs do
     * not trip that guard.
     *
     * @param array<int, array{url:string, dir:string, name:string}> $downloads
     */
    private function pullFilesThrottled(ModpackInstall $record, array $downloads): void
    {
        // Wings cannot pull an empty file (its completion check needs a non-zero size),
        // so files the source lists as zero bytes are written directly.
        $downloads = array_values(array_filter($downloads, function (array $download): bool {
            if (($download['size'] ?? null) !== 0) {
                return true;
            }

            $dir = rtrim((string) $download['dir'], '/');
            $this->fileRepo->putContent($dir . '/' . $download['name'], '');

            return false;
        }));

        if (empty($downloads)) {
            return;
        }

        $limit = min(3, $this->remoteDownloadConcurrency());
        $total = count($downloads);

        $record->appendLog("  Downloading {$total} file(s); Wings handles normal sources, up to {$limit} at a time, with automatic fallback for incompatible FTB sources…");

        $pending = array_values($downloads);
        $active = [];
        $done = 0;
        $lastLoggedDone = -10;
        $lastProgressAt = microtime(true);
        $deadline = time() + max(540, $total * 90);
        $busyLoggedAt = 0;
        $fallbackLogged = false;
        $statusApiAvailable = null;
        $statusFallbackLogged = false;

        $this->reportFileDownloadProgress($record, $done, $total, $lastLoggedDone, true);

        while (!empty($pending) || !empty($active)) {
            $this->ensureInstallNotDismissed($record);

            while (count($active) < $limit && !empty($pending)) {
                $this->ensureInstallNotDismissed($record);
                $next = $pending[0];

                if ($this->requiresPanelDownloadFallback((string) $next['url'])) {
                    $fallbackLimit = $this->panelFallbackConcurrency();
                    $fallbackBatch = [];
                    $stillPending = [];

                    foreach ($pending as $candidate) {
                        if (
                            count($fallbackBatch) < $fallbackLimit
                            && $this->requiresPanelDownloadFallback((string) ($candidate['url'] ?? ''))
                        ) {
                            $fallbackBatch[] = $candidate;
                        } else {
                            $stillPending[] = $candidate;
                        }
                    }

                    $pending = $stillPending;

                    if (!$fallbackLogged) {
                        $record->appendLog(
                            '  FTB source is incompatible with Wings remote pull (missing Content-Length); '
                            . "using parallel Panel → Wings fallback, up to {$fallbackLimit} at a time."
                        );
                        $fallbackLogged = true;
                    }

                    $this->ensureInstallNotDismissed($record);
                    $this->downloadFilesViaPanelFallbackBatch($record, $fallbackBatch);
                    $this->ensureInstallNotDismissed($record);
                    $done += count($fallbackBatch);
                    $lastProgressAt = microtime(true);
                    $this->reportFileDownloadProgress($record, $done, $total, $lastLoggedDone);
                    continue;
                }

                try {
                    $response = $this->fileRepo->pull($next['url'], $next['dir'], [
                        'filename'   => $next['name'],
                        'foreground' => false,
                    ]);
                    array_shift($pending);

                    $identifier = trim((string) ($response->json('identifier') ?? ''));
                    $active[] = $next + [
                        'identifier' => $identifier !== '' ? $identifier : null,
                        'lastSize' => null,
                        'stable' => 0,
                    ];
                    $lastProgressAt = microtime(true);
                } catch (Throwable $e) {
                    if (!$this->isRemoteDownloadLimitError($e)) {
                        throw $e;
                    }

                    if (time() - $busyLoggedAt >= 30) {
                        $record->appendLog('  Wings remote-download slots are full — waiting before queueing more files.');
                        $busyLoggedAt = time();
                    }

                    break;
                }
            }

            if (empty($active)) {
                if (empty($pending)) {
                    break;
                }

                if (time() >= $deadline) {
                    throw new RuntimeException("Timed out waiting for Wings download slots ({$done}/{$total} downloaded).");
                }

                usleep(250000);
                $this->ensureInstallNotDismissed($record);
                continue;
            }

            // Wings exposes its active remote downloads directly. Polling that list lets us
            // refill the three download slots as soon as a transfer finishes instead of
            // waiting for two five-second file-size samples for every batch.
            usleep(500000);
            $this->ensureInstallNotDismissed($record);

            $activeIds = null;
            if ($statusApiAvailable !== false) {
                try {
                    $activeIds = $this->activeRemoteDownloadProgress($record->server);
                    $statusApiAvailable = true;
                } catch (Throwable $e) {
                    $statusApiAvailable = false;
                    if (!$statusFallbackLogged) {
                        $record->appendLog('  Wings download-status endpoint unavailable; using fast file-size polling instead.');
                        $statusFallbackLogged = true;
                    }
                }
            }

            $remaining = [];
            $completed = [];

            foreach ($active as $file) {
                $identifier = $file['identifier'] ?? null;

                if ($statusApiAvailable === true && $identifier !== null) {
                    if (array_key_exists($identifier, $activeIds ?? [])) {
                        $lastProgressAt = microtime(true);
                        $remaining[] = $file;
                        continue;
                    }

                    $completed[] = $file;
                    continue;
                }

                // Compatibility fallback for older Wings builds that do not return a
                // download identifier/status list. One-second stable-size detection is
                // still much faster than the old two x five-second polling window.
                $size = $this->remoteFileSize($file['dir'], $file['name']);
                if ($size !== null && $size > 0) {
                    if ($file['lastSize'] !== null && $size === $file['lastSize']) {
                        $file['stable']++;
                    } else {
                        $file['stable'] = 0;
                        $file['lastSize'] = $size;
                        $lastProgressAt = microtime(true);
                    }

                    if ($file['stable'] >= 1) {
                        $completed[] = $file;
                        continue;
                    }
                }

                $remaining[] = $file;
            }

            if (!empty($completed)) {
                $this->verifyCompletedRemoteFiles($completed);
                $done += count($completed);
                $lastProgressAt = microtime(true);
                $this->reportFileDownloadProgress($record, $done, $total, $lastLoggedDone);
            }

            $active = $remaining;

            if (time() >= $deadline || microtime(true) - $lastProgressAt > 300) {
                throw new RuntimeException("Timed out waiting for Wings downloads ({$done}/{$total} downloaded).");
            }
        }

        $this->reportFileDownloadProgress($record, $done, $total, $lastLoggedDone, true);
    }

    /**
     * @return array<string,float> keyed by Wings download identifier
     */
    private function activeRemoteDownloadProgress(Server $server): array
    {
        $response = $this->fileRepo->getHttpClient()
            ->get("/api/servers/{$server->uuid}/files/pull");

        $active = [];
        foreach ((array) ($response->json('downloads') ?? []) as $download) {
            $identifier = trim((string) ($download['Identifier'] ?? $download['identifier'] ?? ''));
            if ($identifier === '') {
                continue;
            }

            $active[$identifier] = (float) ($download['Progress'] ?? $download['progress'] ?? 0.0);
        }

        return $active;
    }

    /**
     * Verify files that Wings says have left the active-download list. Directory
     * listings are grouped so a pack with hundreds of mods does not issue one API
     * request per completed file.
     *
     * @param array<int, array{dir:string,name:string}> $files
     */
    private function verifyCompletedRemoteFiles(array $files): void
    {
        $byDirectory = [];
        foreach ($files as $file) {
            $dir = (string) $file['dir'];
            $byDirectory[$dir][] = (string) $file['name'];
        }

        foreach ($byDirectory as $dir => $names) {
            $entries = [];
            foreach ($this->fileRepo->getDirectory($dir) as $entry) {
                $name = (string) ($entry['name'] ?? '');
                if ($name !== '') {
                    $entries[$name] = (int) ($entry['size'] ?? 0);
                }
            }

            foreach ($names as $name) {
                if (!isset($entries[$name]) || $entries[$name] <= 0) {
                    throw new RuntimeException("Wings finished a remote download but {$dir}/{$name} is missing or empty.");
                }
            }
        }
    }

    /**
     * Download files on the Panel in parallel, pack them into zip parts (bounded by the
     * node's upload limit), upload each part to Wings and extract it in the server root.
     * Returns the files that could not be bundled so the caller can pull them normally.
     *
     * @param array<int, array{url:string, dir:string, name:string, size:?int, sha1:?string}> $files
     * @return array<int, array{url:string, dir:string, name:string, size:?int, sha1:?string}>
     */
    private function installFilesViaBundles(ModpackInstall $record, array $files): array
    {
        if (empty($files)) {
            return [];
        }

        $server = $record->server;
        $uploadLimit = max(1, (int) ($server->node->upload_size ?? 100)) * 1024 * 1024;
        $partLimit = (int) min(self::BUNDLE_PART_BYTES, $uploadLimit * 0.9);
        $disk = Storage::disk('local');
        try {
            $disk->makeDirectory(self::BUNDLE_DIR);
        } catch (Throwable $e) {
            $record->appendLog('  Panel bundling unavailable (' . $e->getMessage() . '); using per-file downloads.');
            return $files;
        }

        $total = count($files);
        $done = 0;
        $lastLoggedDone = -10;
        $parts = 0;
        $zip = null;
        $zipFile = null;
        $zipBytes = 0;
        $started = microtime(true);

        $record->appendLog('  Bundling ' . $total . ' small file(s) on the Panel (' . self::BUNDLE_DOWNLOAD_CONCURRENCY . ' parallel downloads, ≤' . $this->humanBytes($partLimit) . ' zip parts)…');

        try {
            foreach (array_chunk($files, self::BUNDLE_DOWNLOAD_CONCURRENCY) as $chunk) {
                $this->ensureInstallNotDismissed($record);
                $bodies = $this->fetchBundleChunk($record, $chunk);

                foreach ($chunk as $index => $file) {
                    if ($zip === null) {
                        $zipFile = self::BUNDLE_DIR . '/bundle-' . $record->id . '-' . (++$parts) . '.zip';
                        $zip = new \ZipArchive();
                        if ($zip->open($disk->path($zipFile), \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                            throw new RuntimeException('Could not create ' . $zipFile);
                        }
                    }

                    $entry = ltrim(rtrim((string) $file['dir'], '/') . '/' . $file['name'], '/');
                    $zip->addFromString($entry, $bodies[$index]);
                    $zipBytes += strlen($bodies[$index]);

                    if ($zipBytes >= $partLimit) {
                        $this->shipBundle($record, $zip, $zipFile, $parts);
                        $zip = null;
                        $zipBytes = 0;
                    }
                }

                $done += count($chunk);
                $this->reportFileDownloadProgress($record, $done, $total, $lastLoggedDone);
            }

            if ($zip !== null) {
                $this->shipBundle($record, $zip, $zipFile, $parts);
                $zip = null;
            }
        } catch (InstallCancelledException $e) {
            throw $e;
        } catch (Throwable $e) {
            if ($zip !== null) {
                $zip->close();
            }
            if ($zipFile !== null) {
                $disk->delete($zipFile);
            }

            // Parts already extracted are on the server; the rest are re-pulled per file.
            $remaining = array_slice($files, $done);
            $record->appendLog('  Panel bundling stopped (' . $e->getMessage() . '); downloading the remaining ' . count($remaining) . ' file(s) individually.');

            return $remaining;
        }

        $this->reportFileDownloadProgress($record, $done, $total, $lastLoggedDone, true);
        $record->appendLog(sprintf('  Bundled %d file(s) in %d part(s) in %.0fs.', $total, $parts, microtime(true) - $started));

        return [];
    }

    /**
     * @param array<int, array{url:string, name:string, size:?int, sha1:?string}> $chunk
     * @return array<int, string> bodies keyed like $chunk
     */
    private function fetchBundleChunk(ModpackInstall $record, array $chunk): array
    {
        $bodies = [];
        $pending = $chunk;
        $errors = [];

        for ($attempt = 1; $attempt <= 3 && !empty($pending); $attempt++) {
            $responses = Http::pool(fn ($pool) => array_map(
                fn ($index) => $pool->as((string) $index)
                    ->withHeaders(['User-Agent' => 'pelican-modpack-manager/1.6.9'])
                    ->connectTimeout(10)
                    ->timeout(60)
                    ->get((string) $pending[$index]['url']),
                array_keys($pending)
            ));

            $retry = [];
            foreach ($pending as $index => $file) {
                $response = $responses[(string) $index] ?? null;
                if (!$response instanceof \Illuminate\Http\Client\Response || !$response->successful()) {
                    $errors[$file['name']] = $response instanceof \Illuminate\Http\Client\Response ? 'HTTP ' . $response->status() : 'request failed';
                    $retry[$index] = $file;
                    continue;
                }

                $body = $response->body();
                if (!empty($file['sha1']) && sha1($body) !== $file['sha1']) {
                    $errors[$file['name']] = 'checksum mismatch';
                    $retry[$index] = $file;
                    continue;
                }

                $bodies[$index] = $body;
            }

            $pending = $retry;
            if (!empty($pending)) {
                $this->ensureInstallNotDismissed($record);
                usleep(500000 * $attempt);
            }
        }

        if (!empty($pending)) {
            $first = array_key_first($pending);
            $name = $pending[$first]['name'];
            throw new RuntimeException('could not download ' . count($pending) . " file(s), e.g. {$name}: " . ($errors[$name] ?? 'unknown error'));
        }

        return $bodies;
    }

    /** @param string $zipFile path on the local Storage disk */
    private function shipBundle(ModpackInstall $record, \ZipArchive $zip, string $zipFile, int $part): void
    {
        if (!$zip->close()) {
            throw new RuntimeException('Could not finalize ' . $zipFile);
        }

        $remoteName = '.modpack-bundle-' . $part . '.zip';

        try {
            $this->uploadToWings($record->server, $zipFile, $remoteName);
            $this->fileRepo->decompressFile('/', $remoteName);
        } finally {
            Storage::disk('local')->delete($zipFile);
            try {
                $this->fileRepo->deleteFiles('/', [$remoteName]);
            } catch (Throwable) {
            }
        }
    }

    /** Upload a file from the local Storage disk to the server root through Wings' signed upload endpoint. */
    private function uploadToWings(Server $server, string $localFile, string $remoteName): void
    {
        $jwt = app(\App\Services\Nodes\NodeJWTService::class)
            ->setExpiresAt(\Carbon\CarbonImmutable::now()->addMinutes(15))
            ->setUser($server->user)
            ->setClaims(['server_uuid' => $server->uuid]);

        if (method_exists($jwt, 'setScopes') && enum_exists('App\\Enums\\NodeJwtScope')) {
            $jwt->setScopes(constant('App\\Enums\\NodeJwtScope::FileUpload'));
        }

        // Wings rejects a reused token id, so make each upload's id unique.
        $token = $jwt->handle($server->node, $server->user->id . $server->uuid . bin2hex(random_bytes(8)));

        $handle = Storage::disk('local')->readStream($localFile);
        if (!is_resource($handle)) {
            throw new RuntimeException('Could not read ' . $localFile);
        }

        try {
            Http::withOptions(['verify' => (bool) app()->environment('production')])
                ->connectTimeout(10)
                ->timeout(900)
                ->attach('files', $handle, $remoteName)
                ->post($server->node->getConnectionAddress() . '/upload/file?' . http_build_query([
                    'token'     => $token->toString(),
                    'directory' => '/',
                ]))
                ->throw();
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
    }

    private function requiresPanelDownloadFallback(string $url): bool
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if ($scheme !== 'https') {
            return false;
        }

        if ($host === 'dist.modpacks.ch') {
            return true;
        }

        // files.feed-the-beast.com gzips text types for clients that accept it (Wings'
        // Go client always does) and then sends no Content-Length, which Wings rejects.
        $extension = strtolower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));

        return $host === 'files.feed-the-beast.com'
            && in_array($extension, ['json', 'txt', 'xml', 'html', 'htm', 'css', 'csv', 'svg'], true);
    }

    /**
     * Download a small batch of FTB-hosted files concurrently through the Panel.
     * dist.modpacks.ch omits Content-Length, so Wings cannot pull these URLs
     * directly. Fetching several at once removes the otherwise fully-serial FTB
     * bottleneck while keeping memory usage bounded by a conservative batch size.
     *
     * @param array<int, array{url:string, dir:string, name:string}> $files
     */
    private function downloadFilesViaPanelFallbackBatch(ModpackInstall $record, array $files): void
    {
        $remaining = array_values($files);
        $errors = [];

        for ($attempt = 1; $attempt <= 3 && !empty($remaining); $attempt++) {
            $this->ensureInstallNotDismissed($record);

            try {
                $responses = Http::pool(function ($pool) use ($record, $remaining) {
                    $requests = [];

                    foreach ($remaining as $index => $file) {
                        $lastCancelCheck = 0.0;
                        $requests[] = $pool->as((string) $index)
                            ->withHeaders([
                                'User-Agent' => 'pelican-modpack-manager/1.6.9',
                            ])
                            ->withOptions([
                                'progress' => function () use ($record, &$lastCancelCheck): void {
                                    $now = microtime(true);
                                    if (($now - $lastCancelCheck) >= 0.5) {
                                        $lastCancelCheck = $now;
                                        $this->ensureInstallNotDismissed($record);
                                    }
                                },
                            ])
                            ->connectTimeout(10)
                            ->timeout(60)
                            ->get((string) $file['url']);
                    }

                    return $requests;
                });
            } catch (Throwable $e) {
                $this->ensureInstallNotDismissed($record);

                foreach ($remaining as $file) {
                    $errors[(string) $file['name']] = $e->getMessage();
                }

                if ($attempt < 3) {
                    usleep(500000);
                    continue;
                }

                break;
            }

            $retry = [];

            foreach ($remaining as $index => $file) {
                $name = (string) $file['name'];
                $response = $responses[(string) $index] ?? null;

                if (!is_object($response) || !method_exists($response, 'successful') || !$response->successful()) {
                    $status = is_object($response) && method_exists($response, 'status')
                        ? 'HTTP ' . $response->status()
                        : (is_object($response) && method_exists($response, 'getMessage')
                            ? $response->getMessage()
                            : 'request failed');
                    $errors[$name] = $status;
                    $retry[] = $file;
                    continue;
                }

                try {
                    $body = $response->body();
                    if ($body === '') {
                        throw new RuntimeException('empty response body');
                    }

                    $dir = '/' . trim((string) $file['dir'], '/');
                    $path = $dir === '/'
                        ? '/' . $name
                        : rtrim($dir, '/') . '/' . $name;

                    $this->fileRepo->putContent($path, $body);
                    unset($errors[$name]);
                } catch (Throwable $e) {
                    $errors[$name] = $e->getMessage();
                    $retry[] = $file;
                }
            }

            $remaining = $retry;

            if (!empty($remaining) && $attempt < 3) {
                $this->ensureInstallNotDismissed($record);
                usleep(500000);
            }
        }

        if (!empty($remaining)) {
            $first = $remaining[0];
            $name = (string) ($first['name'] ?? 'unknown file');
            $lastError = $errors[$name] ?? 'unknown error';

            throw new RuntimeException(
                'Could not download FTB-hosted file “' . $name . '” using the parallel Panel fallback: ' . $lastError
            );
        }
    }

    private function reportFileDownloadProgress(
        ModpackInstall $record,
        int $done,
        int $total,
        int &$lastLoggedDone,
        bool $force = false
    ): void {
        $record->update([
            'progress' => min(92, 64 + (int) (28 * $done / max(1, $total))),
        ]);

        if ($force || $done === $total || $done - $lastLoggedDone >= 10) {
            $record->appendLog("  Downloaded {$done}/{$total} files…");
            $lastLoggedDone = $done;
        }
    }

    private function remoteDownloadConcurrency(): int
    {
        return max(
            1,
            (int) config('modpack-manager.remote_download_concurrency', self::DEFAULT_REMOTE_DOWNLOAD_CONCURRENCY)
        );
    }

    private function panelFallbackConcurrency(): int
    {
        return max(
            1,
            min(
                8,
                (int) config('modpack-manager.panel_fallback_concurrency', self::DEFAULT_PANEL_FALLBACK_CONCURRENCY)
            )
        );
    }

    private function isRemoteDownloadLimitError(Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'simultaneous remote file downloads')
            || str_contains($message, 'reached its limit');
    }

    private function remoteFileSize(string $dir, string $name): ?int
    {
        try {
            foreach ($this->fileRepo->getDirectory($dir) as $entry) {
                if (($entry['name'] ?? null) === $name) {
                    return (int) ($entry['size'] ?? 0);
                }
            }
        } catch (Throwable) {
            // ignore
        }

        return null;
    }

    private function humanBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
        if ($bytes >= 1048576)    return round($bytes / 1048576, 1) . ' MB';
        if ($bytes >= 1024)       return round($bytes / 1024, 1) . ' KB';
        return $bytes . ' B';
    }
}
