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

    private int $reacquisitions = 0;

    private float $lastLapseSeconds = 0.0;

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
     * Extend the lease, and notice if it was lost.
     *
     * Two very different things make the stored value differ from ours. Another
     * master may have taken over (a forced start on deploy, or a replacement
     * after this process looked dead): then we stand down, because two masters
     * scaling the same queues is a broken installation. Or the key simply
     * expired because this loop did not turn for longer than the TTL — a host
     * under memory pressure can stall any process for half a minute — and
     * nobody claimed it in the meantime. Standing down there would kill every
     * worker for no reason and leave the queues unstaffed until a supervisor
     * notices; instead the lease is claimed again, atomically, and reported so
     * the stall is visible.
     */
    public function refresh(): LockRefresh
    {
        if ($this->token === null) {
            return LockRefresh::NotHeld;
        }

        $ttl = $this->ttlSeconds();
        $now = microtime(true);
        $sinceLast = $now - $this->lastRefreshAt;

        if ($sinceLast < $ttl / 3) {
            return LockRefresh::Skipped;
        }

        $this->lastRefreshAt = $now;
        $current = $this->store->get($this->key());

        if ($current === $this->token) {
            $this->store->put($this->key(), $this->token, $ttl);

            return LockRefresh::Held;
        }

        // add() is atomic (SET NX / unique index), so two masters that both see
        // an expired key cannot both come out of this holding it.
        if (($current === null || $current === '') && $this->store->add($this->key(), $this->token, $ttl)) {
            $this->reacquisitions++;
            $this->lastLapseSeconds = $sinceLast;

            return LockRefresh::Reacquired;
        }

        $this->token = null;

        return LockRefresh::Lost;
    }

    /** How often the lease lapsed and was claimed again by this same process. */
    public function reacquisitions(): int
    {
        return $this->reacquisitions;
    }

    /** Seconds between the previous renewal and the one that found the key gone. */
    public function lastLapseSeconds(): float
    {
        return $this->lastLapseSeconds;
    }

    public function ttlSeconds(): int
    {
        return max(5, (int) ($this->config->master()['lock_ttl_seconds'] ?? 30));
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

    private function key(): string
    {
        return $this->config->storeKey('master_lock', 'apex:master:lock');
    }
}
