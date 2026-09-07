<?php

declare(strict_types=1);

namespace Symphoria\Apex\Ipc;

use Symphoria\Apex\Contracts\ApexStore;
use Symphoria\Apex\Support\ApexConfig;

/**
 * Tells an idle master that something was dispatched, so it can skip reading
 * every queue depth on a tick where nothing happened.
 *
 * Throttled per process, because this fires on every single dispatch:
 * `Bus::bulk()` with ten thousand jobs would otherwise be ten thousand writes
 * saying the same thing, and on the database store every one of those is a
 * query. The key only has to exist, so re-writing it inside its own lifetime
 * buys nothing.
 */
class WakeSignal
{
    private float $lastWriteAt = 0.0;

    public function __construct(
        private readonly ApexConfig $config,
        private readonly ApexStore $store,
    ) {}

    public function mark(): void
    {
        $ttl = $this->ttlSeconds();

        // Refresh well before expiry: a dispatch burst that spans the TTL must
        // not leave the master blind halfway through.
        if ((microtime(true) - $this->lastWriteAt) < $ttl / 2) {
            return;
        }

        $this->lastWriteAt = microtime(true);

        $this->store->put($this->key(), (string) microtime(true), $ttl);
    }

    public function isSet(): bool
    {
        return $this->store->exists($this->key());
    }

    public function clear(): void
    {
        $this->lastWriteAt = 0.0;

        $this->store->forget($this->key());
    }

    private function ttlSeconds(): int
    {
        return max(2, (int) ($this->config->master()['wake_ttl_seconds'] ?? 60));
    }

    private function key(): string
    {
        return $this->config->storeKey('wake');
    }
}
