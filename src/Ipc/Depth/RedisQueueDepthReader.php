<?php

namespace Symphoria\Apex\Ipc\Depth;

use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Redis;
use Symphoria\Apex\Contracts\QueueDepthProbe;
use Symphoria\Apex\Support\ApexConfig;

final class RedisQueueDepthReader implements QueueDepthProbe
{
    /** @var array<string, int> */
    private array $depthCache = [];

    /** @var array<string, int|null> */
    private array $oldestCache = [];

    public function __construct(
        private readonly ApexConfig $config,
    ) {}

    /**
     * Drop the per-tick memoization. The master calls this once at the start
     * of every tick; without it the same queue is re-read 3-4 times per tick
     * (scaleQueue, countFlexIdleByQueue, writeSnapshot).
     */
    public function resetCache(): void
    {
        $this->depthCache = [];
        $this->oldestCache = [];
    }

    public function depth(array $queueNames): int
    {
        $cacheKey = $this->cacheKey($queueNames);

        if (array_key_exists($cacheKey, $this->depthCache)) {
            return $this->depthCache[$cacheKey];
        }

        if ($queueNames === []) {
            return $this->depthCache[$cacheKey] = 0;
        }

        $now = time();

        $results = $this->redis()->pipeline(function ($pipe) use ($queueNames, $now) {
            foreach ($queueNames as $name) {
                $key = $this->queueKey($name);

                $pipe->llen($key);
                $pipe->zcount($key.':delayed', '-inf', (string) $now);
                $pipe->zcount($key.':reserved', '-inf', (string) $now);
            }
        });

        $total = 0;
        foreach ((array) $results as $value) {
            $total += (int) $value;
        }

        return $this->depthCache[$cacheKey] = $total;
    }

    /**
     * Warm the depth cache for many queue groups in one round trip. The master
     * calls this once per tick so the 12+ per-queue `depth()` lookups that
     * follow are answered from memory.
     *
     * @param  array<int, array<int, string>>  $groups
     */
    public function prefetch(array $groups): void
    {
        $pending = [];

        foreach ($groups as $group) {
            $group = array_values(array_filter($group, static fn ($q) => is_string($q) && $q !== ''));

            if ($group === []) {
                continue;
            }

            $cacheKey = $this->cacheKey($group);

            if (array_key_exists($cacheKey, $this->depthCache) || isset($pending[$cacheKey])) {
                continue;
            }

            $pending[$cacheKey] = $group;
        }

        if ($pending === []) {
            return;
        }

        $now = time();

        $results = array_values((array) $this->redis()->pipeline(function ($pipe) use ($pending, $now) {
            foreach ($pending as $group) {
                foreach ($group as $name) {
                    $key = $this->queueKey($name);

                    $pipe->llen($key);
                    $pipe->zcount($key.':delayed', '-inf', (string) $now);
                    $pipe->zcount($key.':reserved', '-inf', (string) $now);
                }
            }
        }));

        $offset = 0;
        foreach ($pending as $cacheKey => $group) {
            $length = count($group) * 3;
            $total = 0;

            for ($i = 0; $i < $length; $i++) {
                $total += (int) ($results[$offset + $i] ?? 0);
            }

            $offset += $length;
            $this->depthCache[$cacheKey] = $total;
        }
    }

    public function oldestWaitSeconds(array $queueNames): ?int
    {
        $cacheKey = $this->cacheKey($queueNames);

        if (array_key_exists($cacheKey, $this->oldestCache)) {
            return $this->oldestCache[$cacheKey];
        }

        // An empty queue has no oldest job. Skipping the read here is the
        // single largest saving in the master loop: without it every idle
        // queue costs an LINDEX + 2 ZRANGEBYSCORE per sub-queue per tick.
        if ($this->depth($queueNames) === 0) {
            return $this->oldestCache[$cacheKey] = null;
        }

        $now = time();

        $results = $this->redis()->pipeline(function ($pipe) use ($queueNames, $now) {
            foreach ($queueNames as $name) {
                $key = $this->queueKey($name);

                $pipe->lindex($key, -1);
                $pipe->zrangebyscore($key.':delayed', '-inf', (string) $now, ['LIMIT' => [0, 1], 'WITHSCORES' => true]);
                $pipe->zrangebyscore($key.':reserved', '-inf', (string) $now, ['LIMIT' => [0, 1], 'WITHSCORES' => true]);
            }
        });

        $results = array_values((array) $results);
        $oldest = null;

        foreach (array_values($queueNames) as $index => $_) {
            $offset = $index * 3;

            $raw = $results[$offset] ?? null;
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);

                if (is_array($decoded) && isset($decoded['pushedAt'])) {
                    $age = $now - (int) $decoded['pushedAt'];

                    if ($oldest === null || $age > $oldest) {
                        $oldest = $age;
                    }
                }
            }

            foreach ([$results[$offset + 1] ?? null, $results[$offset + 2] ?? null] as $entry) {
                if (! is_array($entry) || $entry === []) {
                    continue;
                }

                $age = $now - (int) reset($entry);

                if ($age >= 0 && ($oldest === null || $age > $oldest)) {
                    $oldest = $age;
                }
            }
        }

        return $this->oldestCache[$cacheKey] = $oldest;
    }

    /**
     * @param  array<int, string>  $queueNames
     */
    private function cacheKey(array $queueNames): string
    {
        return implode("\0", $queueNames);
    }

    private function queueKey(string $name): string
    {
        $queue = $this->queueConfig();
        $base = (string) ($queue['prefix'] ?? 'queues:');

        // Laravel maps the 'default' queue name onto whatever the connection
        // calls its default queue, so reading key 'queues:default' would miss
        // the backlog on any connection that renamed it.
        if ($name === 'default') {
            return $base.(string) ($queue['queue'] ?? 'default');
        }

        return $base.$name;
    }

    /**
     * @return array<string, mixed>
     */
    private function queueConfig(): array
    {
        return $this->config->queueConnectionConfig();
    }

    private function redis(): Connection
    {
        // The queue's Redis connection, not Apex's: the jobs live wherever
        // the queue was configured to put them.
        $connection = $this->queueConfig()['connection'] ?? 'default';

        return Redis::connection(is_string($connection) ? $connection : 'default');
    }
}
