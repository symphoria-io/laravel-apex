<?php

declare(strict_types=1);

use Symphoria\Apex\Contracts\ApexStore;
use Symphoria\Apex\Contracts\QueueDepthProbe;
use Symphoria\Apex\Ipc\Depth\DatabaseQueueDepthReader;
use Symphoria\Apex\Ipc\Depth\RedisQueueDepthReader;
use Symphoria\Apex\Ipc\Stores\DatabaseStore;
use Symphoria\Apex\Ipc\Stores\RedisStore;
use Symphoria\Apex\Support\ApexConfig;

function withQueueDriver(string $driver): void
{
    config()->set('queue.default', 'primary');
    config()->set('queue.connections.primary', ['driver' => $driver, 'queue' => 'default']);
}

it('follows the queue driver when no store is configured', function () {
    withQueueDriver('redis');
    expect(ApexConfig::fromConfig()->store())->toBe('redis');

    withQueueDriver('database');
    expect(ApexConfig::fromConfig()->store())->toBe('database');
});

it('falls back to the database store for any driver that is not redis', function (string $driver) {
    withQueueDriver($driver);

    expect(ApexConfig::fromConfig()->store())->toBe('database');
})->with(['sync', 'sqs', 'beanstalkd', 'null']);

it('ignores the connection name and looks at the driver', function () {
    // A connection called 'redis' backed by something else is legal, and
    // deriving from the name would silently pick a store that is not there.
    config()->set('queue.default', 'redis');
    config()->set('queue.connections.redis', ['driver' => 'sync']);

    expect(ApexConfig::fromConfig()->store())->toBe('database');
});

it('lets an explicit store override the derivation', function () {
    withQueueDriver('redis');
    config()->set('apex.store', 'database');

    expect(ApexConfig::fromConfig()->store())->toBe('database');
});

it('rejects a store it has no configuration for', function () {
    config()->set('apex.store', 'memcached');

    expect(fn () => ApexConfig::fromConfig()->store())
        ->toThrow(InvalidArgumentException::class, 'Unknown Apex store [memcached]');
});

it('resolves the store implementation that matches', function () {
    withQueueDriver('database');
    expect(app(ApexStore::class))->toBeInstanceOf(DatabaseStore::class);

    app()->forgetInstance(ApexStore::class);
    app()->forgetInstance(ApexConfig::class);
    withQueueDriver('redis');

    expect(app(ApexStore::class))->toBeInstanceOf(RedisStore::class);
});

it('picks the depth probe from the queue driver, not the store', function () {
    // Apex state in the database while the queue runs on Redis is a valid
    // combination; the probe has to read jobs where the jobs actually are.
    withQueueDriver('redis');
    config()->set('apex.store', 'database');

    expect(app(ApexStore::class))->toBeInstanceOf(DatabaseStore::class)
        ->and(app(QueueDepthProbe::class))->toBeInstanceOf(RedisQueueDepthReader::class);
});

it('uses the database probe for a database queue', function () {
    withQueueDriver('database');

    expect(app(QueueDepthProbe::class))->toBeInstanceOf(DatabaseQueueDepthReader::class);
});

it('takes tick intervals from the active store when unset', function () {
    withQueueDriver('database');
    config()->set('apex.master.tick_interval_ms', null);
    config()->set('apex.master.idle_tick_interval_ms', null);

    $master = ApexConfig::fromConfig()->master();

    expect($master['tick_interval_ms'])->toBe(1000)
        ->and($master['idle_tick_interval_ms'])->toBe(5000);

    withQueueDriver('redis');
    $master = ApexConfig::fromConfig()->master();

    expect($master['tick_interval_ms'])->toBe(250)
        ->and($master['idle_tick_interval_ms'])->toBe(1000);
});

it('lets an explicit interval win over the store default', function () {
    withQueueDriver('database');
    config()->set('apex.master.tick_interval_ms', 100);

    expect(ApexConfig::fromConfig()->master()['tick_interval_ms'])->toBe(100);
});
