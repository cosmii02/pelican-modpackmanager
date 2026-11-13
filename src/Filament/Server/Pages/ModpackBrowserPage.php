<?php

namespace Cosmii02\ModpackManager\Filament\Server\Pages;

use App\Enums\SubuserPermission;
use App\Models\Backup;
use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Cosmii02\ModpackManager\Jobs\InstallModpackJob;
use Cosmii02\ModpackManager\Models\ModpackInstall;
use Cosmii02\ModpackManager\Services\ATLauncherService;
use Cosmii02\ModpackManager\Services\CurseForgeService;
use Cosmii02\ModpackManager\Services\FtbService;
use Cosmii02\ModpackManager\Services\ModrinthService;
use Cosmii02\ModpackManager\Services\UpdateCheckService;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Locked;
use RuntimeException;
use Throwable;

/**
 * Server-panel page for browsing and installing Minecraft modpacks.
 * URL: /server/{server}/modpacks
 */
class ModpackBrowserPage extends Page
{
    /**
     * A pending/installing record that hasn't advanced in this many seconds is
     * treated as dead (worker crashed/stopped). Longer than the worst-case backup
     * (15 min) + download waits so a slow-but-live install is never killed.
     */
    private const STALE_AFTER_SECONDS = 1200; // 20 minutes

    /**
     * Subuser permission required to view this page and install/update modpacks.
     * Installing replaces files, switches the egg and reinstalls the server, so
     * "reinstall" is the matching authority. The server owner and admins always
     * pass. Change this one line to loosen/tighten the gate.
     */
    private const MANAGE_PERMISSION = SubuserPermission::SettingsReinstall;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-archive-box-arrow-down';
    protected static ?string $navigationLabel = 'Modpacks';
    protected static ?string $slug            = 'modpacks';
    protected static ?int    $navigationSort  = 50;

    /**
     * Sidebar position is admin-configurable via the plugin settings (persisted
     * as MODPACK_MANAGER_NAV_SORT). Lower numbers sit higher in the sidebar.
     */
    public static function getNavigationSort(): ?int
    {
        return (int) config('modpack-manager.navigation_sort', static::$navigationSort);
    }
    protected string         $view            = 'modpack-manager::filament.server.pages.modpack-browser-page';

    // ─── State ────────────────────────────────────────────────────────────────

    public string $search    = '';
    public string $provider  = 'all';   // 'all' | 'curseforge' | 'modrinth' | 'ftb' | 'atlauncher'

    public string $filterVersion  = '';
    public array  $filterLoaders  = [];
    public string $filterCategory = '';
    public string $filterSort     = 'popular';

    /** Providers queried by the combined "all" view. */
    private const COMBINED_PROVIDERS = ['curseforge', 'modrinth', 'ftb', 'atlauncher'];
    #[Locked]
    public array  $modpacks      = [];
    public bool   $isLoading     = false;
    public bool   $isLoadingMore = false;
    public bool   $hasMore       = false;
    public int    $page          = 0;
    public int    $pageSize      = 20;
    public string $errorMsg      = '';
    public array  $providerErrors = [];

    public bool   $showInfoModal = false;
    public string $infoMode      = 'description';
    #[Locked]
    public ?array $infoModpack   = null;
    public string $infoError     = '';

    // Install modal state
    public bool    $showModal       = false;
    public bool    $showInstallConfirmation = false;
    #[Locked]
    public ?array  $selectedModpack = null;
    #[Locked]
    public array   $versions        = [];
    public bool    $versionsLoading = false;
    public ?string $selectedVersion = null;
    public bool    $deleteExisting    = false;
    public bool    $createBackup      = true;
    public bool    $deleteWorld       = false;
    public bool    $deleteBackups     = false;
    public array   $selectedBackupIds = [];
    #[Locked]
    public array   $availableBackups  = [];
    #[Locked]
    public array   $detectedWorlds    = [];

    // Installation progress state
    public bool   $isInstalling  = false;
    #[Locked]
    public int    $installId     = 0;
    public int    $progress      = 0;
    #[Locked]
    public array  $steps         = [];
    #[Locked]
    public array  $debugLog      = [];
    public string $installStatus = '';   // 'pending' | 'installing' | 'cancelling' | 'installed' | 'failed'
    public string $installError  = '';
    public ?int   $installStartedAt = null; // epoch ms — for the elapsed timer
    public int    $installElapsed   = 0;    // frozen elapsed seconds when finished

    // Installed modpack info (current)
    #[Locked]
    public ?array  $installedModpack   = null;
    public bool    $updateAvailable    = false;
    public ?string $latestVersionLabel = null;

    // Last install log viewer (browser view)
    public bool  $hasLastLog   = false;   // whether a finished install log exists to show
    public bool  $showLastLog  = false;   // drives the log modal
    #[Locked]
    public array $lastLog      = [];      // the log lines of the most recent finished install
    #[Locked]
    public array $lastLogMeta  = [];      // ['name', 'version', 'status', 'time']

    // Whether the current user may install/update (drives the UI; enforced server-side too).
    #[Locked]
    public bool $canManage        = false;
    #[Locked]
    public bool $canReadBackups   = false;
    #[Locked]
    public bool $canCreateBackups = false;
    #[Locked]
    public bool $canDeleteBackups = false;

    // ─── Authorization ──────────────────────────────────────────────────────────

    /**
     * Hide the page (and its nav entry) unless the server's egg carries one of
     * the configured tags (default "minecraft") and the user has the manage
     * permission.
     */
    public static function canAccess(): bool
    {
        $server = Filament::getTenant();

        return parent::canAccess()
            && $server instanceof Server
            && self::eggHasAllowedTag($server)
            && user()?->can(self::MANAGE_PERMISSION, $server);
    }

    /**
     * True when the server's egg carries one of the configured required tags
     * (case-insensitive). An empty configured list disables the filter, so the
     * page shows on every server.
     */
    private static function eggHasAllowedTag(Server $server): bool
    {
        $required = array_map('strtolower', config('modpack-manager.required_egg_tags', ['minecraft']));

        if (empty($required)) {
            return true;
        }

        foreach ($server->egg?->tags ?? [] as $tag) {
            if (in_array(strtolower(trim((string) $tag)), $required, true)) {
                return true;
            }
        }

        return false;
    }

    private function userCanManage(): bool
    {
        return (bool) user()?->can(self::MANAGE_PERMISSION, $this->getServer());
    }

    /**
     * Server-side guard for every state-changing action. Never trust the UI.
     */
    private function authorizeManage(): void
    {
        abort_unless($this->userCanManage(), 403);
    }

    // ─── Lifecycle ────────────────────────────────────────────────────────────

