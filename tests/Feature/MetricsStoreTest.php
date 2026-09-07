<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Redis;
use Symphoria\Apex\Contracts\ApexStore;
use Symphoria\Apex\Ipc\MetricsStore;
use Symphoria\Apex\Ipc\Stores\DatabaseStore;
use Symphoria\Apex\Ipc\Stores\RedisStore;
use Symphoria\Apex\Support\ApexConfig;

/**
 * The dashboard reads everything through MetricsStore. If these pass on the
 * database store, "no Redis" means a working dashboard rather than one that
 * merely boots.
 */
beforeEach(function () {
    config()->set('queue.default', 'primary');
    config()->set('queue.connections.primary', ['driver' => 'database', 'queue' => 'default']);

    $this->metrics = new MetricsStore(ApexConfig::fromConfig(), new DatabaseStore(ApexConfig::fromConfig()));
});

it('uses the database store when there is no redis queue', function () {
    expect(app(ApexStore::class))->toBeInstanceOf(DatabaseStore::class);
});

it('round-trips the master snapshot', function () {
    expect($this->metrics->readSnapshot())->toBeNull();

    $this->metrics->writeSnapshot(['workers' => 3]);

    $snapshot = $this->metrics->readSnapshot();

    expect($snapshot['workers'])->toBe(3)
        ->and($snapshot)->toHaveKey('generated_at');
});

it('counts processed jobs per queue and in total', function () {
    $this->metrics->recordJob('mail');
    $this->metrics->recordJob('mail');
    $this->metrics->recordJob('chat');

    expect($this->metrics->processedTotal('mail'))->toBe(2)
        ->and($this->metrics->processedTotal('chat'))->toBe(1)
        ->and($this->metrics->processedRecent('mail'))->toBe(2)
        ->and($this->metrics->processedRecentMany(['mail', 'chat', 'idle']))
        ->toBe(['mail' => 2, 'chat' => 1, 'idle' => 0]);
});

it('tracks a job through queued, processing and finished', function () {
    $this->metrics->recordJobQueued(['uuid' => 'u1', 'name' => 'SendMail', 'queue' => 'mail']);

    $inflight = $this->metrics->inflightJobs();
    expect($inflight)->toHaveCount(1)
        ->and($inflight[0]['status'])->toBe('queued');

    $this->metrics->markJobProcessing('u1', ['queue' => 'mail']);

    $inflight = $this->metrics->inflightJobs();
    expect($inflight[0]['status'])->toBe('processing')
        // The queued_at from the dispatch entry has to survive the merge, or
        // the dashboard cannot show time-in-queue.
        ->and($inflight[0])->toHaveKey('queued_at');

    $this->metrics->removeInflight('u1');
    $this->metrics->recordRecentJob([
        'uuid' => 'u1',
        'name' => 'SendMail',
        'queue' => 'mail',
        'apex_queue' => 'mail',
        'status' => 'completed',
        'runtime_ms' => 12,
        'finished_at' => microtime(true),
    ]);

    expect($this->metrics->inflightJobs())->toBeEmpty()
        ->and($this->metrics->recentJobs())->toHaveCount(1)
        ->and($this->metrics->recentJobs(status: 'failed'))->toBeEmpty()
        ->and($this->metrics->lastJob('mail')['status'])->toBe('completed');
});

it('merges in-flight and finished jobs without duplicating one', function () {
    $now = microtime(true);

    $this->metrics->recordJobQueued(['uuid' => 'u1', 'queue' => 'mail']);
    $this->metrics->recordJobQueued(['uuid' => 'u2', 'queue' => 'mail']);
    $this->metrics->recordRecentJob(['uuid' => 'u1', 'queue' => 'mail', 'status' => 'completed', 'finished_at' => $now]);

    $merged = $this->metrics->recentJobsMerged();

    expect($merged)->toHaveCount(2)
        ->and(array_column($merged, 'uuid'))->toEqualCanonicalizing(['u1', 'u2']);
});

