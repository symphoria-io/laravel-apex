<?php

namespace Symphoria\Apex\Ipc;

use Symphoria\Apex\Contracts\ApexStore;
use Symphoria\Apex\Support\ApexConfig;

class HeartbeatStore
{
    private ?string $lastState = null;

    private float $lastWriteAt = 0.0;

    public function __construct(
        private readonly ApexConfig $config,
        private readonly ApexStore $store,
    ) {}

    /**
     * A worker calls this on every job start and finish. At 50 jobs a second
     * that is 150 writes a second per worker, all of them saying the same
     * thing. So: a state change always lands, a repeat of the same state at
     * most once per interval. The throttle window stays far below the
     * heartbeat TTL, so a throttled worker is never mistaken for a dead one.
     */
    public function write(string $apexId, array $payload, bool $force = false): void
    {
        $state = isset($payload['state']) && is_string($payload['state']) ? $payload['state'] : null;

        if (! $force && $state !== null && $state === $this->lastState && ! $this->intervalElapsed()) {
            return;
        }

        $this->lastState = $state;
        $this->lastWriteAt = microtime(true);

        $this->store->put(
            $this->key($apexId),
            (string) json_encode($payload + ['updated_at' => time()], JSON_UNESCAPED_SLASHES),
            (int) ($this->config->master()['worker_heartbeat_ttl_seconds'] ?? 30),
        );
    }

    public function read(string $apexId): ?array
    {
        return $this->decode($this->store->get($this->key($apexId)));
    }

    public function delete(string $apexId): void
    {
        $this->store->forget($this->key($apexId));
    }

    /**
     * Read many heartbeats in one round trip. Preferred over `all()` whenever
     * the caller already knows which workers exist (the master does, via its
     * registry) because it avoids a scan of the whole keyspace.
     *
     * @param  array<int, string>  $apexIds
     * @return array<string, array<string, mixed>>
     */
    public function many(array $apexIds): array
    {
        $apexIds = array_values(array_unique($apexIds));

        if ($apexIds === []) {
            return [];
        }

        $values = $this->store->many(array_map($this->key(...), $apexIds));

        $out = [];
        foreach ($apexIds as $apexId) {
            $decoded = $this->decode($values[$this->key($apexId)] ?? null);

            if ($decoded === null) {
                continue;
            }

            $decoded['apex_id'] = $apexId;
            $out[$apexId] = $decoded;
        }

        return $out;
    }

    public function all(): array
    {
        $prefix = $this->config->storeKey('workers_prefix');
        $keys = $this->store->keys($prefix);

        if ($keys === []) {
            return [];
        }

        $out = [];

        foreach ($this->store->many($keys) as $key => $value) {
            $decoded = $this->decode($value);

            if ($decoded === null) {
                continue;
            }

            $apexId = substr($key, strlen($prefix));
            $decoded['apex_id'] = $apexId;
            $out[$apexId] = $decoded;
        }

        return $out;
    }

    private function intervalElapsed(): bool
    {
        $interval = (int) ($this->config->master()['heartbeat_min_write_interval_ms'] ?? 1000);

        return (microtime(true) - $this->lastWriteAt) * 1000 >= $interval;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decode(?string $raw): ?array
    {
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function key(string $apexId): string
    {
        return $this->config->storeKey('workers_prefix').$apexId;
    }
}
