<?php

namespace Symphoria\Apex\Services\Bench;

use Symphoria\Apex\Contracts\ApexStore;

/**
 * Cross-mode timing store. Jobs (regardless of worker mode) write their
 * started_at/finished_at/memory under a deterministic store key derived from
 * runId + sequence. The dispatcher polls the same key to await completion.
 */
class BenchResultRecorder
{
    private const TTL = 600;

    private const KEY_PREFIX = 'apex:bench:job:';

    public function __construct(
        private readonly ApexStore $store,
    ) {}

    public function recordStart(string $runId, int $sequence, float $startedAt): void
    {
        $this->merge($runId, $sequence, [
            'started_at' => $startedAt,
            'pid' => getmypid(),
        ]);
    }

    public function recordFinish(string $runId, int $sequence, float $finishedAt, string $status, ?string $error = null, array $extra = []): void
    {
        $this->merge($runId, $sequence, array_filter([
            'finished_at' => $finishedAt,
            'status' => $status,
            'error' => $error,
            'memory_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 1),
            'extra' => $extra ?: null,
        ], fn ($v) => $v !== null));
    }

    public function read(string $runId, int $sequence): ?array
    {
        $raw = $this->store->get($this->key($runId, $sequence));

        if (! is_string($raw)) {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Poll the key until status is set or timeout (ms) elapses.
     * Returns the final payload or null on timeout.
     */
    public function await(string $runId, int $sequence, int $timeoutMs, int $pollIntervalMs = 50): ?array
    {
        $deadline = microtime(true) + ($timeoutMs / 1000);

        while (microtime(true) < $deadline) {
            $payload = $this->read($runId, $sequence);

            if (is_array($payload) && isset($payload['status'])) {
                return $payload;
            }

            usleep($pollIntervalMs * 1000);
        }

        return $this->read($runId, $sequence);
    }

    public function forget(string $runId, int $sequence): void
    {
        $this->store->forget($this->key($runId, $sequence));
    }

    public function forgetRun(string $runId, int $maxSequence): void
    {
        for ($seq = 0; $seq <= $maxSequence; $seq++) {
            $this->store->forget($this->key($runId, $seq));
        }
    }

    private function merge(string $runId, int $sequence, array $patch): void
    {
        $existing = $this->read($runId, $sequence) ?? [];
        $merged = array_merge($existing, $patch);

        $this->store->put(
            $this->key($runId, $sequence),
            (string) json_encode($merged, JSON_UNESCAPED_SLASHES),
            self::TTL,
        );
    }

    private function key(string $runId, int|string $sequence): string
    {
        return self::KEY_PREFIX.$runId.':'.$sequence;
    }
}