it('reads the live tail forward from a cursor', function () {
    $base = microtime(true);

    $this->metrics->recordRecentJob(['uuid' => 'a', 'queue' => 'mail', 'finished_at' => $base + 1]);
    $this->metrics->recordRecentJob(['uuid' => 'b', 'queue' => 'mail', 'finished_at' => $base + 2]);

    expect(array_column($this->metrics->recentJobsSince($base), 'uuid'))->toBe(['a', 'b'])
        ->and(array_column($this->metrics->recentJobsSince($base + 1), 'uuid'))->toBe(['b'])
        ->and($this->metrics->recentJobsSince($base + 2))->toBeEmpty();
});

it('keeps job starts in their own buffer', function () {
    $base = microtime(true);

    $this->metrics->recordRecentJobStart(['uuid' => 'a', 'started_at' => $base + 1]);

    expect(array_column($this->metrics->recentJobStartsSince($base), 'uuid'))->toBe(['a'])
        ->and($this->metrics->recentJobsSince($base))->toBeEmpty();
});

it('records worker lifecycle events and filters them', function () {
    $this->metrics->recordWorkerEvent(['type' => 'spawned', 'queue' => 'mail', 'at' => microtime(true)]);
    $this->metrics->recordWorkerEvent(['type' => 'reaped', 'queue' => 'chat', 'at' => microtime(true)]);

    expect($this->metrics->workerEvents())->toHaveCount(2)
        ->and($this->metrics->workerEvents(queue: 'mail'))->toHaveCount(1)
        ->and($this->metrics->workerEvents(type: 'reaped'))->toHaveCount(1);
});

it('drops recent jobs past the configured cap', function () {
    config()->set('apex.recent_jobs.max_entries', 3);

    // ApexConfig snapshots config on construction, so the store has to be
    // built after the override.
    $config = ApexConfig::fromConfig();
    $metrics = new MetricsStore($config, new DatabaseStore($config));

    $base = microtime(true);
    foreach (range(1, 5) as $i) {
        $metrics->recordRecentJob(['uuid' => 'u'.$i, 'queue' => 'mail', 'finished_at' => $base + $i]);
    }

    expect(array_column($metrics->recentJobs(), 'uuid'))->toBe(['u5', 'u4', 'u3']);
});

it('summarises many queues in one read', function () {
    $this->metrics->recordJob('mail');
    $this->metrics->recordRecentJob([
        'uuid' => 'u1',
        'queue' => 'mail',
        'apex_queue' => 'mail',
        'status' => 'completed',
        'finished_at' => microtime(true),
    ]);

    $summaries = $this->metrics->queueSummaries(['mail', 'chat']);

    expect($summaries['mail']['processed_total'])->toBe(1)
        ->and($summaries['mail']['last_job']['status'])->toBe('completed')
        ->and($summaries['chat'])->toBe(['processed_total' => 0, 'last_job' => null]);
});

it('produces the same results on redis', function () {
    $config = ApexConfig::fromConfig();

    try {
        Redis::connection($config->connection())->flushdb();
    } catch (Throwable) {
        $this->markTestSkipped('no redis server available');
    }

    $metrics = new MetricsStore($config, new RedisStore($config));

    $metrics->recordJob('mail');
    $metrics->recordJobQueued(['uuid' => 'u1', 'queue' => 'mail']);
    $metrics->recordRecentJob([
        'uuid' => 'u2',
        'queue' => 'mail',
        'apex_queue' => 'mail',
        'status' => 'completed',
        'finished_at' => microtime(true),
    ]);

    expect($metrics->processedTotal('mail'))->toBe(1)
        ->and($metrics->inflightJobs())->toHaveCount(1)
        ->and($metrics->recentJobs())->toHaveCount(1)
        ->and($metrics->queueSummaries(['mail'])['mail']['last_job']['status'])->toBe('completed');
});
