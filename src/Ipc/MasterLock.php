<?php

declare(strict_types=1);

namespace Symphoria\Apex\Ipc;

use Symphoria\Apex\Contracts\ApexStore;
use Symphoria\Apex\Support\ApexConfig;

/**
 * Keeps one master per installation.
 *
 * Two masters watching the same queues both scale them, each blind to the
 * other's workers: they spawn past every maximum together and retire each
 * other's processes. Nothing in the design prevented a second `apex:start`,
 * and the failure looks like erratic scaling rather than like two supervisors.
 *
 * Held for `lock_ttl_seconds` and renewed while the loop turns, so a master
 * that is killed outright frees it by lapsing rather than leaving it stuck.
 */
final class MasterLock
{
    private ?string $token = null;

    private float $lastRefreshAt = 0.0;

    public function __construct(
        private readonly ApexConfig $config,
        private readonly ApexStore $store,
    ) {}

    /**
     * @param  bool  $force  take the lock even when someone else holds it
     */
    public function acquire(bool $force = false): bool
    {
        $token = $this->newToken();

        if ($force) {
            $this->store->put($this->key(), $token, $this->ttlSeconds());
        } elseif (! $this->store->add($this->key(), $token, $this->ttlSeconds())) {
            return false;
        }

        $this->token = $token;
        $this->lastRefreshAt = microtime(true);

        return true;
    }

    /**
     * Extend the lease, and notice if it was lost. A master whose lock lapsed
     * while another one started must stand down rather than quietly carry on
     * scaling the same queues.
     */
    public function refresh(): void
    {
        if ($this->token === null) {
            return;
        }

        $ttl = $this->ttlSeconds();

        if ((microtime(true) - $this->lastRefreshAt) < $ttl / 3) {
            return;
        }

        $this->lastRefreshAt = microtime(true);

        if ($this->store->get($this->key()) !== $this->token) {
            $this->token = null;

            return;
        }

        $this->store->put($this->key(), $this->token, $ttl);
    }

    public function heldByThisProcess(): bool
    {
        return $this->token !== null;
    }

    public function release(): void
    {
        if ($this->token === null) {
            return;
        }

        // Only ever drop our own lease, or a master shutting down slowly would
        // delete the lock its replacement has already taken.
        if ($this->store->get($this->key()) === $this->token) {
            $this->store->forget($this->key());
        }

        $this->token = null;
    }

    /**
     * Who holds it right now, for an error message worth reading.
     *
     * @return array{host: string, pid: int, since: int}|null
     */
    public function holder(): ?array
    {
        $raw = $this->store->get($this->key());

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            return null;
        }

        return [
            'host' => (string) ($decoded['host'] ?? 'unknown'),
            'pid' => (int) ($decoded['pid'] ?? 0),
            'since' => (int) ($decoded['since'] ?? 0),
        ];
    }

    private function newToken(): string
    {
        return (string) json_encode([
            'host' => gethostname() ?: 'unknown',
            'pid' => getmypid() ?: 0,
            'since' => time(),
            // Two hosts can report the same pid, and a pid is reused after a
            // crash. The nonce is what makes the token identify this process.
            'nonce' => bin2hex(random_bytes(8)),
        ]);
    }

    private function ttlSeconds(): int
    {
        return max(5, (int) ($this->config->master()['lock_ttl_seconds'] ?? 30));
    }

    private function key(): string
    {
        return $this->config->storeKey('master_lock', 'apex:master:lock');
    }
}
