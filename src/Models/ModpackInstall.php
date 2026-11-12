<?php

namespace Cosmii02\ModpackManager\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Server;

/**
 * Tracks modpack installations per server.
 *
 * @property int         $id
 * @property int         $server_id
 * @property string      $provider         'curseforge' | 'modrinth'
 * @property string      $modpack_id       External modpack ID
 * @property string      $modpack_name
 * @property string|null $modpack_version
 * @property string|null $modpack_icon_url
 * @property string      $status           'pending' | 'installing' | 'installed' | 'failed'
 * @property array       $steps            Step-by-step progress array
 * @property int         $progress         0–100
 * @property array       $debug_log        Array of debug messages
 * @property string|null $error_message
 * @property string|null $latest_version           Latest version label seen by the update checker
 * @property bool        $update_available         Whether a newer version is available
 * @property \Illuminate\Support\Carbon|null $update_checked_at  When updates were last checked
 * @property string|null $update_notified_version  Version we last notified about
 */
class ModpackInstall extends Model
{
    protected $table = 'modpack_installs';

    protected $fillable = [
        'server_id',
        'provider',
        'modpack_id',
        'modpack_name',
        'modpack_version',
        'modpack_icon_url',
        'status',
        'steps',
        'progress',
        'debug_log',
        'error_message',
        'latest_version',
        'update_available',
        'update_checked_at',
        'update_notified_version',
        'pack_mods',
    ];

    protected $casts = [
        'steps'             => 'array',
        'debug_log'         => 'array',
        'progress'          => 'integer',
        'update_available'  => 'boolean',
        'update_checked_at' => 'datetime',
        'pack_mods'         => 'array',
    ];

    /**
     * Path (relative to the server root) of the optional metadata file written
     * into each server's own files when `store_metadata` is enabled. Lets the
     * Modpacks page recover the current pack when no DB record exists.
     */
    public const METADATA_FILE = '/.modpack-manager.json';

    /**
     * Build the JSON payload describing this install for the on-disk metadata file.
     *
     * @return array<string, mixed>
     */
    public function toMetadata(): array
    {
        return [
            'managed_by'   => 'modpack-manager',
            'provider'     => $this->provider,
            'modpack_id'   => $this->modpack_id,
            'name'         => $this->modpack_name,
            'version'      => $this->modpack_version,
            'icon_url'     => $this->modpack_icon_url,
            'installed_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Build a transient (unsaved) install record from a decoded metadata payload,
     * for driving the installed-pack banner + update check when there is no DB row.
     */
    public static function fromMetadata(int $serverId, array $meta): self
    {
        return new self([
            'server_id'        => $serverId,
            'provider'         => (string) ($meta['provider'] ?? ''),
            'modpack_id'       => (string) ($meta['modpack_id'] ?? ''),
            'modpack_name'     => (string) ($meta['name'] ?? 'Installed modpack'),
            'modpack_version'  => $meta['version'] ?? null,
            'modpack_icon_url' => $meta['icon_url'] ?? null,
            'status'           => 'installed',
        ]);
    }

    // ─── Step definitions ─────────────────────────────────────────────────────

    public const STEPS = [
        'save_config'       => 'Save Server Settings',
        'create_backup'     => 'Create Backup',
        'delete_files'      => 'Delete Old Files',
        'download'          => 'Download Modpack',
        'extract'           => 'Extract Files',
        'merge_configs'     => 'Assemble Modpack',
        'restore_config'    => 'Restore Server Settings',
        'finalize'          => 'Finalize Installation',
        'configure_loader'  => 'Configure Startup / Loader',
    ];

    // Step statuses
    public const STEP_PENDING    = 'pending';
    public const STEP_RUNNING    = 'running';
    public const STEP_DONE       = 'done';
    public const STEP_FAILED     = 'failed';

    // ─── Helpers ──────────────────────────────────────────────────────────────

    public function buildInitialSteps(): array
    {
        $steps = [];
        foreach (array_keys(self::STEPS) as $key) {
            $steps[$key] = self::STEP_PENDING;
        }
        return $steps;
    }

    public function markStepRunning(string $step): void
    {
        $steps = $this->steps ?? $this->buildInitialSteps();
        $steps[$step] = self::STEP_RUNNING;
        $this->update(['steps' => $steps]);
    }

    public function markStepDone(string $step): void
    {
        $steps = $this->steps ?? $this->buildInitialSteps();
        $steps[$step] = self::STEP_DONE;
        $this->update(['steps' => $steps]);
    }

    public function markStepFailed(string $step): void
    {
        $steps = $this->steps ?? $this->buildInitialSteps();
        $steps[$step] = self::STEP_FAILED;
        $this->update(['steps' => $steps]);
    }

    public function appendLog(string $message): void
    {
        $log = $this->debug_log ?? [];
        $log[] = '[' . now()->format('H:i:s') . '] ' . $message;
        $this->update(['debug_log' => $log]);
    }

    // ─── Relationships ────────────────────────────────────────────────────────

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
