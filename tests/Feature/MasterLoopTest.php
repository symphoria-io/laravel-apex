<?php

declare(strict_types=1);

use Symphoria\Apex\Contracts\ApexStore;
use Symphoria\Apex\Contracts\QueueDepthProbe;
use Symphoria\Apex\Ipc\ActivityTracker;
use Symphoria\Apex\Ipc\ControlChannel;
use Symphoria\Apex\Ipc\HeartbeatStore;
use Symphoria\Apex\Ipc\MasterLock;
use Symphoria\Apex\Ipc\MetricsStore;
use Symphoria\Apex\Ipc\WakeSignal;
use Symphoria\Apex\Master\BootThrottle;
use Symphoria\Apex\Master\Master;
use Symphoria\Apex\Master\MetricsAggregator;
use Symphoria\Apex\Master\ProcessMemoryReader;
use Symphoria\Apex\Master\ScalingDecider;
use Symphoria\Apex\Master\SystemLoad;
use Symphoria\Apex\Master\WorkerHandle;
use Symphoria\Apex\Master\WorkerRegistry;
use Symphoria\Apex\Process\ProcessFactory;
use Symphoria\Apex\Support\ApexConfig;

/**
 * The control loop itself, which nothing covered before.
 *
 * Both behaviours here are about what happens when something else is broken:
 * the supervisor is the last thing that may fall over, because everything it
 * started outlives it.
 */
beforeEach(function () {
    config()->set('queue.default', 'primary');
    config()->set('queue.connections.primary', ['driver' => 'database', 'queue' => 'default', 'table' => 'jobs']);
    config()->set('apex.store', 'database');
    config()->set('apex.queues', ['chat' => ['queues' => ['chat'], 'min_processes' => 0, 'max_processes' => 1]]);
    config()->set('apex.master.tick_interval_ms', 10);
    config()->set('apex.master.idle_tick_interval_ms', 10);
    config()->set('apex.master.graceful_shutdown_seconds', 0);

    $this->createJobsTable();
});

function makeMaster(
    ProcessFactory $processes,
    WorkerRegistry $registry,
    MetricsStore $metrics,
    ControlChannel $control,
    ?ApexConfig $config = null,
    ?MasterLock $lock = null,
): Master {
    $real = ApexConfig::fromConfig();

    return new Master(
        config: $config ?? $real,
        processFactory: $processes,
        registry: $registry,
        bootThrottle: BootThrottle::fromConfig($real),
        decider: new ScalingDecider,
        depthReader: app(QueueDepthProbe::class),
        heartbeats: app(HeartbeatStore::class),
        control: $control,
        activity: app(ActivityTracker::class),
        metrics: $metrics,
        wake: app(WakeSignal::class),
        systemLoad: app(SystemLoad::class),
        aggregator: new MetricsAggregator($metrics),
        memoryReader: new ProcessMemoryReader,
        lock: $lock,
    );
}

it('survives a store that cannot write the snapshot', function () {
    // Reporting is not the supervisor's job. An unreachable store used to
    // throw straight out of the loop, killing the master and stranding every
    // worker it had started.
    $master = makeMaster(
        new SpyProcessFactory,
        new WorkerRegistry,
        new ExplodingMetricsStore(ApexConfig::fromConfig(), app(ApexStore::class)),
        new SelfStoppingControlChannel(ApexConfig::fromConfig(), app(ApexStore::class), app(WakeSignal::class)),
    );

    $master->run();
})->throwsNoExceptions();

it('kills a worker that ignored the graceful stop', function () {
    // close() blocks until the process is gone, so without an escalation the
    // grace period bounds nothing and one wedged worker hangs the master exit.
    $registry = new WorkerRegistry;
    $registry->add(new WorkerHandle(
        pid: 4242,
        queueName: 'chat',
        apexId: 'stuck-worker',
        startedAt: microtime(true),
        process: fopen('php://memory', 'r'),
    ));

    $processes = new SpyProcessFactory;

    $master = makeMaster(
        $processes,
        $registry,
        app(MetricsStore::class),
        new SelfStoppingControlChannel(ApexConfig::fromConfig(), app(ApexStore::class), app(WakeSignal::class)),
    );

    $master->run();

    expect($processes->terminated)->toContain(4242)
        ->and($processes->killed)->toContain(4242)
        ->and($processes->closed)->toContain(4242)
        ->and($registry->totalCount())->toBe(0);
});

