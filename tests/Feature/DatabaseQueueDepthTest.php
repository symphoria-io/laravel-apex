<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Symphoria\Apex\Ipc\Depth\DatabaseQueueDepthReader;
use Symphoria\Apex\Support\ApexConfig;

beforeEach(function () {
    config()->set('queue.default', 'primary');
    config()->set('queue.connections.primary', ['driver' => 'database', 'queue' => 'default', 'table' => 'jobs']);

    $this->createJobsTable();
});

function pushJob(string $queue, int $ageSeconds = 0, ?int $reservedAt = null, int $availableIn = 0): void
{
    DB::table('jobs')->insert([
        'queue' => $queue,
        'payload' => '{}',
        'attempts' => 0,
        'reserved_at' => $reservedAt,
        'available_at' => time() + $availableIn,
        'created_at' => time() - $ageSeconds,
    ]);
}

it('reports zero for an empty queue', function () {
    $reader = new DatabaseQueueDepthReader(ApexConfig::fromConfig());

    expect($reader->depth(['default']))->toBe(0)
        ->and($reader->oldestWaitSeconds(['default']))->toBeNull();
});

it('counts waiting jobs per queue', function () {
    pushJob('default');
    pushJob('default');
    pushJob('mail');

    $reader = new DatabaseQueueDepthReader(ApexConfig::fromConfig());

    expect($reader->depth(['default']))->toBe(2)
        ->and($reader->depth(['mail']))->toBe(1)
        ->and($reader->depth(['default', 'mail']))->toBe(3);
});

it('ignores a job that is actively being worked on', function () {
    // Work in progress is not backlog; counting it would keep the master
    // scaling up for jobs that are being handled.
    pushJob('default');
    pushJob('default', reservedAt: time());

    $reader = new DatabaseQueueDepthReader(ApexConfig::fromConfig());

    expect($reader->depth(['default']))->toBe(1);
});

it('counts a job whose reservation has expired', function () {
    // A crashed worker leaves its job reserved. Laravel hands that job to the
    // next worker once retry_after has passed, so it is backlog again — and on
    // a queue with min_processes 0 there is nobody left to be that worker
    // unless the master can see it.
    config()->set('queue.connections.primary.retry_after', 90);

    pushJob('default', ageSeconds: 200, reservedAt: time() - 120);

    $reader = new DatabaseQueueDepthReader(ApexConfig::fromConfig());

    expect($reader->depth(['default']))->toBe(1)
        ->and($reader->oldestWaitSeconds(['default']))->toBeGreaterThanOrEqual(200);
});

it('follows the connection retry_after when deciding a reservation lapsed', function () {
    config()->set('queue.connections.primary.retry_after', 5);

    pushJob('default', reservedAt: time() - 10);

    expect((new DatabaseQueueDepthReader(ApexConfig::fromConfig()))->depth(['default']))->toBe(1);

    config()->set('queue.connections.primary.retry_after', 3600);

    expect((new DatabaseQueueDepthReader(ApexConfig::fromConfig()))->depth(['default']))->toBe(0);
});

it('still ignores a job that is not available yet even when unreserved', function () {
    pushJob('default', availableIn: 600);

    expect((new DatabaseQueueDepthReader(ApexConfig::fromConfig()))->depth(['default']))->toBe(0);
});

it('ignores jobs that are not available yet', function () {
    pushJob('default', availableIn: 600);

    $reader = new DatabaseQueueDepthReader(ApexConfig::fromConfig());

    expect($reader->depth(['default']))->toBe(0);
});

it('reports the age of the oldest waiting job', function () {
    pushJob('default', ageSeconds: 5);
    pushJob('default', ageSeconds: 90);

    $reader = new DatabaseQueueDepthReader(ApexConfig::fromConfig());

    expect($reader->oldestWaitSeconds(['default']))->toBeGreaterThanOrEqual(90);
});

it('takes the oldest across a queue group', function () {
    pushJob('fast', ageSeconds: 2);
    pushJob('slow', ageSeconds: 120);

    $reader = new DatabaseQueueDepthReader(ApexConfig::fromConfig());

    expect($reader->oldestWaitSeconds(['fast', 'slow']))->toBeGreaterThanOrEqual(120);
});

it('memoizes within a tick and refreshes after a reset', function () {
    $reader = new DatabaseQueueDepthReader(ApexConfig::fromConfig());

    expect($reader->depth(['default']))->toBe(0);

    pushJob('default');

    // Still the memoized answer: the master reads the same queue three or
    // four times per tick and must see one consistent picture.
    expect($reader->depth(['default']))->toBe(0);

    $reader->resetCache();

    expect($reader->depth(['default']))->toBe(1);
});

it('warms every queue in a group through prefetch', function () {
    pushJob('a');
    pushJob('b');

    $reader = new DatabaseQueueDepthReader(ApexConfig::fromConfig());
    $reader->prefetch([['a', 'b'], ['c']]);

    DB::table('jobs')->delete();

    // Answered from the prefetched cache, not the (now empty) table.
    expect($reader->depth(['a']))->toBe(1)
        ->and($reader->depth(['b']))->toBe(1)
        ->and($reader->depth(['c']))->toBe(0);
});
