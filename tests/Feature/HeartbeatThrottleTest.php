<?php

declare(strict_types=1);

use Symphoria\Apex\Ipc\HeartbeatStore;
use Symphoria\Apex\Ipc\Stores\DatabaseStore;
use Symphoria\Apex\Support\ApexConfig;

beforeEach(function () {
    config()->set('queue.default', 'primary');
    config()->set('queue.connections.primary', ['driver' => 'database', 'queue' => 'default', 'table' => 'jobs']);
});

it('writes every state change', function () {
    $store = new DatabaseStore(ApexConfig::fromConfig());
    $heartbeats = new HeartbeatStore(ApexConfig::fromConfig(), $store);

    $heartbeats->write('w1', ['state' => 'booted']);
    expect($heartbeats->read('w1')['state'])->toBe('booted');

    $heartbeats->write('w1', ['state' => 'working']);
    expect($heartbeats->read('w1')['state'])->toBe('working');

    $heartbeats->write('w1', ['state' => 'waiting']);
    expect($heartbeats->read('w1')['state'])->toBe('waiting');
});

it('collapses repeated writes of the same state', function () {
    // A worker doing 50 jobs a second emits three heartbeats per job, all
    // saying 'working'. Writing each of them is the single largest source of
    // store traffic Apex generates.
    config()->set('apex.master.heartbeat_min_write_interval_ms', 60_000);

    $store = new DatabaseStore(ApexConfig::fromConfig());
    $heartbeats = new HeartbeatStore(ApexConfig::fromConfig(), $store);

    $heartbeats->write('w1', ['state' => 'working', 'jobs' => 1]);
    $heartbeats->write('w1', ['state' => 'working', 'jobs' => 2]);
    $heartbeats->write('w1', ['state' => 'working', 'jobs' => 3]);

    expect($heartbeats->read('w1')['jobs'])->toBe(1);
});

it('writes a repeat once the interval has passed', function () {
    config()->set('apex.master.heartbeat_min_write_interval_ms', 0);

    $store = new DatabaseStore(ApexConfig::fromConfig());
    $heartbeats = new HeartbeatStore(ApexConfig::fromConfig(), $store);

    $heartbeats->write('w1', ['state' => 'working', 'jobs' => 1]);
    $heartbeats->write('w1', ['state' => 'working', 'jobs' => 2]);

    expect($heartbeats->read('w1')['jobs'])->toBe(2);
});

it('always writes when forced', function () {
    config()->set('apex.master.heartbeat_min_write_interval_ms', 60_000);

    $store = new DatabaseStore(ApexConfig::fromConfig());
    $heartbeats = new HeartbeatStore(ApexConfig::fromConfig(), $store);

    $heartbeats->write('w1', ['state' => 'working', 'jobs' => 1]);
    $heartbeats->write('w1', ['state' => 'working', 'jobs' => 2], force: true);

    expect($heartbeats->read('w1')['jobs'])->toBe(2);
});

it('reads many heartbeats and reports each worker id', function () {
    $store = new DatabaseStore(ApexConfig::fromConfig());
    $heartbeats = new HeartbeatStore(ApexConfig::fromConfig(), $store);

    $heartbeats->write('w1', ['state' => 'working'], force: true);
    $heartbeats->write('w2', ['state' => 'waiting'], force: true);

    $all = $heartbeats->many(['w1', 'w2', 'gone']);

    expect($all)->toHaveCount(2)
        ->and($all['w1']['apex_id'])->toBe('w1')
        ->and($all['w2']['state'])->toBe('waiting');

    expect($heartbeats->all())->toHaveCount(2);

    $heartbeats->delete('w1');
    expect($heartbeats->all())->toHaveCount(1);
});
