<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Symphoria\Apex\Apex;
use Symphoria\Apex\Contracts\ApexStore;
use Symphoria\Apex\Ipc\MasterLock;
use Symphoria\Apex\Ipc\Stores\DatabaseStore;
use Symphoria\Apex\Ipc\Stores\RedisStore;
use Symphoria\Apex\Support\ApexConfig;

/**
 * Two masters on one installation is not a degraded mode, it is a broken one:
 * neither sees the other's workers, so both scale the same queues and spawn
 * past every configured maximum.
 *
 * Run against both stores, because an installation without Redis has to be
 * just as protected as one with it — and the two get their atomicity from
 * completely different places: `SET NX` on one, a unique index on the other.
 */
beforeEach(function () {
    config()->set('queue.default', 'primary');
    config()->set('queue.connections.primary', ['driver' => 'database', 'queue' => 'default', 'table' => 'jobs']);
    config()->set('apex.master.lock_ttl_seconds', 30);

    try {
        Redis::connection()->flushdb();
    } catch (Throwable) {
        // No Redis here; the database pass covers everything that matters.
    }
});

dataset('lock stores', ['database', 'redis']);

function lockStore(string $driver): ?ApexStore
{
    $config = ApexConfig::fromConfig();

    if ($driver === 'database') {
        return new DatabaseStore($config);
    }

    try {
        Redis::connection($config->connection())->ping();
    } catch (Throwable) {
        return null;
    }

    return new RedisStore($config);
}

function makeLock(ApexStore $store): MasterLock
{
    return new MasterLock(ApexConfig::fromConfig(), $store);
}

it('is free until someone takes it', function (string $driver) {
    $store = lockStore($driver);

    if ($store === null) {
        $this->markTestSkipped('no redis server available');
    }

    expect(makeLock($store)->acquire())->toBeTrue();
})->with('lock stores');

it('refuses a second master', function (string $driver) {
    $store = lockStore($driver);

    if ($store === null) {
        $this->markTestSkipped('no redis server available');
    }

    $first = makeLock($store);
    $second = makeLock($store);

    expect($first->acquire())->toBeTrue()
        ->and($second->acquire())->toBeFalse()
        ->and($second->heldByThisProcess())->toBeFalse();
})->with('lock stores');

it('names the holder so the refusal is actionable', function (string $driver) {
    $store = lockStore($driver);

    if ($store === null) {
        $this->markTestSkipped('no redis server available');
    }

    makeLock($store)->acquire();

    $holder = makeLock($store)->holder();

    expect($holder)->not->toBeNull()
        ->and($holder['pid'])->toBe(getmypid())
        ->and($holder['host'])->toBe(gethostname())
        ->and($holder['since'])->toBeGreaterThan(0);
})->with('lock stores');

it('hands over once the previous master released it', function (string $driver) {
    $store = lockStore($driver);

    if ($store === null) {
        $this->markTestSkipped('no redis server available');
    }

    $first = makeLock($store);
    $first->acquire();
    $first->release();

    expect(makeLock($store)->acquire())->toBeTrue();
})->with('lock stores');

it('can be taken by force when the holder is known to be gone', function (string $driver) {
    $store = lockStore($driver);

    if ($store === null) {
        $this->markTestSkipped('no redis server available');
    }

    makeLock($store)->acquire();

    $second = makeLock($store);

    expect($second->acquire(force: true))->toBeTrue()
        ->and($second->heldByThisProcess())->toBeTrue();
})->with('lock stores');

it('stands down when its lease was taken over', function (string $driver) {
    $store = lockStore($driver);

    if ($store === null) {
        $this->markTestSkipped('no redis server available');
    }

    $original = makeLock($store);
    $original->acquire();

    makeLock($store)->acquire(force: true);

    // Past ttl/3, so the refresh actually goes and looks.
    (new ReflectionProperty($original, 'lastRefreshAt'))->setValue($original, microtime(true) - 60);
    $original->refresh();

    expect($original->heldByThisProcess())->toBeFalse();
})->with('lock stores');

it('does not delete a lock that already belongs to its replacement', function (string $driver) {
    $store = lockStore($driver);

    if ($store === null) {
        $this->markTestSkipped('no redis server available');
    }

    $original = makeLock($store);
    $original->acquire();

    $replacement = makeLock($store);
    $replacement->acquire(force: true);

    // A master shutting down slowly must not take the new one's lock with it.
    $original->release();

    expect($replacement->holder())->not->toBeNull()
        ->and(makeLock($store)->acquire())->toBeFalse();
})->with('lock stores');

it('keeps the lease alive while the loop turns', function (string $driver) {
    $store = lockStore($driver);

    if ($store === null) {
        $this->markTestSkipped('no redis server available');
    }

    $lock = makeLock($store);
    $lock->acquire();

    (new ReflectionProperty($lock, 'lastRefreshAt'))->setValue($lock, microtime(true) - 60);
    $lock->refresh();

    expect($lock->heldByThisProcess())->toBeTrue()
        ->and(makeLock($store)->acquire())->toBeFalse();
})->with('lock stores');

it('leaves the store alone when nothing is held', function (string $driver) {
    $store = lockStore($driver);

    if ($store === null) {
        $this->markTestSkipped('no redis server available');
    }

    $lock = makeLock($store);

    $lock->refresh();
    $lock->release();

    expect($lock->heldByThisProcess())->toBeFalse()
        ->and($lock->holder())->toBeNull();
})->with('lock stores');

it('comes free on its own after a kill -9', function () {
    // Database store only: Redis expiry is the server's own business, while
    // this store keeps `expires_at` itself and has to filter on it. Reaching
    // into the row beats sleeping out a lease that is 5 seconds at its
    // shortest, and neither backend accepts a negative TTL.
    $store = new DatabaseStore(ApexConfig::fromConfig());

    makeLock($store)->acquire();

    expect(makeLock($store)->acquire())->toBeFalse();

    DB::table(Apex::table('store'))
        ->where('key', (string) config('apex.store_keys.master_lock'))
        ->update(['expires_at' => time() - 1]);

    expect(makeLock($store)->acquire())->toBeTrue();
});
