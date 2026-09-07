<?php

namespace Symphoria\Apex\Support;

use InvalidArgumentException;

class ApexConfig
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public static function fromConfig(): self
    {
        return new self(config('apex', []));
    }

    /**
     * Settings left null fall back to the default for the active store, so
     * an application on the database store gets a sane cadence without
     * configuring anything.
     */
    public function master(): array
    {
        $master = $this->config['master'] ?? [];
        $defaults = $this->config['stores'][$this->store()] ?? [];

        foreach ($defaults as $key => $value) {
            if (! isset($master[$key]) || $master[$key] === '') {
                $master[$key] = $value;
            }
        }

        return $master;
    }

    /**
     * Where Apex keeps its coordination state.
     *
     * Derived from the queue driver when not set explicitly: only a redis
     * queue implies a Redis worth using. Deriving from the connection *name*
     * would be wrong — a connection called 'redis' backed by the sync driver
     * is entirely legal.
     */
    public function store(): string
    {
        $store = $this->config['store'] ?? null;

        if (is_string($store) && $store !== '') {
            if (! isset($this->config['stores'][$store])) {
                throw new InvalidArgumentException(
                    "Unknown Apex store [{$store}]. Configured stores: "
                    .implode(', ', array_keys($this->config['stores'] ?? [])).'.'
                );
            }

            return $store;
        }

        $default = config('queue.default');
        $driver = is_string($default) ? config("queue.connections.{$default}.driver") : null;

        return $driver === 'redis' ? 'redis' : 'database';
    }

    /**
     * The application's default queue connection config. Apex reads the queue
     * backend directly, so it needs the raw settings rather than a queue
     * instance.
     *
     * @return array<string, mixed>
     */
    public function queueConnectionConfig(): array
    {
        $name = config('queue.default');

        return is_string($name) ? (array) config("queue.connections.{$name}", []) : [];
    }

    public function databaseConnection(): ?string
    {
        $connection = $this->config['database_connection'] ?? null;

        return is_string($connection) && $connection !== '' ? $connection : null;
    }

    /**
     * Whether job pickup can use Redis-specific tricks such as BLMPOP. A
     * separate question from the store: an application can run its queue on
     * Redis while keeping Apex state in the database, or the other way round.
     */
    public function queueDriverIsRedis(): bool
    {
        $default = config('queue.default');

        return is_string($default) && config("queue.connections.{$default}.driver") === 'redis';
    }

    public function activity(): array
    {
        return $this->config['activity'] ?? [];
    }

    public function recentJobs(): array
    {
        return $this->config['recent_jobs'] ?? [];
    }

    public function workerEvents(): array
    {
        return $this->config['worker_events'] ?? [];
    }

    public function burst(): array
    {
        return $this->config['burst'] ?? [];
    }

    public function connection(): string
    {
        return $this->config['connection'] ?? 'default';
    }

    /**
     * @param  string|null  $default  returned instead of throwing when the key
     *                                is absent, for keys added after an
     *                                installation published its config
     */
    public function storeKey(string $name, ?string $default = null): string
    {
        $keys = $this->config['store_keys'] ?? [];

        if (! isset($keys[$name])) {
            if ($default !== null) {
                return $default;
            }

            throw new InvalidArgumentException("Unknown apex redis key: {$name}");
        }

        return $keys[$name];
    }

    public function queueNames(): array
    {
        return array_keys($this->config['queues'] ?? []);
    }

    public function hasQueue(string $name): bool
    {
        return isset($this->config['queues'][$name]);
    }

    public function queue(string $name, ?string $environment = null): array
    {
        if (! $this->hasQueue($name)) {
            throw new InvalidArgumentException("Apex queue '{$name}' is not configured");
        }

        $environment = $environment ?? app()->environment();
        $queue = $this->config['queues'][$name];
        $profileName = $queue['profile'] ?? 'background';
        $profile = $this->config['profiles'][$profileName] ?? [];

        $envOverride = $this->config['environments'][$environment]['queues'][$name] ?? [];

        $merged = array_replace($profile, $queue, $envOverride);

        $merged['name'] = $name;
        $merged['profile'] = $profileName;
        $merged['queues'] = $merged['queues'] ?? [$name];
        $group = $merged['group'] ?? null;
        $merged['group'] = is_string($group) && $group !== '' ? $group : null;
        $merged['min_processes'] = (int) ($merged['min_processes'] ?? 0);
        $merged['min_processes_when_active'] = (int) ($merged['min_processes_when_active'] ?? $merged['min_processes']);
        $merged['max_processes'] = (int) ($merged['max_processes'] ?? max(1, $merged['min_processes']));
        $merged['use_activity'] = (bool) ($merged['use_activity'] ?? false);
        $merged['standby_when_active'] = max(0, (int) ($merged['standby_when_active'] ?? 0));
        $merged['burst_threshold_depth'] = max(0, (int) ($merged['burst_threshold_depth'] ?? 0));
        $merged['burst_max_extra'] = max(0, (int) ($merged['burst_max_extra'] ?? 0));
        $merged['burst_enabled'] = $merged['burst_max_extra'] > 0 && $merged['burst_threshold_depth'] > 0;

        // Display priority: positive = pinned to top (higher first), null = middle
        // (config order), negative = pinned to bottom (higher first within bottom).
        // Cast empty string to null so env overrides can clear it.
        $dp = $merged['display_priority'] ?? null;
        $merged['display_priority'] = ($dp === null || $dp === '') ? null : (int) $dp;

        // Flex queue support. A flex queue listens on multiple queues and
        // serves as a first-line responder so dedicated queues stay idle
        // until the flex pool is saturated. `standby_counts_flex` lets a
        // dedicated queue opt out of having flex workers count toward its
        // standby buffer (useful when a queue absolutely needs a guaranteed
        // dedicated reserve).
        $merged['is_flex'] = (bool) ($merged['is_flex'] ?? false);
        $merged['standby_counts_flex'] = (bool) ($merged['standby_counts_flex'] ?? true);

        if ($merged['min_processes_when_active'] < $merged['min_processes']) {
            $merged['min_processes_when_active'] = $merged['min_processes'];
        }

        if ($merged['max_processes'] < $merged['min_processes_when_active']) {
            $merged['max_processes'] = $merged['min_processes_when_active'];
        }

        return $merged;
    }

    public function allQueues(?string $environment = null): array
    {
        $out = [];
        $configOrder = [];
        $i = 0;

        foreach ($this->queueNames() as $name) {
            $out[$name] = $this->queue($name, $environment);
            $configOrder[$name] = $i++;
        }

        // Tri-state sort by display_priority:
        //   bucket 0 = positive (top, higher first)
        //   bucket 1 = null     (middle, original config order)
        //   bucket 2 = negative (bottom, higher first within bottom)
        // Used by both the dashboard UI and master iteration so logs/snapshots
        // share a single canonical order.
        uasort($out, function ($a, $b) use ($configOrder) {
            $pa = $a['display_priority'] ?? null;
            $pb = $b['display_priority'] ?? null;
            $ba = $pa === null ? 1 : ($pa >= 0 ? 0 : 2);
            $bb = $pb === null ? 1 : ($pb >= 0 ? 0 : 2);

            if ($ba !== $bb) {
                return $ba <=> $bb;
            }
            if ($ba === 1) {
                return ($configOrder[$a['name']] ?? 0) <=> ($configOrder[$b['name']] ?? 0);
            }

            return $pb <=> $pa;
        });

        return $out;
    }

    /**
     * Return the names of all queues belonging to the given group.
     *
     * A group is an arbitrary label the host application assigns to related
     * queues so it can suspend them as a unit. Queues without a `group` entry
     * are ignored. Comparison is done on the raw config (no profile merge) for
     * performance — `group` is not environment-overridable.
     *
     * @return list<string>
     */
    public function queuesForGroup(string $group): array
    {
        $out = [];

        foreach ($this->config['queues'] ?? [] as $name => $queue) {
            if (($queue['group'] ?? null) === $group) {
                $out[] = $name;
            }
        }

        return $out;
    }

    /**
     * Return all queues marked as `is_flex`. Each entry is a fully merged
     * config (same shape as `queue()`).
     */
    public function flexQueues(?string $environment = null): array
    {
        $out = [];
        foreach ($this->allQueues($environment) as $name => $cfg) {
            if (! empty($cfg['is_flex'])) {
                $out[$name] = $cfg;
            }
        }

        return $out;
    }
}
