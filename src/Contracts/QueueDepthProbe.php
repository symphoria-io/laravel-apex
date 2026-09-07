<?php

declare(strict_types=1);

namespace Symphoria\Apex\Contracts;

/**
 * Reads backlog straight from the queue backend, bypassing Laravel's queue
 * abstraction, which has no notion of "how much work is waiting".
 *
 * Separate from ApexStore on purpose: an application can keep Apex state in
 * the database while running its queue on Redis, or the other way round. This
 * one always follows the queue driver.
 */
interface QueueDepthProbe
{
    /**
     * Drop the per-tick memoization. The master calls this once at the start
     * of every tick; without it the same queue is re-read 3-4 times per tick
     * (scaleQueue, countFlexIdleByQueue, writeSnapshot).
     */
    public function resetCache(): void;

    /**
     * @param  array<int, string>  $queueNames
     */
    public function depth(array $queueNames): int;

    /**
     * Warm the cache for many queue groups at once, so the 12+ per-queue
     * lookups that follow are answered from memory.
     *
     * @param  array<int, array<int, string>>  $groups
     */
    public function prefetch(array $groups): void;

    /**
     * Age in seconds of the oldest job still waiting, or null when nothing is
     * waiting.
     *
     * @param  array<int, string>  $queueNames
     */
    public function oldestWaitSeconds(array $queueNames): ?int;
}
