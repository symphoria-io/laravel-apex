<?php

declare(strict_types=1);

namespace Symphoria\Apex\Ipc\Depth;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Symphoria\Apex\Contracts\QueueDepthProbe;
use Symphoria\Apex\Support\ApexConfig;

/**
 * Backlog for the database queue driver.
 *
 * Cheaper per tick than the Redis reader rather than more expensive: one
 * grouped query answers depth *and* oldest-wait for every queue at once,
 * where Redis needs three reads per queue plus a second round for the ages.
 * What it costs instead is a scan on the jobs table, which is why the master
 * runs at a lower cadence on this driver.
 */
final class DatabaseQueueDepthReader implements QueueDepthProbe
{
    /** @var array<string, array{depth: int, oldest: int|null}> */
    private array $cache = [];

    public function __construct(
        private readonly ApexConfig $config,
    ) {}

    public function resetCache(): void
    {
        $this->cache = [];
    }

    public function depth(array $queueNames): int
    {
        $this->load($queueNames);

        $total = 0;
        foreach ($queueNames as $name) {
            $total += $this->cache[$name]['depth'] ?? 0;
        }

        return $total;
    }

    public function prefetch(array $groups): void
    {
        $names = [];

        foreach ($groups as $group) {
            foreach ($group as $name) {
                if ($name !== '') {
                    $names[] = $name;
                }
            }
        }

        $this->load($names);
    }

    public function oldestWaitSeconds(array $queueNames): ?int
    {
        $this->load($queueNames);

        $oldest = null;

        foreach ($queueNames as $name) {
            $age = $this->cache[$name]['oldest'] ?? null;

            if ($age !== null && ($oldest === null || $age > $oldest)) {
                $oldest = $age;
            }
        }

        return $oldest;
    }

    /**
     * @param  array<int, string>  $queueNames
     */
    private function load(array $queueNames): void
    {
        $missing = array_values(array_unique(array_filter(
            $queueNames,
            fn (string $name): bool => $name !== '' && ! array_key_exists($name, $this->cache),
        )));

        if ($missing === []) {
            return;
        }

        $now = time();

        // Mirrors DatabaseQueue::getNextAvailableJob: a job being worked on is
        // not backlog, but one whose reservation has outlived `retry_after` is,
        // because Laravel hands that to the next worker asking.
        $expiredBefore = $now - $this->retryAfterSeconds();

        $rows = $this->query()
            ->whereIn('queue', $missing)
            ->where(function (Builder $query) use ($now, $expiredBefore): void {
                $query->where(function (Builder $available) use ($now): void {
                    $available->whereNull('reserved_at')->where('available_at', '<=', $now);
                })->orWhere('reserved_at', '<=', $expiredBefore);
            })
            ->groupBy('queue')
            ->selectRaw('queue, count(*) as aggregate_depth, min(created_at) as oldest_created_at')
            ->get();

        foreach ($missing as $name) {
            $this->cache[$name] = ['depth' => 0, 'oldest' => null];
        }

        foreach ($rows as $row) {
            $queue = (string) $row->queue;
            $created = $row->oldest_created_at;

            $this->cache[$queue] = [
                'depth' => (int) $row->aggregate_depth,
                'oldest' => $created === null ? null : max(0, $now - (int) $created),
            ];
        }
    }

    /**
     * Laravel's own default when a connection does not name one.
     */
    private function retryAfterSeconds(): int
    {
        $configured = $this->config->queueConnectionConfig()['retry_after'] ?? 60;

        return max(1, (int) $configured);
    }

    private function query(): Builder
    {
        $connection = $this->config->queueConnectionConfig();

        return DB::connection($connection['connection'] ?? null)->table($connection['table'] ?? 'jobs');
    }
}