    public function mount(): void
    {
        $server = $this->getServer();

        $this->canManage        = $this->userCanManage();
        $this->canReadBackups   = (bool) user()?->can(SubuserPermission::BackupRead, $server);
        $this->canCreateBackups = (bool) user()?->can(SubuserPermission::BackupCreate, $server);
        $this->canDeleteBackups = (bool) user()?->can(SubuserPermission::BackupDelete, $server);

        // Is there a finished install whose log we can offer via "Show last install log"?
        $this->hasLastLog = ModpackInstall::where('server_id', $server->id)
            ->whereIn('status', ['installed', 'failed'])
            ->exists();

        // Check for any existing installation record
        $latest = ModpackInstall::where('server_id', $server->id)
            ->orderByDesc('id')
            ->first();

        if ($latest) {
            if (in_array($latest->status, ['installing', 'pending', 'cancelling'], true)) {
                if ($this->isRecordStale($latest)) {
                    // The job never progressed (worker not running / crashed before
                    // it could mark the record failed). Auto-recover instead of
                    // locking the page forever, and show the last good install.
                    $this->failStaleRecord($latest);

                    $previous = ModpackInstall::where('server_id', $server->id)
                        ->where('status', 'installed')
                        ->orderByDesc('id')
                        ->first();

                    if ($previous) {
                        $this->applyInstalledInfo($previous);
                    }
                } else {
                    // Resume watching an ongoing install
                    $this->resumeWatchingInstall($latest);
                }
            } elseif ($latest->status === 'installed') {
                $this->applyInstalledInfo($latest);
            }
        }

        // No usable DB record? When the admin has enabled file-backed metadata,
        // recover the current pack from the server's own .modpack-manager.json so
        // the banner + update tracking survive lost install records.
        if (!$this->installedModpack && !$this->isInstalling && config('modpack-manager.store_metadata', false)) {
            $this->loadInstalledFromMetadata($server);
        }

        // Pre-load popular modpacks (skip if an install is already running)
        if (!$this->isInstalling) {
            $this->loadModpacks();
        }
    }

    // ─── Public properties for view ───────────────────────────────────────────

    public function getPreservedFilesProperty(): array
    {
        return config('modpack-manager.preserved_files', []);
    }

    public function getInstallStepLabels(): array
    {
        return ModpackInstall::STEPS;
    }

    // ─── Filter options ───────────────────────────────────────────────────────

    public function getFilterVersionOptions(): array
    {
        $versions = [];

        try {
            $versions = app(CurseForgeService::class)->getMinecraftVersions();
        } catch (Throwable) {
            // CurseForge may be disabled when no API key is configured.
        }

        if (empty($versions)) {
            try {
                $versions = app(ModrinthService::class)->getMinecraftVersions();
            } catch (Throwable) {
                $versions = [];
            }
        }

        return !empty($versions) ? $versions : [
            '1.21.1', '1.21', '1.20.6', '1.20.4', '1.20.1', '1.20',
            '1.19.2', '1.18.2', '1.16.5', '1.12.2', '1.7.10',
        ];
    }

    public function getFilterLoaderOptions(): array
    {
        return [
            'forge'    => 'Forge',
            'neoforge' => 'NeoForge',
            'fabric'   => 'Fabric',
            'quilt'    => 'Quilt',
        ];
    }

    public function getSortOptions(): array
    {
        return [
            'popular' => 'Popular',
            'updated' => 'Recently updated',
            'name'    => 'Name A–Z',
        ];
    }

    public function loaderFilterAvailable(): bool
    {
        return $this->provider !== 'atlauncher';
    }

    public function categoryFilterAvailable(): bool
    {
        return in_array($this->provider, ['all', 'curseforge', 'modrinth'], true);
    }

    public function getFilterCategoryOptions(): array
    {
        if ($this->provider === 'all') {
            return [
                'adventure'   => 'Adventure / RPG',
                'technology'  => 'Technology',
                'magic'       => 'Magic',
                'quests'      => 'Quests',
                'combat'      => 'Combat',
                'lightweight' => 'Lightweight',
                'hardcore'    => 'Hardcore / Challenging',
            ];
        }

        if ($this->provider === 'modrinth') {
            $categories = app(ModrinthService::class)->getCategories();

            if (!empty($categories)) {
                $options = [];
                foreach ($categories as $category) {
                    $options[$category['slug']] = $category['name'];
                }
                return $options;
            }

            return [
                'adventure' => 'Adventure', 'challenging' => 'Challenging', 'combat' => 'Combat',
                'kitchen-sink' => 'Kitchen Sink', 'lightweight' => 'Lightweight', 'magic' => 'Magic',
                'multiplayer' => 'Multiplayer', 'optimization' => 'Optimization', 'quests' => 'Quests',
                'technology' => 'Technology',
            ];
        }

        $categories = app(CurseForgeService::class)->getCategories();
        if (!empty($categories)) {
            $options = [];
            foreach ($categories as $category) {
                $options[(string) $category['id']] = $category['name'];
            }
            return $options;
        }

        return [
            '4475' => 'Adventure and RPG', '4472' => 'Tech', '4473' => 'Magic',
            '4478' => 'Quests', '4483' => 'Combat / PvP', '4476' => 'Exploration',
            '4477' => 'Skyblock', '4481' => 'Small / Light', '4474' => 'Sci-Fi',
            '4479' => 'Hardcore',
        ];
    }

    private function activeFilters(): array
    {
        $filters = [];

        if ($this->filterVersion !== '') {
            $filters['gameVersion'] = $this->filterVersion;
        }
        if ($this->provider !== 'atlauncher' && !empty($this->filterLoaders)) {
            $filters['loaders'] = array_values($this->filterLoaders);
            if (count($this->filterLoaders) === 1) {
                $filters['loader'] = $this->filterLoaders[0];
            }
        }
        if ($this->categoryFilterAvailable() && $this->filterCategory !== '') {
            $filters['category'] = $this->filterCategory;
        }

        return $filters;
    }

    public function getActiveFilterCount(): int
    {
        return ($this->filterVersion !== '' ? 1 : 0)
            + count($this->filterLoaders)
            + ($this->filterCategory !== '' && $this->categoryFilterAvailable() ? 1 : 0)
            + ($this->filterSort !== 'popular' ? 1 : 0);
    }

