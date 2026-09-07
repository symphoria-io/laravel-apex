<?php

declare(strict_types=1);

namespace Symphoria\Apex\Contracts;

/**
 * Ephemeral shared state between the master, its workers and the dashboard:
 * heartbeats, control flags, activity, counters and job timelines.
 * Deliberately not the queue backend — that stays Laravel's.
 *
 * Three shapes, because Apex genuinely needs three: plain keys, hashes for
 * in-flight jobs keyed by uuid, and timelines for anything ordered by time.
 * The bulk methods exist because the master reads a lot of keys on every
 * tick; done one by one that is the dominant cost of an otherwise idle
 * installation, so any new caller must use `many()` or `existsMany()` rather
 * than looping over the single-key methods.
 */
interface ApexStore
{
    public function get(string $key): ?string;

    public function put(string $key, string $value, ?int $ttlSeconds = null): void;

    /**
     * Set the key only when it is absent or has expired, and report whether
     * that succeeded. Atomic: two callers racing for the same key must not
     * both be told they got it, which is what makes it usable as a lock.
     */
    public function add(string $key, string $value, ?int $ttlSeconds = null): bool;

    public function forget(string $key): void;

    public function exists(string $key): bool;

    /**
     * @param  list<string>  $keys
     * @return array<string, string|null>
     */
    public function many(array $keys): array;

    /**
     * @param  list<string>  $keys
     * @return array<string, bool>
     */
    public function existsMany(array $keys): array;

    /**
     * Every key starting with the given prefix.
     *
     * Scans the keyspace, so it is for tooling only — never call it from the
     * master loop.
     *
     * @return list<string>
     */
    public function keys(string $prefix): array;

    public function increment(string $key, int $by = 1, ?int $ttlSeconds = null): int;

    public function hashPut(string $key, string $field, string $value): void;

    public function hashGet(string $key, string $field): ?string;

    /**
     * @param  list<string>  $fields
     */
    public function hashForget(string $key, array $fields): void;

    /**
     * @return array<string, string>
     */
    public function hashAll(string $key): array;

    /**
     * Append to a time-ordered log. The score is a microtime float; entries
     * are opaque strings (JSON, in practice).
     */
    public function timelineAdd(string $key, float $score, string $value): void;

    /**
     * Newest first.
     *
     * @return list<string>
     */
    public function timelineNewest(string $key, int $limit): array;

    /**
     * Everything strictly newer than $exclusiveMin, oldest first. Drives the
     * live tail, which polls with the score it last saw.
     *
     * @return list<string>
     */
    public function timelineSince(string $key, float $exclusiveMin, int $limit): array;

    /**
     * Drop entries that have lapsed and return how many were removed.
     *
     * Expired entries are never *visible*, whatever the store; this is about
     * reclaiming the space they occupy. Backends that expire on their own
     * return 0.
     */
    public function sweep(): int;

    /**
     * Drop entries scored below $minScore and, beyond that, anything past the
     * newest $maxEntries. Either bound may be null to skip it.
     */
    public function timelineTrim(string $key, ?float $minScore, ?int $maxEntries): void;
}
