<?php

declare(strict_types=1);

namespace Symphoria\Apex\Ipc\Stores;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Symphoria\Apex\Apex;
use Symphoria\Apex\Contracts\ApexStore;
use Symphoria\Apex\Support\ApexConfig;

/**
 * Lets Apex run without Redis. Slower per operation, but the master reads in
 * bulk so a tick is a handful of queries rather than one per key.
 *
 * Expiry is lazy: rows carry `expires_at` and are filtered on read, then
 * swept periodically. A cron-style sweep would be another moving part for no
 * gain, since a stale row is invisible the moment it expires.
 */
final class DatabaseStore implements ApexStore
{
    public function __construct(
        private readonly ApexConfig $config,
    ) {}

    public function get(string $key): ?string
    {
        $value = $this->query()
            ->where('key', $key)
            ->where('member', '')
            ->where($this->unexpired(...))
            ->value('value');

        return is_string($value) ? $value : null;
    }

    public function put(string $key, string $value, ?int $ttlSeconds = null): void
    {
        $this->query()->updateOrInsert(
            ['key' => $key, 'member' => ''],
            [
                'value' => $value,
                'expires_at' => $ttlSeconds !== null && $ttlSeconds > 0 ? time() + $ttlSeconds : null,
                'updated_at' => time(),
            ],
        );
    }

    public function forget(string $key): void
    {
        $this->query()->where('key', $key)->where('member', '')->delete();
    }

    /**
     * Leans on the unique index over (key, member): the insert either lands or
     * it does not, so two masters starting at the same moment cannot both be
     * told they won. An expired row is cleared first, which is what lets a
     * lock survive its holder being killed.
     */
    public function add(string $key, string $value, ?int $ttlSeconds = null): bool
    {
        $this->query()
            ->where('key', $key)
            ->where('member', '')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', time())
            ->delete();

        try {
            $this->query()->insert([
                'key' => $key,
                'member' => '',
                'value' => $value,
                'expires_at' => $ttlSeconds !== null && $ttlSeconds > 0 ? time() + $ttlSeconds : null,
                'updated_at' => time(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return false;
        } catch (QueryException) {
            // Older drivers do not map this to the dedicated exception.
            return false;
        }

        return true;
    }

    public function exists(string $key): bool
    {
        return $this->get($key) !== null;
    }

    public function many(array $keys): array
    {
        $keys = array_values(array_unique($keys));

        if ($keys === []) {
            return [];
        }

        $rows = $this->query()
            ->whereIn('key', $keys)
            ->where('member', '')
            ->where($this->unexpired(...))
            ->pluck('value', 'key');

        $out = [];
        foreach ($keys as $key) {
            $value = $rows[$key] ?? null;
            $out[$key] = is_string($value) && $value !== '' ? $value : null;
        }

        return $out;
    }

    public function existsMany(array $keys): array
    {
        return array_map(static fn (?string $value): bool => $value !== null, $this->many($keys));
    }

    public function keys(string $prefix): array
    {
        return array_values($this->query()
            ->where('key', 'like', str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $prefix).'%')
            ->where('member', '')
            ->where($this->unexpired(...))
            ->pluck('key')
            ->map(strval(...))
            ->all());
    }

    public function increment(string $key, int $by = 1, ?int $ttlSeconds = null): int
    {
        $expiresAt = $ttlSeconds !== null && $ttlSeconds > 0 ? time() + $ttlSeconds : null;

        // Two workers finishing a job at the same moment must not lose a
        // count, so read-modify-write is not an option; the increment happens
        // in the database.
        $affected = $this->query()
            ->where('key', $key)
            ->where('member', '')
            ->where($this->unexpired(...))
            ->increment('value', $by, ['expires_at' => $expiresAt, 'updated_at' => time()]);

        if ($affected === 0) {
            // Either absent or expired. Overwrite rather than insert, so an
            // expired counter restarts instead of colliding on the unique key.
            $this->query()->updateOrInsert(
                ['key' => $key, 'member' => ''],
                ['value' => (string) $by, 'expires_at' => $expiresAt, 'updated_at' => time()],
            );

            return $by;
        }

        return (int) $this->get($key);
    }

    public function hashPut(string $key, string $field, string $value): void
    {
        $this->query()->updateOrInsert(
            ['key' => $key, 'member' => $field],
            ['value' => $value, 'expires_at' => null, 'updated_at' => time()],
        );
    }

    public function hashGet(string $key, string $field): ?string
    {
        $value = $this->query()
            ->where('key', $key)
            ->where('member', $field)
            ->where($this->unexpired(...))
            ->value('value');

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function hashForget(string $key, array $fields): void
    {
        if ($fields === []) {
            return;
        }

        $this->query()->where('key', $key)->whereIn('member', $fields)->delete();
    }

    public function hashAll(string $key): array
    {
        return $this->query()
            ->where('key', $key)
            ->where('member', '<>', '')
            ->where($this->unexpired(...))
            ->pluck('value', 'member')
            ->map(strval(...))
            ->all();
    }

    public function timelineAdd(string $key, float $score, string $value): void
    {
        $this->timeline()->insert(['key' => $key, 'score' => $score, 'value' => $value]);
    }

    public function timelineNewest(string $key, int $limit): array
    {
        return array_values($this->timeline()
            ->where('key', $key)
            ->orderByDesc('score')
            ->orderByDesc('id')
            ->limit(max(1, $limit))
            ->pluck('value')
            ->map(strval(...))
            ->all());
    }

    public function timelineSince(string $key, float $exclusiveMin, int $limit): array
    {
        return array_values($this->timeline()
            ->where('key', $key)
            ->where('score', '>', $exclusiveMin)
            ->orderBy('score')
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->pluck('value')
            ->map(strval(...))
            ->all());
    }

    public function timelineTrim(string $key, ?float $minScore, ?int $maxEntries): void
    {
        if ($minScore !== null) {
            $this->timeline()->where('key', $key)->where('score', '<', $minScore)->delete();
        }

        if ($maxEntries === null) {
            return;
        }

        // Find the score of the oldest entry we want to keep, then delete
        // everything below it. One extra query, but it avoids pulling ids for
        // a potentially large overflow.
        $cutoff = $this->timeline()
            ->where('key', $key)
            ->orderByDesc('score')
            ->orderByDesc('id')
            ->offset(max(0, $maxEntries))
            ->limit(1)
            ->value('score');

        if ($cutoff !== null) {
            $this->timeline()->where('key', $key)->where('score', '<=', (float) $cutoff)->delete();
        }
    }

    /**
     * Expiry is lazy: rows carry `expires_at` and are filtered on read, so an
     * expired entry is invisible the moment it lapses. This reclaims the
     * space, and is called from apex:prune-history rather than a schedule of
     * its own.
     */
    public function sweep(): int
    {
        return $this->query()->whereNotNull('expires_at')->where('expires_at', '<=', time())->delete();
    }

    private function unexpired(Builder $query): void
    {
        $query->whereNull('expires_at')->orWhere('expires_at', '>', time());
    }

    private function query(): Builder
    {
        return $this->connection()->table(Apex::table('store'));
    }

    private function timeline(): Builder
    {
        return $this->connection()->table(Apex::table('timeline'));
    }

    private function connection(): ConnectionInterface
    {
        return DB::connection($this->config->databaseConnection());
    }
}
