<?php

namespace Cosmii02\ModpackManager\Jobs;

use Cosmii02\ModpackManager\Models\ModpackInstall;
use Cosmii02\ModpackManager\Services\ModpackInstallService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class InstallModpackJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600; // 60 min – includes backup, download, loader install, and verification
    public bool $failOnTimeout = true;

    // The queue's retry_after (90s by default) is far shorter than an install, so with
    // several workers the job is handed out again while it is still running. Unlimited
    // tries let that duplicate reservation reach handle() instead of failing the record;
    // a real exception still fails the job after one attempt.
    public int $tries = 0;
    public int $maxExceptions = 1;

    /** Matches ModpackBrowserPage's stale-install threshold. */
    private const STALE_AFTER_SECONDS = 1200;

    public array $spec = [];

    public function __construct(
        public readonly int   $installRecordId,
        public readonly array $options = [],
        array $spec = []
    ) {
        $this->spec = $spec;
    }

    public function handle(ModpackInstallService $installService): void
    {
        $record = ModpackInstall::find($this->installRecordId);

        if (!$record) {
            Log::warning("[ModpackManager] InstallModpackJob: record #{$this->installRecordId} not found, skipping.");
            return;
        }

        if (in_array($record->status, ['installing', 'cancelling'], true)) {
            $lastActivity = $record->updated_at ?? $record->created_at;

            if ($lastActivity !== null && $lastActivity->diffInSeconds(now()) <= self::STALE_AFTER_SECONDS) {
                // Another worker is still running this install. Put the row back instead of
                // finishing here: the Modpacks page reads the jobs table to tell a running
                // install from an abandoned one and to cancel it.
                $this->release(60);
                return;
            }

            $record->update([
                'status'        => 'failed',
                'error_message' => 'The queue worker stopped during the install. Marked failed automatically.',
            ]);
            $record->appendLog('Auto-failed: the queue worker running this install stopped.');
            return;
        }

        if ($record->status !== 'pending') {
            Log::info("[ModpackManager] InstallModpackJob: record #{$this->installRecordId} is {$record->status}, not pending; skipping queued job.");
            return;
        }

        $record->update(['status' => 'installing']);
        $record->appendLog('Job started on queue worker.');

        $installService->install($record, $this->options, $this->spec);
    }

    public function failed(Throwable $e): void
    {
        $record = ModpackInstall::find($this->installRecordId);

        if ($record) {
            $record->update([
                'status'        => 'failed',
                'error_message' => $e->getMessage(),
            ]);
            $record->appendLog('JOB FAILED: ' . $e->getMessage());
        }

        Log::error("[ModpackManager] InstallModpackJob failed for record #{$this->installRecordId}", [
            'error' => $e->getMessage(),
        ]);
    }
}