    public function getActiveFilterChips(): array
    {
        $chips = [];

        if ($this->filterVersion !== '') {
            $chips[] = ['type' => 'version', 'value' => $this->filterVersion, 'label' => 'MC ' . $this->filterVersion];
        }

        $loaderLabels = $this->getFilterLoaderOptions();
        foreach ($this->filterLoaders as $loader) {
            $chips[] = [
                'type' => 'loader',
                'value' => $loader,
                'label' => $loaderLabels[$loader] ?? ucfirst($loader),
            ];
        }

        if ($this->filterCategory !== '' && $this->categoryFilterAvailable()) {
            $categoryLabels = $this->getFilterCategoryOptions();
            $chips[] = [
                'type' => 'category',
                'value' => $this->filterCategory,
                'label' => $categoryLabels[$this->filterCategory] ?? $this->filterCategory,
            ];
        }

        if ($this->filterSort !== 'popular') {
            $sortLabels = $this->getSortOptions();
            $chips[] = [
                'type' => 'sort',
                'value' => $this->filterSort,
                'label' => $sortLabels[$this->filterSort] ?? $this->filterSort,
            ];
        }

        return $chips;
    }

    public function removeFilterChip(string $type, string $value = ''): void
    {
        if ($type === 'version') {
            $this->filterVersion = '';
        } elseif ($type === 'loader') {
            $this->filterLoaders = array_values(array_filter(
                $this->filterLoaders,
                fn ($loader) => $loader !== strtolower(trim($value))
            ));
        } elseif ($type === 'category') {
            $this->filterCategory = '';
        } elseif ($type === 'sort') {
            $this->filterSort = 'popular';
        } else {
            return;
        }

        $this->resetResults();
        $this->loadModpacks();
    }

    public function toggleLoader(string $loader): void
    {
        $loader = strtolower(trim($loader));
        if (!array_key_exists($loader, $this->getFilterLoaderOptions())) {
            return;
        }

        if (in_array($loader, $this->filterLoaders, true)) {
            $this->filterLoaders = array_values(array_filter($this->filterLoaders, fn ($item) => $item !== $loader));
        } else {
            $this->filterLoaders[] = $loader;
            $this->filterLoaders = array_values(array_unique($this->filterLoaders));
        }
    }

    public function searchModpacks(): void
    {
        $this->resetResults();
        $this->loadModpacks();
    }

    public function applyFilters(): void
    {
        $this->resetResults();
        $this->loadModpacks();
    }

    public function clearFilters(): void
    {
        $this->filterVersion  = '';
        $this->filterLoaders  = [];
        $this->filterCategory = '';
        $this->filterSort     = 'popular';
        $this->resetResults();
        $this->loadModpacks();
    }

    public function setProvider(string $provider): void
    {
        if (!in_array($provider, ['all', 'curseforge', 'modrinth', 'ftb', 'atlauncher'], true)) {
            return;
        }

        $this->provider = $provider;
        $this->filterCategory = '';
        if ($provider === 'atlauncher') {
            $this->filterLoaders = [];
        }
        $this->resetResults();
        $this->loadModpacks();
    }

    public function loadMore(): void
    {
        if ($this->isLoading || $this->isLoadingMore || !$this->hasMore) {
            return;
        }

        $this->page++;
        $this->loadModpacks(true);
    }

