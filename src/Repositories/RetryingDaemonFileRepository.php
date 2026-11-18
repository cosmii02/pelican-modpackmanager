<?php

namespace Cosmii02\ModpackManager\Repositories;

use App\Repositories\Daemon\DaemonFileRepository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Throwable;

/**
 * Retries Wings calls that failed before the request was sent (DNS lookup, TCP connect,
 * TLS handshake), so retrying is safe even for renames and deletes. An install makes many
 * daemon calls, each with its own DNS lookup, and one resolver hiccup must not fail the job.
 */
class RetryingDaemonFileRepository extends DaemonFileRepository
{
    private const RETRY_DELAYS_MS = [500, 2000, 5000];

    public function getHttpClient(array $headers = []): PendingRequest
    {
        return parent::getHttpClient($headers)->retry(
            self::RETRY_DELAYS_MS,
            when: fn (Throwable $e) => self::failedBeforeSending($e),
        );
    }

    public static function failedBeforeSending(Throwable $e): bool
    {
        return $e instanceof ConnectionException
            && preg_match('/cURL error (6|7|35):|cURL error 28: (Resolving|Connection) timed out/i', $e->getMessage()) === 1;
    }
}
