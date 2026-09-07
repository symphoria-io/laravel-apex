<?php

namespace Symphoria\Apex\Services\Bench\Scenarios;

use Symphoria\Apex\Services\Bench\BenchSample;

/**
 * Shared helpers for scenarios that dispatch a job and poll an external completion
 * marker (DB row status, redis key, etc.) instead of using BenchResultRecorder.
 *
 * Such scenarios cannot break dispatch/wait/run apart precisely; total_ms is the
 * dispatch-to-completion-observed-in-poll duration. wait_ms and run_ms are null.
 */
abstract class AbstractPollScenario implements BenchScenario
{
    /**
     * Wait until $isDone() returns true or the timeout elapses.
     *
     * @return array{done:bool,elapsed_ms:float,error:?string}
     */
    protected function pollUntil(callable $isDone, int $timeoutMs, int $intervalMs = 100, ?callable $errorMessage = null): array
    {
        $start = microtime(true);
        $deadline = $start + ($timeoutMs / 1000);

        while (microtime(true) < $deadline) {
            try {
                $done = (bool) $isDone();
            } catch (\Throwable $e) {
                return [
                    'done' => false,
                    'elapsed_ms' => round((microtime(true) - $start) * 1000, 3),
                    'error' => $e->getMessage(),
                ];
            }

            if ($done) {
                return [
                    'done' => true,
                    'elapsed_ms' => round((microtime(true) - $start) * 1000, 3),
                    'error' => $errorMessage ? $errorMessage() : null,
                ];
            }

            usleep($intervalMs * 1000);
        }

        return [
            'done' => false,
            'elapsed_ms' => round((microtime(true) - $start) * 1000, 3),
            'error' => 'Polling timed out after '.$timeoutMs.' ms',
        ];
    }

    protected function buildSample(int $sequence, float $dispatchStartedAt, float $dispatchedAt, array $pollResult, array $extra = []): BenchSample
    {
        $now = microtime(true);

        return new BenchSample(
            sequence: $sequence,
            status: $pollResult['done'] ? 'completed' : 'timeout',
            dispatchMs: round(($dispatchedAt - $dispatchStartedAt) * 1000, 3),
            waitMs: null,
            runMs: null,
            totalMs: round(($now - $dispatchStartedAt) * 1000, 3),
            memoryMb: round(memory_get_peak_usage(true) / 1024 / 1024, 1),
            error: $pollResult['done'] ? null : $pollResult['error'],
            extra: $extra,
        );
    }
}