    public function openPackInfo(string|int $modpackId, string $provider, string $mode = 'description'): void
    {
        if (!in_array($mode, ['description', 'gallery'], true)) {
            $mode = 'description';
        }

        $this->infoMode = $mode;
        $this->infoError = '';
        $this->infoModpack = collect($this->modpacks)->first(
            fn ($pack) => (string) ($pack['id'] ?? '') === (string) $modpackId
                && ($pack['provider'] ?? null) === $provider
        );
        $this->showInfoModal = true;

        try {
            $details = $this->fetchSingleModpack($modpackId, $provider);
            $this->infoModpack = array_merge($this->infoModpack ?? [], $details);

            if ($provider === 'curseforge') {
                $description = app(CurseForgeService::class)->getDescription((int) $modpackId);
                if ($description !== '') {
                    $this->infoModpack['description'] = $description;
                }
            }
        } catch (Throwable $e) {
            $this->infoError = 'Could not load the full modpack details.';
            Log::info('[ModpackManager] Modpack detail lookup failed', [
                'provider' => $provider,
                'id' => (string) $modpackId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function closePackInfo(): void
    {
        $this->showInfoModal = false;
        $this->infoModpack = null;
        $this->infoError = '';
    }

    public function externalUrl(?string $url): ?string
    {
        return is_string($url) && preg_match('#^https?://#i', $url) ? $url : null;
    }

    /**
     * Open install/update modal for a given modpack.
     */
    public function openModal(string|int $modpackId, ?string $provider = null): void
    {
        $this->authorizeManage();

        // In the combined "all" view, numeric IDs can collide across providers
        // (e.g. CurseForge vs FTB), so match on provider too when it's supplied.
        $modpack = collect($this->modpacks)->first(
            fn ($m) => (string) ($m['id'] ?? '') === (string) $modpackId
                && ($provider === null || ($m['provider'] ?? null) === $provider)
        );

        if (!$modpack) {
            // Fetch from API if not in current list (e.g., installed pack not in search results)
            try {
                $modpack = $this->fetchSingleModpack($modpackId, $provider ?? $this->provider);
            } catch (Throwable $e) {
                Notification::make()->title('Could not load modpack details.')->danger()->send();
                return;
            }
        }

        $this->selectedModpack = $modpack;

        // ATLauncher already returns every published version inside each Pack
        // Object used to build the browser card. Reuse that data immediately
        // instead of making the drawer wait on another provider request.
        $embeddedAtLauncherVersions = (($modpack['provider'] ?? null) === 'atlauncher'
            && isset($modpack['availableVersions'])
            && is_array($modpack['availableVersions']))
                ? array_values($modpack['availableVersions'])
                : [];

        $this->versions = $embeddedAtLauncherVersions;
        $this->selectedVersion = !empty($embeddedAtLauncherVersions)
            ? (string) ($embeddedAtLauncherVersions[0]['id'] ?? '')
            : null;
        $this->versionsLoading = empty($embeddedAtLauncherVersions);
        $isSameInstalledPack = $this->installedModpack
            && (string) ($this->installedModpack['id'] ?? '') === (string) ($modpack['id'] ?? '')
            && ($this->installedModpack['provider'] ?? null) === ($modpack['provider'] ?? null);
        $this->deleteExisting    = !$isSameInstalledPack;
        $this->createBackup      = $this->canCreateBackups;
        $this->deleteWorld       = false;
        $this->deleteBackups     = false;
        $this->selectedBackupIds = [];
        $this->availableBackups  = [];
        $this->detectedWorlds    = [];
        $this->showInstallConfirmation = false;
        $this->showModal         = true;

        // Don't block opening the modal on the version fetch — the modal pops up
        // instantly with its loading state, and the version list is fetched in a
        // follow-up request via wire:init="loadVersions" in the Blade view. The
        // newest version (versions[0]) is auto-selected the moment it lands.
    }

    public function closeModal(): void
    {
        $this->showInstallConfirmation = false;
        $this->showModal         = false;
        $this->selectedModpack   = null;
        $this->versions          = [];
        $this->selectedVersion   = null;
        $this->deleteWorld       = false;
        $this->deleteBackups     = false;
        $this->selectedBackupIds = [];
        $this->availableBackups  = [];
        $this->detectedWorlds    = [];
    }

    /**
     * Load available versions for the selected modpack.
     */
    public function loadVersions(): void
    {
        if (!$this->selectedModpack) {
            return;
        }

        // ATLauncher version data is embedded in the Pack Object returned by
        // the browser search. This avoids a slow/racy second HTTP request when
        // Alpine initialises the drawer. Keep the provider lookup below only as
        // a compatibility fallback for older/stale cards without that field.
        if (($this->selectedModpack['provider'] ?? null) === 'atlauncher'
            && !empty($this->selectedModpack['availableVersions'])
            && is_array($this->selectedModpack['availableVersions'])) {
            $this->versions = array_values($this->selectedModpack['availableVersions']);
            if ($this->selectedVersion === null && !empty($this->versions)) {
                $this->selectedVersion = (string) ($this->versions[0]['id'] ?? '');
            }
            $this->versionsLoading = false;
            return;
        }

        $this->versionsLoading = true;

        try {
            $id = $this->selectedModpack['id'];

            $this->versions = match ($this->selectedModpack['provider']) {
                'curseforge' => app(CurseForgeService::class)->getFiles((int) $id, $this->activeFilters()),
                'modrinth'   => app(ModrinthService::class)->getVersions((string) $id, ['forge', 'fabric', 'quilt', 'neoforge'], $this->activeFilters()),
                'ftb'        => app(FtbService::class)->getVersions((string) $id),
                'atlauncher' => app(ATLauncherService::class)->getVersions((string) $id),
                default      => [],
            };

            if (!empty($this->versions)) {
                $this->selectedVersion = (string) $this->versions[0]['id'];
            }
        } catch (Throwable $e) {
            Notification::make()
                ->title('Could not load versions: ' . $e->getMessage())
                ->warning()
                ->send();
        } finally {
            $this->versionsLoading = false;
        }
    }

    public function reviewInstall(): void
    {
        $this->authorizeManage();

        if (!$this->selectedModpack || !$this->selectedVersion) {
            Notification::make()->title('Please select a version.')->warning()->send();
            return;
        }

        $this->showInstallConfirmation = true;
    }

    public function cancelInstallConfirmation(): void
    {
        $this->showInstallConfirmation = false;
    }

    public function getInstallReview(): array
    {
        if (!$this->selectedModpack || !$this->selectedVersion) {
            return [];
        }

        $provider = (string) ($this->selectedModpack['provider'] ?? '');
        $version = collect($this->versions)->firstWhere('id', $this->selectedVersion);
        $loaders = array_values(array_unique(array_filter(array_map(
            function ($loader) {
                $loader = strtolower((string) $loader);
                return match ($loader) {
                    'neoforge' => 'NeoForge',
                    'forge' => 'Forge',
                    'fabric' => 'Fabric',
                    'quilt' => 'Quilt',
                    default => $loader !== '' ? ucfirst($loader) : null,
                };
            },
            (array) ($version['loaders'] ?? [])
        ))));

        if (empty($loaders)) {
            foreach ((array) ($version['gameVersions'] ?? []) as $candidate) {
                $candidate = strtolower((string) $candidate);
                if (in_array($candidate, ['forge', 'neoforge', 'fabric', 'quilt'], true)) {
                    $loaders[] = $candidate === 'neoforge' ? 'NeoForge' : ucfirst($candidate);
                }
            }
        }

        return [
            'name' => (string) ($this->selectedModpack['name'] ?? 'Modpack'),
            'provider' => match ($provider) {
                'curseforge' => 'CurseForge',
                'modrinth' => 'Modrinth',
                'ftb' => 'FTB',
                'atlauncher' => 'ATLauncher',
                default => ucfirst($provider),
            },
            'version' => $this->getVersionLabel($provider, (string) $this->selectedVersion),
            'loaders' => $loaders,
            'backup' => $this->createBackup,
            'wipeFiles' => $this->deleteExisting,
            'deleteWorld' => $this->deleteWorld,
            'deleteBackups' => $this->deleteBackups,
            'backupCount' => count($this->selectedBackupIds),
        ];
    }

    /**
     * Cancel old install jobs for this server before a replacement is queued.
     * Unreserved database jobs can be removed immediately. A reserved job is
     * already executing inside the queue worker, so request cancellation and wait
     * briefly for the installer to acknowledge it instead of putting another job
     * behind a worker that is still busy.
     */
    private function prepareServerForNewInstall(Server $server): bool
    {
        $runningRecordIds = [];

        foreach ($this->queuedModpackJobs() as $queued) {
            $recordId = $queued['record_id'];
            $record = ModpackInstall::find($recordId);

            if (!$record || $record->server_id !== $server->id) {
                continue;
            }

            if ($queued['reserved_at'] === null) {
                \Illuminate\Support\Facades\DB::table('jobs')->where('id', $queued['job_id'])->delete();

                if (in_array($record->status, ['pending', 'installing', 'cancelling'], true)) {
                    $record->update([
                        'status' => 'failed',
                        'error_message' => 'Superseded by a newer modpack install on this server.',
                    ]);
                    $record->appendLog('Cancelled automatically before the queued installer started.');
                }

                continue;
            }

            if ($record->status !== 'installed') {
                if ($record->status !== 'cancelling') {
                    $record->update([
                        'status' => 'cancelling',
                        'error_message' => 'Stopping this install before starting the replacement modpack.',
                    ]);
                    $record->appendLog('Cancellation requested because a newer modpack install was started on this server.');
                }
                $runningRecordIds[$recordId] = true;
            }
        }

        // Also clean up active records that have no queue row at all. They cannot
        // still be executing through the database queue and should not lock the UI.
        $queuedIds = array_column($this->queuedModpackJobs(), 'record_id');
        ModpackInstall::where('server_id', $server->id)
            ->whereIn('status', ['pending', 'installing', 'cancelling'])
            ->whereNotIn('id', $queuedIds ?: [-1])
            ->get()
            ->each(function (ModpackInstall $record): void {
                $record->update([
                    'status' => 'failed',
                    'error_message' => 'Previous install no longer has an active queue job.',
                ]);
                $record->appendLog('Cleared stale install state before starting another modpack.');
            });

        if (empty($runningRecordIds)) {
            return true;
        }

        // Most cancellation checkpoints are sub-second. Give a running operation a
        // short window to stop; if it is still inside an external request, do not
        // queue the replacement behind it and recreate the zombie-job problem.
        $deadline = microtime(true) + 15.0;
        do {
            usleep(250000);
            $stillRunning = [];

            foreach ($this->queuedModpackJobs() as $queued) {
                if ($queued['reserved_at'] !== null && isset($runningRecordIds[$queued['record_id']])) {
                    $stillRunning[] = $queued['record_id'];
                }
            }

            if (empty($stillRunning)) {
                return true;
            }
        } while (microtime(true) < $deadline);

        Notification::make()
            ->title('Previous modpack install is still stopping')
            ->body('The replacement was not queued, so it cannot get trapped behind the old job. Wait a few seconds and press Install again.')
            ->warning()
            ->send();

        return false;
    }

    /**
     * Return database queue rows that contain this plugin's install job.
     *
     * @return array<int,array{job_id:int,record_id:int,reserved_at:?int}>
     */
    private function queuedModpackJobs(): array
    {
        $result = [];

        foreach (\Illuminate\Support\Facades\DB::table('jobs')->get() as $job) {
            $payload = json_decode((string) $job->payload, true);
            $serialized = $payload['data']['command'] ?? null;

            if (!is_string($serialized) || $serialized === '') {
                continue;
            }

            try {
                $command = @unserialize($serialized, ['allowed_classes' => [InstallModpackJob::class]]);
            } catch (Throwable) {
                continue;
            }

            if (!$command instanceof InstallModpackJob) {
                continue;
            }

            $result[] = [
                'job_id' => (int) $job->id,
                'record_id' => (int) $command->installRecordId,
                'reserved_at' => $job->reserved_at !== null ? (int) $job->reserved_at : null,
            ];
        }

        return $result;
    }

    private function installStillHasQueueJob(int $recordId): bool
    {
        foreach ($this->queuedModpackJobs() as $queued) {
            if ($queued['record_id'] === $recordId) {
                return true;
            }
        }

        return false;
    }

    /**
     * Dispatch the installation job.
     */
    public function startInstall(): void
    {
        $this->authorizeManage();

        if (!$this->selectedModpack || !$this->selectedVersion) {
            Notification::make()->title('Please select a version.')->warning()->send();
            return;
        }

        if (!$this->showInstallConfirmation) {
            $this->reviewInstall();
            return;
        }

        $this->showInstallConfirmation = false;
        $server   = $this->getServer();
        $provider = $this->selectedModpack['provider'];

        if ($this->createBackup) {
            abort_unless(user()?->can(SubuserPermission::BackupCreate, $server), 403);
        }

        if ($this->deleteBackups) {
            abort_unless(
                user()?->can(SubuserPermission::BackupRead, $server)
                    && user()?->can(SubuserPermission::BackupDelete, $server),
                403
            );
        }

        // Build an install "spec" the job uses to resolve the files to install.
        // CurseForge resolves the server pack (or builds from the client pack);
        // Modrinth resolves the .mrpack URL here (versions are already loaded);
        // FTB and ATLauncher only need identifiers — the job pulls their file
        // lists from the provider API.
        if ($provider === 'curseforge') {
            $spec = [
                'provider'         => 'curseforge',
                'mod_id'           => (int) $this->selectedModpack['id'],
                'file_id'          => (int) $this->selectedVersion,
                'preferred_loader' => $this->preferredLoaderForSelectedVersion(),
            ];
        } elseif ($provider === 'modrinth') {
            $version = collect($this->versions)->firstWhere('id', $this->selectedVersion);
            $mrpackUrl = $version ? app(ModrinthService::class)->getPrimaryFileUrl($version['files']) : null;

            if (!$mrpackUrl) {
                Notification::make()->title('No downloadable file found for this version.')->danger()->send();
                return;
            }

            $spec = [
                'provider'   => 'modrinth',
                'project_id' => (string) $this->selectedModpack['id'],
                'version_id' => (string) $this->selectedVersion,
                'mrpack_url' => $mrpackUrl,
            ];
        } elseif ($provider === 'ftb') {
            $spec = [
                'provider'   => 'ftb',
                'pack_id'    => (int) $this->selectedModpack['id'],
                'version_id' => (int) $this->selectedVersion,
            ];
        } else {
            $spec = [
                'provider'  => 'atlauncher',
                'safe_name' => (string) $this->selectedModpack['id'],
                'version'   => (string) $this->selectedVersion,
            ];
        }

        // Determine version label
        $versionLabel = $this->getVersionLabel($provider, $this->selectedVersion);

        // Never put a new install behind a cancelled-but-still-running queue job.
        if (!$this->prepareServerForNewInstall($server)) {
            $this->showInstallConfirmation = true;
            return;
        }

        // Create install record
        $record = ModpackInstall::create([
            'server_id'       => $server->id,
            'provider'        => $provider,
            'modpack_id'      => (string) $this->selectedModpack['id'],
            'modpack_name'    => $this->selectedModpack['name'],
            'modpack_version' => $versionLabel,
            'modpack_icon_url'=> $this->selectedModpack['iconUrl'] ?? null,
            'status'          => 'pending',
            'steps'           => (new ModpackInstall())->buildInitialSteps(),
            'progress'        => 0,
            'debug_log'       => ['[' . now()->format('H:i:s') . '] Install queued; waiting for the Pelican queue worker.'],
        ]);

        // Capture the name before closeModal() nulls out $selectedModpack.
        $modpackName = $this->selectedModpack['name'];
        $installOptions = [
            'delete_existing' => $this->deleteExisting,
            'create_backup'   => $this->createBackup,
            'delete_world'    => $this->deleteWorld,
            'delete_backups'  => $this->deleteBackups,
            'backup_ids'      => array_values(array_unique(array_map('intval', $this->selectedBackupIds))),
        ];

        $this->closeModal();

        $this->installId       = $record->id;
        $this->isInstalling    = true;
        $this->progress        = 0;
        $this->steps           = $record->steps;
        $this->debugLog        = [];
        $this->installStatus   = 'installing';
        $this->installError    = '';
        $this->installStartedAt = (int) ($record->created_at->valueOf());
        $this->installElapsed   = 0;

        InstallModpackJob::dispatch($record->id, $installOptions, $spec)->onQueue('default');

        Notification::make()
            ->title("Installing {$modpackName}…")
            ->info()
            ->send();
    }

    /**
     * Register an already-installed modpack as this server's current install WITHOUT
     * touching any files or dispatching the installer.
     *
     * Use this to restore the installed-pack banner + update tracking when there is no
     * record — e.g. the pack was installed before this plugin existed, was set up
     * manually, or an install record was lost (a plugin re-install on an older version
     * used to drop the records table). Completely non-destructive: it only writes a
     * `status = installed` row from the modpack + version the user picks in the modal.
     */
    public function linkInstalled(): void
    {
        $this->authorizeManage();

        if (!$this->selectedModpack || !$this->selectedVersion) {
            Notification::make()->title('Please select the version you currently have installed.')->warning()->send();
            return;
        }

        $server       = $this->getServer();
        $provider     = $this->selectedModpack['provider'];
        $versionLabel = $this->getVersionLabel($provider, $this->selectedVersion);
        $modpackName  = $this->selectedModpack['name'];

        // Mark every step done so the record reads as a completed install if it's ever
        // rendered in the step view.
        $steps = array_map(
            fn () => ModpackInstall::STEP_DONE,
            (new ModpackInstall())->buildInitialSteps()
        );

        $record = ModpackInstall::create([
            'server_id'        => $server->id,
            'provider'         => $provider,
            'modpack_id'       => (string) $this->selectedModpack['id'],
            'modpack_name'     => $modpackName,
            'modpack_version'  => $versionLabel,
            'modpack_icon_url' => $this->selectedModpack['iconUrl'] ?? null,
            'status'           => 'installed',
            'steps'            => $steps,
            'progress'         => 100,
            'debug_log'        => ['[' . now()->format('H:i:s') . '] Linked as already-installed — no files were changed.'],
        ]);

        // Mirror the link into the server's files when file-backed metadata is on.
        $this->writeInstalledMetadata($server, $record);

        $this->closeModal();

        $this->applyInstalledInfo($record);

        Notification::make()
            ->title("Linked “{$modpackName}” {$versionLabel} as installed.")
            ->body('No files were changed. The installed-pack banner and update checks now track this pack.')
            ->success()
            ->send();
    }

    /**
     * Polled every 2 seconds while installing to update the UI.
     * Called via wire:poll in the Blade view.
     */
    public function pollProgress(): void
    {
        if (!$this->isInstalling || !$this->installId) {
            return;
        }

        $record = $this->findOwnInstall($this->installId);

        if (!$record) {
            return;
        }

        $this->progress      = $record->progress;
        $this->steps         = $record->steps ?? [];
        $this->debugLog      = $record->debug_log ?? [];
        $this->installStatus = $record->status;
        $this->installError  = $record->error_message ?? '';

        if (!$this->installStartedAt && $record->created_at) {
            $this->installStartedAt = (int) ($record->created_at->valueOf());
        }

        if ($record->status === 'failed') {
            $reason = strtolower((string) $record->error_message);
            $wasCancellation = str_contains($reason, 'cancel')
                || str_contains($reason, 'supersed')
                || str_contains($reason, 'stopping');

            if ($wasCancellation) {
                if ($this->installStillHasQueueJob($record->id)) {
                    $this->isInstalling = true;
                    $this->installStatus = 'cancelling';
                    $this->installError = '';
                    return;
                }

                $this->closeInstallConsole();
                return;
            }
        }

        if (in_array($record->status, ['installed', 'failed'], true)) {
            $this->isInstalling = false;
            $this->installElapsed = $record->created_at
                ? (int) $record->created_at->diffInSeconds($record->updated_at ?? now())
                : 0;

            if ($record->status === 'installed') {
                $this->installedModpack = [
                    'provider' => $record->provider,
                    'id'       => $record->modpack_id,
                    'name'     => $record->modpack_name,
                    'version'  => $record->modpack_version,
                    'iconUrl'  => $record->modpack_icon_url,
                ];
            }

            return;
        }

        // Still pending/installing — if it has stopped progressing, the worker is
        // likely dead. Auto-fail so the page doesn't stay stuck.
        if ($this->isRecordStale($record)) {
            $this->failStaleRecord($record);
            $this->isInstalling  = false;
            $this->installStatus = 'failed';
            $this->installError  = $record->refresh()->error_message ?? 'Install stalled.';
        }
    }

    /**
     * Dismiss the progress view. If the underlying record is still pending/installing
     * (e.g. a stuck job), mark it failed so it doesn't re-lock the page on next load.
     */
    public function cancelInstallView(): void
    {
        if (!$this->installId) {
            $this->closeInstallConsole();
            return;
        }

        $record = $this->findOwnInstall($this->installId);
        if (!$record) {
            $this->closeInstallConsole();
            return;
        }

        if (in_array($record->status, ['installed', 'failed'], true)) {
            $this->closeInstallConsole();
            return;
        }

        $reserved = false;
        $foundQueueJob = false;

        foreach ($this->queuedModpackJobs() as $queued) {
            if ($queued['record_id'] !== $record->id) {
                continue;
            }

            $foundQueueJob = true;
            if ($queued['reserved_at'] === null) {
                \Illuminate\Support\Facades\DB::table('jobs')->where('id', $queued['job_id'])->delete();
            } else {
                $reserved = true;
            }
        }

        if (!$reserved) {
            $record->update([
                'status' => 'failed',
                'error_message' => 'Cancelled by user from the panel.',
            ]);
            $record->appendLog($foundQueueJob
                ? 'Cancelled before the queued installer started.'
                : 'Cancelled; no running queue job remained.');
            \Illuminate\Support\Facades\Cache::forget("modpack-manager:install-spec:{$record->id}");
            $this->closeInstallConsole();
            return;
        }

        // The job is already executing. Keep the console locked in a truthful
        // "Cancelling" state until the queue worker acknowledges the request and
        // exits. Unlocking immediately is what previously let a new job get stuck
        // behind the still-running one.
        if ($record->status !== 'cancelling') {
            $record->update([
                'status' => 'cancelling',
                'error_message' => 'Cancellation requested; waiting for the running installer to stop.',
            ]);
            $record->appendLog('Cancellation requested by user; waiting for the queue worker to stop safely.');
        }

        $this->isInstalling = true;
        $this->installStatus = 'cancelling';
        $this->installError = '';
    }

    private function findOwnInstall(int $id): ?ModpackInstall
    {
        return ModpackInstall::where('server_id', $this->getServer()->id)->find($id);
    }

    private function closeInstallConsole(): void
    {
        $this->isInstalling  = false;
        $this->installStatus = '';
        $this->installError  = '';
        $this->installId     = 0;

        $server = $this->getServer();
        $installed = ModpackInstall::where('server_id', $server->id)
            ->where('status', 'installed')
            ->orderByDesc('id')
            ->first();

        if ($installed) {
            $this->applyInstalledInfo($installed);
        } else {
            $this->installedModpack = null;
        }

        if (empty($this->modpacks)) {
            $this->loadModpacks();
        }
    }

    /**
     * Load the most recent finished (installed/failed) install's debug log and
     * open the log modal. Backs the "Show last install log" button.
     */
    public function showLastInstallLog(): void
    {
        $record = ModpackInstall::where('server_id', $this->getServer()->id)
            ->whereIn('status', ['installed', 'failed'])
            ->orderByDesc('id')
            ->first();

        if (!$record) {
            Notification::make()->title('No previous install log found.')->info()->send();
            return;
        }

        $this->lastLog = $record->debug_log ?? [];
        $this->lastLogMeta = [
            'name'    => $record->modpack_name,
            'version' => $record->modpack_version,
            'status'  => $record->status,
            'time'    => $record->updated_at?->diffForHumans(),
        ];
        $this->showLastLog = true;
    }

    public function hideLastInstallLog(): void
    {
        $this->showLastLog = false;
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function getServer(): Server
    {
        /** @var Server $server */
        $server = Filament::getTenant();
        return $server;
    }

    public function loadInstallTargets(): void
    {
        if (!$this->showModal) {
            return;
        }

        $server = $this->getServer();

        $this->availableBackups = $this->canReadBackups
            ? Backup::query()
                ->where('server_id', $server->id)
                ->orderByDesc('created_at')
                ->get()
                ->map(fn (Backup $backup) => [
                    'id'         => $backup->id,
                    'name'       => $backup->name,
                    'bytes'      => $backup->bytes,
                    'createdAt'  => $backup->created_at?->format('M j, Y g:i A') ?? 'Unknown date',
                    'locked'     => $backup->is_locked,
                    'inProgress' => $backup->completed_at === null,
                ])
                ->values()
                ->all()
            : [];

        $this->detectedWorlds = [];

        try {
            $repo = app(DaemonFileRepository::class);
            $repo->setServer($server);
            $properties = (string) $repo->getContent('/server.properties', 1024 * 1024);
            $levelName = $this->extractLevelName($properties);

            if ($levelName === null) {
                return;
            }

            $candidates = [$levelName, $levelName . '_nether', $levelName . '_the_end'];

            foreach ($repo->getDirectory('/') as $entry) {
                $name = (string) ($entry['name'] ?? '');
                $isDirectory = (bool) ($entry['directory'] ?? false);

                if ($isDirectory && in_array($name, $candidates, true)) {
                    $this->detectedWorlds[] = $name;
                }
            }
        } catch (Throwable) {
            $this->detectedWorlds = [];
        }
    }

    private function extractLevelName(string $properties): ?string
    {
        if (!preg_match('/^\s*level-name\s*=\s*(.+?)\s*$/m', $properties, $matches)) {
            return null;
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
            return null;
        }

        return $levelName;
    }

    /**
     * Is this pending/installing record dead (no progress for too long)?
     */
    private function isRecordStale(ModpackInstall $record): bool
    {
        if (!in_array($record->status, ['pending', 'installing'], true)) {
            return false;
        }

        $last = $record->updated_at ?? $record->created_at;

        return $last === null || $last->diffInSeconds(now()) > self::STALE_AFTER_SECONDS;
    }

    /**
     * Mark a stranded record failed and drop its cached install spec.
     */
    private function failStaleRecord(ModpackInstall $record): void
    {
        $record->update([
            'status'        => 'failed',
            'error_message' => $record->error_message
                ?: 'Install stopped making progress — the queue worker may not be running. Marked failed automatically.',
        ]);
        $record->appendLog('Auto-failed: no progress for over ' . (self::STALE_AFTER_SECONDS / 60) . ' minutes.');
        \Illuminate\Support\Facades\Cache::forget("modpack-manager:install-spec:{$record->id}");
    }

    /**
     * Recover the installed-pack banner from the server's on-disk metadata file
     * (written when `store_metadata` is enabled). Builds a transient, unsaved
     * record purely to drive the banner + update check. Silent on any failure
     * (missing file, malformed JSON, daemon unreachable).
     */
    private function loadInstalledFromMetadata(Server $server): void
    {
        try {
            $repo = app(DaemonFileRepository::class);
            $repo->setServer($server);
            $raw = $repo->getContent(ModpackInstall::METADATA_FILE, 64 * 1024);
        } catch (Throwable) {
            return;
        }

        $meta = json_decode((string) $raw, true);

        if (!is_array($meta) || empty($meta['provider']) || empty($meta['modpack_id'])) {
            return;
        }

        $this->applyInstalledInfo(ModpackInstall::fromMetadata($server->id, $meta));
    }

    /**
     * Best-effort write of the on-disk metadata file outside the installer
     * (e.g. when linking an already-installed pack), gated on `store_metadata`.
     */
    private function writeInstalledMetadata(Server $server, ModpackInstall $record): void
    {
        if (!config('modpack-manager.store_metadata', false)) {
            return;
        }

        try {
            $repo = app(DaemonFileRepository::class);
            $repo->setServer($server);
            $repo->putContent(
                ModpackInstall::METADATA_FILE,
                (string) json_encode($record->toMetadata(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            );
        } catch (Throwable $e) {
            Log::warning('[ModpackManager] Could not write metadata file on link', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Populate the "installed pack" banner state from a record.
     */
    private function applyInstalledInfo(ModpackInstall $record): void
    {
        $this->installedModpack = [
            'provider' => $record->provider,
            'id'       => $record->modpack_id,
            'name'     => $record->modpack_name,
            'version'  => $record->modpack_version,
            'iconUrl'  => $record->modpack_icon_url,
        ];

        $this->computeUpdateState($record);
    }

    /**
     * Compare the installed version against the latest available version to
     * decide whether an update is genuinely available.
     */
    private function computeUpdateState(ModpackInstall $record): void
    {
        $latestLabel = app(UpdateCheckService::class)->latestVersionLabel($record);

        $this->latestVersionLabel = $latestLabel;
        $this->updateAvailable = $latestLabel !== null
            && $record->modpack_version !== null
            && trim($latestLabel) !== trim((string) $record->modpack_version);
    }

    private function resetResults(): void
    {
        $this->page = 0;
        $this->hasMore = false;
        $this->modpacks = [];
    }

    private function loadModpacks(bool $append = false): void
    {
        if ($append) {
            $this->isLoadingMore = true;
        } else {
            $this->isLoading = true;
            $this->modpacks = [];
        }

        $this->errorMsg = '';
        if (!$append) {
            $this->providerErrors = [];
        }

        try {
            $filters = $this->activeFilters();

            if ($this->provider === 'all') {
                [$results, $hasMore] = $this->searchAllProviders($this->search, $this->page, filters: $filters);
            } else {
                $results = $this->providerService($this->provider)->search(
                    $this->search,
                    $this->page,
                    $this->pageSize,
                    $filters
                );
                $hasMore = count($results) >= $this->pageSize;
            }

            $results = $this->sortResults($results);

            if ($append) {
                $existing = [];
                foreach ($this->modpacks as $pack) {
                    $existing[($pack['provider'] ?? '') . ':' . ($pack['id'] ?? '')] = $pack;
                }
                foreach ($results as $pack) {
                    $existing[($pack['provider'] ?? '') . ':' . ($pack['id'] ?? '')] = $pack;
                }
                $this->modpacks = array_values($existing);
                $this->modpacks = $this->sortResults($this->modpacks);
            } else {
                $this->modpacks = $results;
            }

            $this->hasMore = $hasMore;
        } catch (Throwable $e) {
            if ($append && $this->page > 0) {
                $this->page--;
            }
            $this->errorMsg = $e->getMessage();
            Log::warning('[ModpackManager] Failed to load modpacks', ['error' => $e->getMessage()]);
        } finally {
            $this->isLoading = false;
            $this->isLoadingMore = false;
        }
    }

    /**
     * @return array{0:array<int,array<string,mixed>>,1:bool}
     */
    private function searchAllProviders(string $query, int $page = 0, int $perProvider = 0, array $filters = []): array
    {
        $providers = array_values(array_filter(
            self::COMBINED_PROVIDERS,
            fn (string $provider) => $this->providerSupportsFilters($provider, $filters)
                && ($provider !== 'curseforge' || CurseForgeService::isConfigured())
        ));

        if (empty($providers)) {
            return [[], false];
        }

        $perProvider = $perProvider > 0
            ? $perProvider
            : max(1, (int) ceil($this->pageSize / count($providers)));

        $buckets = [];
        $hasMore = false;

        foreach ($providers as $provider) {
            try {
                $providerFilters = $this->filtersForProvider($provider, $filters);
                $packs = $this->providerService($provider)->search($query, $page, $perProvider, $providerFilters);
                unset($this->providerErrors[$provider]);
                $buckets[$provider] = $packs;
                $hasMore = $hasMore || count($packs) >= $perProvider;
            } catch (Throwable $e) {
                $this->providerErrors[$provider] = $this->providerLabel($provider) . ' is temporarily unavailable.';
                Log::info("[ModpackManager] '{$provider}' search skipped in combined view", ['error' => $e->getMessage()]);
                $buckets[$provider] = [];
            }
        }

        $merged = [];
        for ($i = 0; $i < $perProvider && count($merged) < $this->pageSize; $i++) {
            foreach ($buckets as $packs) {
                if (isset($packs[$i])) {
                    $merged[] = $packs[$i];
                    if (count($merged) >= $this->pageSize) {
                        break;
                    }
                }
            }
        }

        return [$merged, $hasMore];
    }

    public function retryProviderSearches(): void
    {
        $this->resetResults();
        $this->loadModpacks();
    }

    private function providerLabel(string $provider): string
    {
        return match ($provider) {
            'curseforge' => 'CurseForge',
            'modrinth' => 'Modrinth',
            'ftb' => 'FTB',
            'atlauncher' => 'ATLauncher',
            default => ucfirst($provider),
        };
    }

    private function providerSupportsFilters(string $provider, array $filters): bool
    {
        if (!empty($filters['category']) && !in_array($provider, ['curseforge', 'modrinth'], true)) {
            return false;
        }

        if ((!empty($filters['loaders']) || !empty($filters['loader'])) && $provider === 'atlauncher') {
            return false;
        }

        return true;
    }

    private function filtersForProvider(string $provider, array $filters): array
    {
        if ($provider === 'modrinth' && ($filters['category'] ?? '') === 'hardcore') {
            $filters['category'] = 'challenging';
        }

        return $filters;
    }

    private function sortResults(array $packs): array
    {
        if ($this->filterSort === 'name') {
            usort($packs, fn ($a, $b) => strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')));
        } elseif ($this->filterSort === 'updated') {
            usort($packs, fn ($a, $b) => strtotime((string) ($b['dateModified'] ?? '1970-01-01')) <=> strtotime((string) ($a['dateModified'] ?? '1970-01-01')));
        }

        return $packs;
    }

    private function fetchSingleModpack(string|int $id, ?string $provider = null): array
    {
        $provider ??= $this->provider;

        if ($provider === 'curseforge') {
            return app(CurseForgeService::class)->getMod((int) $id);
        }

        return $this->providerService($provider)->getProject((string) $id);
    }

    /**
     * Resolve the search/getProject service for a provider. CurseForge has a
     * different method shape (getFiles/getMod), so callers that need those still
     * reference it directly; this covers the uniform search()/getProject() calls.
     */
    private function providerService(string $provider): object
    {
        return match ($provider) {
            'modrinth'   => app(ModrinthService::class),
            'ftb'        => app(FtbService::class),
            'atlauncher' => app(ATLauncherService::class),
            default      => app(CurseForgeService::class),
        };
    }

    private function resolveDownloadUrl(array $modpack, string $versionId): string
    {
        if ($modpack['provider'] === 'curseforge') {
            $service = app(CurseForgeService::class);
            return $service->getDownloadUrl((int) $modpack['id'], (int) $versionId);
        }

        // Modrinth: find the file URL within the loaded versions list
        $version = collect($this->versions)->firstWhere('id', $versionId);

        if (!$version) {
            throw new RuntimeException("Version {$versionId} not found in loaded versions list.");
        }

        $url = app(ModrinthService::class)->getPrimaryFileUrl($version['files']);

        if (!$url) {
            throw new RuntimeException('No downloadable file found for this version.');
        }

        return $url;
    }

    private function preferredLoaderForSelectedVersion(): ?string
    {
        $version = collect($this->versions)->firstWhere('id', $this->selectedVersion);
        $candidates = array_merge((array) ($version['loaders'] ?? []), (array) ($version['gameVersions'] ?? []));

        foreach ($candidates as $candidate) {
            $loader = strtolower((string) $candidate);
            if (in_array($loader, ['forge', 'neoforge', 'fabric', 'quilt'], true)) {
                return $loader;
            }
        }

        return count($this->filterLoaders) === 1 ? $this->filterLoaders[0] : null;
    }

    private function getVersionLabel(string $provider, string $versionId): string
    {
        $version = collect($this->versions)->firstWhere('id', $versionId);

        if (!$version) {
            return $versionId;
        }

        // CurseForge/FTB/ATLauncher expose a displayName; Modrinth uses versionNumber/name.
        return $version['displayName']
            ?? $version['versionNumber']
            ?? $version['name']
            ?? $versionId;
    }

    private function resumeWatchingInstall(ModpackInstall $record): void
    {
        $this->installId     = $record->id;
        $this->isInstalling  = true;
        $this->progress      = $record->progress;
        $this->steps         = $record->steps ?? [];
        $this->debugLog      = $record->debug_log ?? [];
        $this->installStatus = $record->status;
        $this->installError  = $record->error_message ?? '';
        $this->installStartedAt = $record->created_at ? (int) ($record->created_at->valueOf()) : null;
    }

    // ─── Navigation helpers ───────────────────────────────────────────────────

    public static function getNavigationLabel(): string
    {
        return 'Modpacks';
    }

    public function getTitle(): string
    {
        return 'Modpack Browser';
    }
}
