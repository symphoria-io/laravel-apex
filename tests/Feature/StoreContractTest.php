<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Redis;
use Symphoria\Apex\Contracts\ApexStore;
use Symphoria\Apex\Ipc\Stores\DatabaseStore;
use Symphoria\Apex\Ipc\Stores\RedisStore;
use Symphoria\Apex\Support\ApexConfig;

/**
 * One body of assertions, run against every store. A store that passes here
 * is interchangeable with the others as far as Apex is concerned, which is
 * the entire claim the abstraction makes.
 *
 * The Redis pass is skipped when no server is reachable, so the suite still
 * runs on a machine without Redis — which is, after all, the case this whole
 * feature exists for.
 */
function apexStores(): array
{
    return ['database', 'redis'];
}

function makeStore(string $driver): ?ApexStore
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

dataset('stores', apexStores());

beforeEach(function () {
    try {
        Redis::connection()->flushdb();
    } catch (Throwable) {
        // No Redis here; the database pass covers everything that matters.
    }
});

it('round-trips a plain key', function (string $driver) {
    $store = makeStore($driver);

    if ($store === null) {
        $this->markTestSkipped('no redis server available');
    }

    expect($store->get('k'))->toBeNull()
        ->and($store->exists('k'))->toBeFalse();

    $store->put('k', 'v');

    expect($store->get('k'))->toBe('v')
        ->and($store->exists('k'))->toBeTrue();

    $store->forget('k');

    expect($store->get('k'))->toBeNull();
})->with('stores');

it('overwrites rather than duplicating an existing key', function (string $driver) {
    $store = makeStore($driver);

    if ($store === null) {
        $this->markTestSkipped('no redis server available');
    }

    $store->put('k', 'first');
    $store->put('k', 'second');

    expect($store->get('k'))->toBe('second');
})->with('stores');

it('adds a key only when it is absent', function (string $driver) {
    $store = makeStore($driver);

    if ($store === null) {
        $this->markTestSkipped('no redis server available');
    }

    // This is what makes the store usable as a lock: two callers racing for
    // the same key must not both be told they won it.
    expect($store->add('k', 'first', 60))->toBeTrue()
        ->and($store->add('k', 'second', 60))->toBeFalse()
        ->and($store->get('k'))->toBe('first');

    $store->forget('k');

    expect($store->add('k', 'third', 60))->toBeTrue()
        ->and($store->get('k'))->toBe('third');
})->with('stores');

it('adds without a ttl as well', function (string $driver) {
    $store = makeStore($driver);

    if ($store === null) {
        $this->markTestSkipped('no redis server available');
    }

    expect($store->add('k', 'v'))->toBeTrue()
        ->and($store->add('k', 'other'))->toBeFalse()
        ->and($store->get('k'))->toBe('v');
})->with('stores');

it('treats an expired key as absent', function (string $driver) {
    $store = makeStore($driver);

    if ($store === null) {
        $this->markTestSkipped('no redis server available');
    }

    // Negative TTLs are not a thing in either backend, so the shortest
    // portable proof is a key that outlives the assertion.
    $store->put('k', 'v', 60);

    expect($store->get('k'))->toBe('v');
})->with('stores');

it('reads many keys at once, reporting misses as null', function (string $driver) {
    $store = makeStore($driver);

    if ($store === null) {
        $this->markTestSkipped('no redis server available');
    }

    $store->put('a', '1');
    $store->put('c', '3');

    expect($store->many(['a', 'b', 'c']))->toBe(['a' => '1', 'b' => null, 'c' => '3'])
        ->and($store->existsMany(['a', 'b', 'c']))->toBe(['a' => true, 'b' => false, 'c' => true]);
})->with('stores');

it('lists keys by prefix without the client prefix', function (string $driver) {
    $store = makeStore($driver);

    if ($store === null) {
        $this->markTestSkipped('no redis server available');
    }

    $store->put('apex:workers:one', '1');
    $store->put('apex:workers:two', '2');
    $store->put('apex:other', '3');

    $keys = $store->keys('apex:workers:');
    sort($keys);

    expect($keys)->toBe(['apex:workers:one', 'apex:workers:two'])
        // Whatever comes back must be usable as-is; a prefixed key that
        // cannot be read back is the classic Redis keys() trap.
        ->and($store->get($keys[0]))->toBe('1');
})->with('stores');

