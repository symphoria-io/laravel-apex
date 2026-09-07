<?php

namespace Symphoria\Apex\Queue;

use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\RedisQueue;

/**
 * RedisQueue extension that adds atomic multi-queue blocking pop via BLMPOP
 * (Redis 7+). Behaviour is identical to the parent class unless
 * popFromMultiple() is called — that path is opt-in from ApexWorker.
 *
 * Strategy: use BLMPOP on the per-queue ':notify' lists (the same channel
 * Laravel uses internally for its single-queue blockFor flow). BLMPOP wakes
 * us the instant ANY of the watched queues receives a push, then we run
 * Laravel's standard atomic pop Lua on that one queue.
 */
class ApexRedisQueue extends RedisQueue
{
    private ?bool $supportsBlmpop = null;

    private bool $lastCallBlocked = false;

    /**
     * Whether the last popFromMultiple() actually waited on Redis.
     *
     * False means it returned without blocking — an unsupported client, a
     * connection error, or a zero timeout. The caller has to supply the idle
     * wait itself in that case, or it spins.
     */
    public function lastCallBlocked(): bool
    {
        return $this->lastCallBlocked;
    }

    public function supportsBlmpop(): bool
    {
        if ($this->supportsBlmpop !== null) {
            return $this->supportsBlmpop;
        }

        try {
            $info = $this->getConnection()->info('server');
            $version = is_array($info)
                ? ($info['redis_version'] ?? ($info['Server']['redis_version'] ?? null))
                : null;
            $major = is_string($version) ? (int) explode('.', $version)[0] : 0;

            return $this->supportsBlmpop = ($major >= 7);
        } catch (\Throwable) {
            return $this->supportsBlmpop = false;
        }
    }

    /**
     * Block across multiple queues at once.
     *
     * @param  array<int,string>  $queues  raw queue names (no prefix)
     * @param  float  $timeoutSeconds  BLMPOP timeout
     * @return array{0:string,1:Job}|null
     */
    public function popFromMultiple(array $queues, float $timeoutSeconds): ?array
    {
        $this->lastCallBlocked = false;

        $queues = array_values(array_unique(array_filter(array_map('trim', $queues))));
        if (empty($queues)) {
            return null;
        }

        // Suppress blockFor while we drive the wait via BLMPOP ourselves; the
        // parent's per-queue BLPOP on :notify would otherwise stall on the
        // first queue and defeat the multi-queue benefit.
        $savedBlockFor = $this->blockFor;
        $this->blockFor = null;

        try {
            // Non-blocking pass first: handles delayed-job migration and grabs
            // anything already waiting. Cheap (one Lua eval per queue).
            foreach ($queues as $q) {
                if (($job = parent::pop($q)) !== null) {
                    return [$q, $job];
                }
            }

            if (! $this->supportsBlmpop() || $timeoutSeconds <= 0) {
                return null;
            }

            $notifyKeys = array_map(fn ($q) => $this->getQueue($q).':notify', $queues);

            $popped = $this->execBlmpop($notifyKeys, $timeoutSeconds);
            if ($popped === null) {
                return null;
            }

            $signaledKey = (string) ($popped[0] ?? '');
            foreach ($queues as $i => $q) {
                if ($notifyKeys[$i] === $signaledKey) {
                    if (($job = parent::pop($q)) !== null) {
                        return [$q, $job];
                    }
                    break;
                }
            }

            return null;
        } finally {
            $this->blockFor = $savedBlockFor;
        }
    }

    /**
     * Run BLMPOP via phpredis (the modern default). Returns [keyName, [value]]
     * on hit, null on timeout or unsupported client. Marks BLMPOP unavailable
     * on this instance if the call throws, so we degrade gracefully.
     */
    private function execBlmpop(array $keys, float $timeout): ?array
    {
        $client = $this->getConnection()->client();

        if (! class_exists('Redis') || ! ($client instanceof \Redis) || ! method_exists($client, 'blmpop')) {
            $this->supportsBlmpop = false;

            return null;
        }

        try {
            $result = $client->blmpop($timeout, $keys, 'LEFT');
            $this->lastCallBlocked = true;
        } catch (\Throwable) {
            return null;
        }

        return (is_array($result) && count($result) >= 2) ? $result : null;
    }
}
