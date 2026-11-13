<?php

namespace Cosmii02\ModpackManager\Services;

use Cosmii02\ModpackManager\Models\ModpackInstall;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Checks installed modpacks against their provider for newer versions and
 * notifies the server owner when an update first appears.
 *
 * The "latest version" logic mirrors the live check in ModpackBrowserPage so
 * the scheduled result and the in-panel banner agree.
 */
class UpdateCheckService
{
    /**
     * Check every server's most-recent installed modpack.
     *
     * @return array{checked:int, updates:int, notified:int, errors:int}
     */
    public function checkAll(bool $notify = true): array
    {
        $stats = ['checked' => 0, 'updates' => 0, 'notified' => 0, 'errors' => 0];

        // One record per server: the newest successful install.
        $records = ModpackInstall::query()
            ->where('status', 'installed')
            ->orderByDesc('id')
            ->get()
            ->unique('server_id');

        foreach ($records as $record) {
            try {
                $result = $this->check($record, $notify);
                $stats['checked']++;
                if ($result['update_available']) {
                    $stats['updates']++;
                }
                if ($result['notified']) {
                    $stats['notified']++;
                }
            } catch (Throwable $e) {
                $stats['errors']++;
                Log::warning('[ModpackManager] Update check failed for install #' . $record->id, [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $stats;
    }

    /**
     * Check a single record, persist the result, and notify once per new version.
     *
     * @return array{update_available:bool, latest:?string, notified:bool}
     */
    public function check(ModpackInstall $record, bool $notify = true): array
    {
        $latest = $this->latestVersionLabel($record);

        $available = $latest !== null
            && $record->modpack_version !== null
            && trim($latest) !== trim((string) $record->modpack_version);

        $record->update([
            'latest_version'    => $latest,
            'update_available'  => $available,
            'update_checked_at' => now(),
        ]);

        $notified = false;

        // Notify only when there's an update AND we haven't already told the
        // owner about *this* version.
        if ($available && $notify && $record->update_notified_version !== $latest) {
            $notified = $this->notifyOwner($record, $latest);
            if ($notified) {
                $record->update(['update_notified_version' => $latest]);
            }
        }

        return ['update_available' => $available, 'latest' => $latest, 'notified' => $notified];
    }

    /**
     * The newest version label the installed pack can move to: same Minecraft version
     * and loader as the installed version, and no beta/alpha unless the installed
     * version is one. Returns the installed label when nothing newer qualifies.
     */
    public function latestVersionLabel(ModpackInstall $record): ?string
    {
        try {
            $versions = match ($record->provider) {
                'curseforge' => app(CurseForgeService::class)->getFiles((int) $record->modpack_id),
                'ftb'        => app(FtbService::class)->getVersions((string) $record->modpack_id),
                'atlauncher' => app(ATLauncherService::class)->getVersions((string) $record->modpack_id),
                default      => app(ModrinthService::class)->getVersions((string) $record->modpack_id),
            };
        } catch (Throwable) {
            // Be conservative: a provider hiccup must not produce a false "update".
            return null;
        }

        return $this->pickLatest(array_values($versions), trim((string) $record->modpack_version));
    }

    /** @param array<int, array<string, mixed>> $versions newest first */
    private function pickLatest(array $versions, string $installedLabel): ?string
    {
        $installedIndex = null;
        foreach ($versions as $i => $v) {
            if ($installedLabel !== '' && $this->label($v) === $installedLabel) {
                $installedIndex = $i;
                break;
            }
        }

        if ($installedIndex === null) {
            foreach ($versions as $v) {
                if (empty($v['prerelease'])) {
                    return $this->label($v);
                }
            }
            return isset($versions[0]) ? $this->label($versions[0]) : null;
        }

        $installed = $versions[$installedIndex];
        $mc = $this->mcVersions($installed);
        $loaders = $this->loaders($installed);
        $allowPrerelease = !empty($installed['prerelease']);

        // Only versions listed before the installed one are newer.
        for ($i = 0; $i < $installedIndex; $i++) {
            $v = $versions[$i];

            if (!$allowPrerelease && !empty($v['prerelease'])) {
                continue;
            }
            if (!empty($mc) && empty(array_intersect($mc, $this->mcVersions($v)))) {
                continue;
            }
            $candidateLoaders = $this->loaders($v);
            if (!empty($loaders) && !empty($candidateLoaders) && empty(array_intersect($loaders, $candidateLoaders))) {
                continue;
            }

            return $this->label($v);
        }

        return $installedLabel;
    }

    private function label(array $v): string
    {
        return trim((string) ($v['displayName'] ?? $v['versionNumber'] ?? $v['name'] ?? ''));
    }

    /** @return string[] plain Minecraft versions; CurseForge mixes loader names into gameVersions */
    private function mcVersions(array $v): array
    {
        return array_values(array_filter(
            array_map(fn ($g) => trim((string) $g), (array) ($v['gameVersions'] ?? [])),
            fn (string $g) => preg_match('/^\d+\.\d+(?:\.\d+)?$/', $g) === 1
        ));
    }

    /** @return string[] */
    private function loaders(array $v): array
    {
        $known = ['forge', 'neoforge', 'fabric', 'quilt'];
        $tags = array_merge((array) ($v['loaders'] ?? []), (array) ($v['gameVersions'] ?? []));

        return array_values(array_unique(array_filter(
            array_map(fn ($t) => strtolower(trim((string) $t)), $tags),
            fn (string $t) => in_array($t, $known, true)
        )));
    }

    /**
     * Send a Filament database notification to the server owner.
     */
    private function notifyOwner(ModpackInstall $record, string $latest): bool
    {
        $owner = $record->server?->owner;

        if (!$owner) {
            return false;
        }

        try {
            Notification::make()
                ->title('Modpack update available')
                ->icon('tabler-package')
                ->body(sprintf(
                    '%s has a new version available: %s (you have %s).',
                    $record->modpack_name,
                    $latest,
                    $record->modpack_version ?: 'unknown'
                ))
                ->success()
                ->sendToDatabase($owner, isEventDispatched: true);

            $record->appendLog("Update available: {$latest} — notified owner.");

            return true;
        } catch (Throwable $e) {
            Log::warning('[ModpackManager] Could not send update notification for install #' . $record->id, [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