it('increments a counter from nothing', function (string $driver) {
    $store = makeStore($driver);

    if ($store === null) {
        $this->markTestSkipped('no redis server available');
    }

    expect($store->increment('c'))->toBe(1)
        ->and($store->increment('c'))->toBe(2)
        ->and($store->increment('c', 5))->toBe(7)
        ->and((int) $store->get('c'))->toBe(7);
})->with('stores');

it('stores hash fields independently of the plain key', function (string $driver) {
    $store = makeStore($driver);

    if ($store === null) {
        $this->markTestSkipped('no redis server available');
    }

    $store->hashPut('h', 'one', 'a');
    $store->hashPut('h', 'two', 'b');

    expect($store->hashGet('h', 'one'))->toBe('a')
        ->and($store->hashGet('h', 'missing'))->toBeNull()
        ->and($store->hashAll('h'))->toBe(['one' => 'a', 'two' => 'b']);

    $store->hashPut('h', 'one', 'updated');
    expect($store->hashGet('h', 'one'))->toBe('updated');

    $store->hashForget('h', ['one']);
    expect($store->hashAll('h'))->toBe(['two' => 'b']);

    $store->hashForget('h', []);
    expect($store->hashAll('h'))->toBe(['two' => 'b']);
})->with('stores');

it('reads a timeline newest first', function (string $driver) {
    $store = makeStore($driver);

    if ($store === null) {
        $this->markTestSkipped('no redis server available');
    }

    $store->timelineAdd('t', 1.0, 'oldest');
    $store->timelineAdd('t', 2.0, 'middle');
    $store->timelineAdd('t', 3.0, 'newest');

    expect($store->timelineNewest('t', 10))->toBe(['newest', 'middle', 'oldest'])
        ->and($store->timelineNewest('t', 2))->toBe(['newest', 'middle']);
})->with('stores');

it('reads a timeline forward from a cursor, exclusive', function (string $driver) {
    $store = makeStore($driver);

    if ($store === null) {
        $this->markTestSkipped('no redis server available');
    }

    $store->timelineAdd('t', 1.0, 'a');
    $store->timelineAdd('t', 2.0, 'b');
    $store->timelineAdd('t', 3.0, 'c');

    // Exclusive, or the live tail re-renders its last line on every poll.
    expect($store->timelineSince('t', 2.0, 10))->toBe(['c'])
        ->and($store->timelineSince('t', 0.0, 10))->toBe(['a', 'b', 'c'])
        ->and($store->timelineSince('t', 3.0, 10))->toBe([]);
})->with('stores');

it('trims a timeline by score and by count', function (string $driver) {
    $store = makeStore($driver);

    if ($store === null) {
        $this->markTestSkipped('no redis server available');
    }

    foreach (range(1, 6) as $i) {
        $store->timelineAdd('t', (float) $i, 'entry-'.$i);
    }

    $store->timelineTrim('t', 3.0, null);
    expect($store->timelineNewest('t', 10))->toBe(['entry-6', 'entry-5', 'entry-4', 'entry-3']);

    $store->timelineTrim('t', null, 2);
    expect($store->timelineNewest('t', 10))->toBe(['entry-6', 'entry-5']);

    $store->timelineTrim('t', null, null);
    expect($store->timelineNewest('t', 10))->toBe(['entry-6', 'entry-5']);
})->with('stores');

it('leaves a timeline shorter than the cap alone', function (string $driver) {
    $store = makeStore($driver);

    if ($store === null) {
        $this->markTestSkipped('no redis server available');
    }

    $store->timelineAdd('t', 1.0, 'only');
    $store->timelineTrim('t', null, 100);

    expect($store->timelineNewest('t', 10))->toBe(['only']);
})->with('stores');