it('stays stoppable while the snapshot keeps failing', function () {
    // The shutdown check must not sit behind the snapshot: if a broken store
    // could skip it, the control channel would stop being able to stop the
    // master at all.
    $control = new SelfStoppingControlChannel(ApexConfig::fromConfig(), app(ApexStore::class), app(WakeSignal::class));

    $master = makeMaster(
        new SpyProcessFactory,
        new WorkerRegistry,
        new ExplodingMetricsStore(ApexConfig::fromConfig(), app(ApexStore::class)),
        $control,
    );

    $master->run();

    expect($control->calls)->toBeGreaterThan(0);
});

it('shuts its workers down even when the loop throws', function () {
    $registry = new WorkerRegistry;
    $registry->add(new WorkerHandle(
        pid: 99,
        queueName: 'chat',
        apexId: 'doomed',
        startedAt: microtime(true),
        process: fopen('php://memory', 'r'),
    ));

    $processes = new SpyProcessFactory;

    $master = makeMaster(
        $processes,
        $registry,
        app(MetricsStore::class),
        new SelfStoppingControlChannel(ApexConfig::fromConfig(), app(ApexStore::class), app(WakeSignal::class)),
        new ExplodingConfig(config('apex')),
    );

    try {
        $master->run();
    } catch (RuntimeException) {
        // The throw is the point; what matters is what happened on the way out.
    }

    // Reaching terminate() at all means the finally ran: before this fix an
    // escaping throw left every worker orphaned, still holding its jobs.
    expect($processes->terminated)->toContain(99);
});

it('stands down and cleans up when another master takes the lock', function () {
    // Carrying on here means two supervisors scaling the same queues, neither
    // able to see the other's workers.
    config()->set('apex.master.lock_ttl_seconds', 5);

    $lock = new MasterLock(ApexConfig::fromConfig(), app(ApexStore::class));
    $lock->acquire();

    $registry = new WorkerRegistry;
    $registry->add(new WorkerHandle(
        pid: 7,
        queueName: 'chat',
        apexId: 'evicted',
        startedAt: microtime(true),
        process: fopen('php://memory', 'r'),
    ));

    $processes = new SpyProcessFactory;

    $master = makeMaster(
        $processes,
        $registry,
        app(MetricsStore::class),
        new NeverStoppingControlChannel(ApexConfig::fromConfig(), app(ApexStore::class), app(WakeSignal::class)),
        null,
        $lock,
    );

    // A replacement takes over, and the running master must notice on its next
    // refresh rather than keep scaling.
    (new MasterLock(ApexConfig::fromConfig(), app(ApexStore::class)))->acquire(force: true);
    (new ReflectionProperty($lock, 'lastRefreshAt'))->setValue($lock, microtime(true) - 10);

    $master->run();

    expect($lock->heldByThisProcess())->toBeFalse()
        ->and($processes->terminated)->toContain(7);
});

/**
 * Never asks for shutdown, so only losing the lock can end the loop.
 */
final class NeverStoppingControlChannel extends ControlChannel
{
    public function clearShutdown(): void {}

    public function isShutdownRequested(): bool
    {
        return false;
    }
}

/**
 * Records what the master asked of the OS without touching a real process.
 */
final class SpyProcessFactory extends ProcessFactory
{
    /** @var list<int> */
    public array $terminated = [];

    /** @var list<int> */
    public array $killed = [];

    /** @var list<int> */
    public array $closed = [];

    public function __construct() {}

    public function isAlive(WorkerHandle $handle): bool
    {
        return true;
    }

    public function terminate(WorkerHandle $handle): void
    {
        $this->terminated[] = $handle->pid;
    }

    public function kill(WorkerHandle $handle): void
    {
        $this->killed[] = $handle->pid;
    }

    public function close(WorkerHandle $handle): int
    {
        $this->closed[] = $handle->pid;

        return 0;
    }
}

final class ExplodingMetricsStore extends MetricsStore
{
    public function writeSnapshot(array $snapshot): void
    {
        throw new RuntimeException('store unreachable');
    }
}

/**
 * Lets the loop run exactly one full iteration, so a test does not depend on
 * a signal arriving.
 */
class SelfStoppingControlChannel extends ControlChannel
{
    public int $calls = 0;

    public function clearShutdown(): void {}

    public function isShutdownRequested(): bool
    {
        return ++$this->calls > 1;
    }
}

/**
 * Stands in for anything unexpected escaping the loop; the master reads its
 * intervals from here on the way in.
 */
final class ExplodingConfig extends ApexConfig
{
    public function master(): array
    {
        throw new RuntimeException('config gone');
    }
}
