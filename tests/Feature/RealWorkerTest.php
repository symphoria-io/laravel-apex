<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Symphoria\Apex\Ipc\HeartbeatStore;
use Symphoria\Apex\Ipc\Stores\DatabaseStore;
use Symphoria\Apex\Process\ProcessFactory;
use Symphoria\Apex\Support\ApexConfig;
use Symphoria\Apex\Tests\Fixtures\NoopJob;

/**
 * The one test that runs a real worker.
 *
 * Everything else drives the pop and pause decisions against a stand-in
 * connection, which cannot catch the class of failure that matters most here:
 * `apex:work` extends Laravel's WorkCommand, so a signature that drifts out of
 * sync makes Symfony Console exit 1 before any of our code runs. The master
 * then sees a worker that died on boot and respawns it, forever.
 *
 * Needs a database on disk rather than in memory, because the worker is a
 * separate process and has to see the same rows.
 */
beforeEach(function () {
    $this->e2ePath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'apex-e2e-'.getmypid().'-'.uniqid().'.sqlite';
    touch($this->e2ePath);

    config([
        'database.connections.apex_e2e' => [
            'driver' => 'sqlite',
            'database' => $this->e2ePath,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ],
        'queue.default' => 'apex_e2e',
        'queue.connections.apex_e2e' => [
            'driver' => 'database',
            'connection' => 'apex_e2e',
            'table' => 'jobs',
            'queue' => 'default',
            'retry_after' => 90,
        ],
        'apex.database_connection' => 'apex_e2e',
        'apex.store' => 'database',
    ]);

    // Only the package's own migrations run here; the jobs table belongs to
    // the skeleton app, which this context does not load.
    Artisan::call('migrate', ['--database' => 'apex_e2e', '--force' => true]);
    $this->createJobsTable('jobs', 'apex_e2e');
});

afterEach(function () {
    if (isset($this->e2ePath) && is_file($this->e2ePath)) {
        @unlink($this->e2ePath);
    }
});

/**
 * The worker is a child process, so it reads its configuration from the
 * environment rather than from this test's container.
 */
function withWorkerEnvironment(string $databasePath, callable $callback): mixed
{
    $previous = [
        'DB_CONNECTION' => getenv('DB_CONNECTION'),
        'DB_DATABASE' => getenv('DB_DATABASE'),
        'QUEUE_CONNECTION' => getenv('QUEUE_CONNECTION'),
        'APEX_STORE' => getenv('APEX_STORE'),
        'CACHE_STORE' => getenv('CACHE_STORE'),
        'CACHE_DRIVER' => getenv('CACHE_DRIVER'),
    ];

    putenv('DB_CONNECTION=sqlite');
    putenv('DB_DATABASE='.$databasePath);
    putenv('QUEUE_CONNECTION=database');
    putenv('APEX_STORE=database');
    // Laravel's restart signal lives in the cache; nothing here tests it, and
    // a database cache would need a table this fixture does not carry.
    putenv('CACHE_STORE=array');
    putenv('CACHE_DRIVER=array');

    try {
        return $callback();
    } finally {
        foreach ($previous as $key => $value) {
            putenv($value === false ? $key : $key.'='.$value);
        }
    }
}

/**
 * base_path() is the test skeleton here, not the package, so the worker has to
 * be told where the application it should boot actually lives.
 */
function packageProcessFactory(): ProcessFactory
{
    $root = dirname(__DIR__, 2);

    return new ProcessFactory(
        artisanPath: $root.DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.'testbench',
        workingDirectory: $root,
    );
}

/**
 * @param  callable(): bool  $condition
 */
function waitUntil(callable $condition, float $seconds = 25.0): bool
{
    $deadline = microtime(true) + $seconds;

    while (microtime(true) < $deadline) {
        if ($condition()) {
            return true;
        }

        usleep(200_000);
    }

    return false;
}

it('runs a real worker that boots, reports itself and drains the queue', function () {
    Queue::push(new NoopJob);
    Queue::push(new NoopJob);

    expect(DB::connection('apex_e2e')->table('jobs')->count())->toBe(2);

    $processes = packageProcessFactory();
    $store = new DatabaseStore(ApexConfig::fromConfig());
    $heartbeats = new HeartbeatStore(ApexConfig::fromConfig(), $store);

    $worker = withWorkerEnvironment($this->e2ePath, fn () => $processes->spawnWorker(
        apexId: 'e2e-worker',
        queueName: 'default',
        queueConfig: [
            'queues' => ['default'],
            'idle_timeout_seconds' => 4,
            'max_time_seconds' => 45,
            'memory_mb' => 256,
            'timeout_seconds' => 30,
            'tries' => 1,
            'max_jobs' => 10,
            'sleep_seconds' => 0.3,
        ],
    ));

    try {
        // The heartbeat has to be observed while the worker lives: it deletes
        // its own entry on WorkerStopping, so afterwards there is nothing left
        // to assert on.
        $reportedItself = waitUntil(fn (): bool => $heartbeats->read('e2e-worker') !== null);

        $drained = waitUntil(fn (): bool => DB::connection('apex_e2e')->table('jobs')->count() === 0);

        expect($reportedItself)->toBeTrue('worker never wrote a heartbeat')
            ->and($drained)->toBeTrue('worker never drained the queue');
    } finally {
        $processes->kill($worker);
        $processes->close($worker);
    }
});

it('exits on its own once the queue stays empty', function () {
    $processes = packageProcessFactory();

    $worker = withWorkerEnvironment($this->e2ePath, fn () => $processes->spawnWorker(
        apexId: 'e2e-idler',
        queueName: 'default',
        queueConfig: [
            'queues' => ['default'],
            'idle_timeout_seconds' => 3,
            'max_time_seconds' => 45,
            'memory_mb' => 256,
            'timeout_seconds' => 30,
            'tries' => 1,
            'max_jobs' => 10,
            'sleep_seconds' => 0.3,
        ],
    ));

    try {
        // A worker that cannot retire itself leaves the master to do it, which
        // is the churn `floor_workers_stay_warm` exists to avoid.
        expect(waitUntil(fn (): bool => ! $processes->isAlive($worker), 40.0))
            ->toBeTrue('worker never reached its idle timeout');
    } finally {
        $processes->kill($worker);
        $processes->close($worker);
    }
});
