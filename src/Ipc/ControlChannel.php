<?php

namespace Symphoria\Apex\Ipc;

use Symphoria\Apex\Contracts\ApexStore;
use Symphoria\Apex\Support\ApexConfig;

class ControlChannel
{
    public function __construct(
        private readonly ApexConfig $config,
        private readonly ApexStore $store,
        private readonly WakeSignal $wake,
    ) {}

    public function isShutdownRequested(): bool
    {
        return $this->store->exists($this->config->storeKey('control_shutdown'));
    }

    public function requestShutdown(int $ttlSeconds = 60): void
    {
        $this->store->put($this->config->storeKey('control_shutdown'), (string) time(), $ttlSeconds);
    }

    public function clearShutdown(): void
    {
        $this->store->forget($this->config->storeKey('control_shutdown'));
    }

    public function pauseQueue(string $queue): void
    {
        $this->store->put($this->pauseKey($queue), (string) time());
    }

    public function continueQueue(string $queue): void
    {
        $this->store->forget($this->pauseKey($queue));
        $this->wake->mark();
    }

    public function isQueuePaused(string $queue): bool
    {
        return $this->store->exists($this->pauseKey($queue));
    }

    /**
     * Read pause + suspension state for many queues in a single round trip.
     * The master needs both flags for every queue on every tick; done
     * one-by-one that is 4 lookups per queue per tick.
     *
     * @param  array<int, string>  $queueNames
     * @return array<string, array{paused: bool, suspended: bool}>
     */
    public function statesFor(array $queueNames): array
    {
        $queueNames = array_values(array_unique($queueNames));

        if ($queueNames === []) {
            return [];
        }

        $keys = [];
        foreach ($queueNames as $name) {
            $keys[] = $this->pauseKey($name);
            $keys[] = $this->suspendedKey($name);
        }

        $present = $this->store->existsMany($keys);

        $out = [];
        foreach ($queueNames as $name) {
            $out[$name] = [
                'paused' => $present[$this->pauseKey($name)] ?? false,
                'suspended' => $present[$this->suspendedKey($name)] ?? false,
            ];
        }

        return $out;
    }

    public function suspendQueue(string $queue): void
    {
        $this->store->put($this->suspendedKey($queue), (string) time());
    }

    public function resumeQueue(string $queue): void
    {
        $this->store->forget($this->suspendedKey($queue));
        $this->wake->mark();
    }

    public function isQueueSuspended(string $queue): bool
    {
        return $this->store->exists($this->suspendedKey($queue));
    }

    /**
     * True when the queue should not run, for any reason: an operator paused
     * it, or the application suspended it through a QueueSuspensionSource.
     */
    public function isQueueStopped(string $queue): bool
    {
        return $this->isQueuePaused($queue) || $this->isQueueSuspended($queue);
    }

    private function pauseKey(string $queue): string
    {
        return $this->config->storeKey('control_pause_prefix').$queue;
    }

    private function suspendedKey(string $queue): string
    {
        return $this->config->storeKey('control_suspended_prefix').$queue;
    }
}
