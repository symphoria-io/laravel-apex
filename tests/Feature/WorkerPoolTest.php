<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Symphoria\Apex\Console\Commands\ApexWorkCommand;
use Symphoria\Apex\Ipc\ControlChannel;
use Symphoria\Apex\Queue\ApexRedisQueue;
use Symphoria\Apex\Queue\ApexWorker;
use Symphoria\Apex\Support\ApexConfig;

/**
 * The worker side of the supervisor: what it reads, and whether it waits.
 *
 * Both bugs covered here were invisible in production logs. A worker that
 * skips its sleep looks healthy while it hammers Redis, and a worker draining
 * a queue an operator paused looks like the pause never took.
 */
beforeEach(function () {
    config()->set('queue.default', 'primary');
    config()->set('queue.connections.primary', ['driver' => 'redis', 'queue' => 'default']);

    // The queue driver is redis here so the BLMPOP path is reachable, but the
    // store must not follow it: these tests would otherwise write control keys
    // into a real Redis and leak them into each other.
    config()->set('apex.store', 'database');
});

function apexWorker(): ApexWorker
{
    $worker = app('queue.worker');

    expect($worker)->toBeInstanceOf(ApexWorker::class);

    return $worker;
}

function popOnce(ApexWorker $worker, ApexRedisQueue $connection, string $queue): mixed
{
    $method = new ReflectionMethod($worker, 'getNextJob');

    return $method->invoke($worker, $connection, $queue);
}

function skipsLaravelSleep(ApexWorker $worker): bool
{
    return (bool) (new ReflectionProperty($worker, 'blmpopActive'))->getValue($worker);
}

it('hands the idle wait back to laravel when blmpop never blocked', function () {
    // Redis 7 is present, so the worker opts into BLMPOP and stops sleeping.
    // If the client then turns out not to support it, the pop returns at once
    // and the loop spins at full speed against a queue with nothing in it.
    $connection = new FakeApexRedisQueue;
    $connection->blocked = false;

    $worker = apexWorker();

    expect(popOnce($worker, $connection, 'chat'))->toBeNull()
        ->and($connection->popCalls)->toBe(1)
        ->and(skipsLaravelSleep($worker))->toBeFalse();
});

it('keeps skipping the sleep while blmpop really blocks', function () {
    $connection = new FakeApexRedisQueue;
    $connection->blocked = true;

    $worker = apexWorker();

    expect(popOnce($worker, $connection, 'chat'))->toBeNull()
        ->and(skipsLaravelSleep($worker))->toBeTrue();
});

it('falls back to laravel once the queue reports blmpop is unusable', function () {
    $connection = new FakeApexRedisQueue;
    $connection->supports = false;

    $worker = apexWorker();

    popOnce($worker, $connection, 'chat');

    expect($connection->popCalls)->toBe(0)
        ->and(skipsLaravelSleep($worker))->toBeFalse();
});

it('actually sleeps when it is not blocking on redis', function () {
    $worker = apexWorker();
    (new ReflectionProperty($worker, 'blmpopActive'))->setValue($worker, false);

    $started = microtime(true);
    $worker->sleep(0.05);
    $elapsed = microtime(true) - $started;

    // Loose bound on purpose: usleep resolution varies per platform, and the
    // thing under test is that it waits at all rather than for how long.
    expect($elapsed)->toBeGreaterThan(0.01);

    (new ReflectionProperty($worker, 'blmpopActive'))->setValue($worker, true);

    $started = microtime(true);
    $worker->sleep(0.05);

    expect(microtime(true) - $started)->toBeLessThan($elapsed);
});

it('stops reading a queue whose group was stopped', function () {
    $connection = new FakeApexRedisQueue;
    $connection->blocked = true;

    $worker = apexWorker();
    $worker->filterQueuesUsing(fn (array $queues): array => array_values(array_diff($queues, ['chat'])));

    popOnce($worker, $connection, 'chat,mail');

    // A flex pool reads queues it does not own. Pausing `chat` has to reach it
    // too, or the pause only stops the dedicated pool.
    expect($connection->lastQueues)->toBe(['mail']);
});

it('idles instead of spinning when every queue it reads is stopped', function () {
    $connection = new FakeApexRedisQueue;

    $worker = apexWorker();
    $worker->filterQueuesUsing(fn (): array => []);

    expect(popOnce($worker, $connection, 'chat'))->toBeNull()
        ->and($connection->popCalls)->toBe(0)
        ->and(skipsLaravelSleep($worker))->toBeFalse();
});

it('honours laravel queue:pause on the blmpop path', function () {
    $connection = new FakeApexRedisQueue;
    $connection->blocked = true;

    $worker = apexWorker();
    // WorkCommand wires this up in the real flow; without it the framework
    // reports nothing as paused and the assertion would pass for the wrong reason.
    $worker->setCache(app('cache.store'));

    Queue::pause('primary', 'chat');

    popOnce($worker, $connection, 'chat,mail');

    expect($connection->lastQueues)->toBe(['mail']);
});

it('writes the pause key per group, not per queue', function () {
    config()->set('apex.queues', ['reports' => ['queues' => ['reports', 'reports-bulk']]]);

    $control = app(ControlChannel::class);
    $control->pauseQueue('reports');

    // The worker used to compare its `--queue` names against these keys and
    // quit only when every one matched, which for this group was never.
    expect($control->isQueueStopped('reports'))->toBeTrue()
        ->and($control->isQueueStopped('reports-bulk'))->toBeFalse();
});

it('expands a stopped group into the queue names its workers must skip', function () {
    config()->set('apex.queues', [
        'chat' => ['queues' => ['chat', 'chat-priority']],
        'flex' => ['queues' => ['chat', 'mail'], 'is_flex' => true],
    ]);

    app(ControlChannel::class)->pauseQueue('chat');

    $command = new ApexWorkCommand(app('queue.worker'), app('cache.store'));

    (new ReflectionProperty($command, 'apexConfig'))->setValue($command, ApexConfig::fromConfig());
    (new ReflectionProperty($command, 'control'))->setValue($command, app(ControlChannel::class));
    (new ReflectionMethod($command, 'refreshStoppedQueues'))->invoke($command);

    $stopped = (new ReflectionProperty($command, 'stoppedQueues'))->getValue($command);

    expect($stopped)->toEqualCanonicalizing(['chat', 'chat-priority']);
});

it('leaves every queue readable when nothing is stopped', function () {
    config()->set('apex.queues', ['chat' => ['queues' => ['chat']]]);

    $command = new ApexWorkCommand(app('queue.worker'), app('cache.store'));

    (new ReflectionProperty($command, 'apexConfig'))->setValue($command, ApexConfig::fromConfig());
    (new ReflectionProperty($command, 'control'))->setValue($command, app(ControlChannel::class));
    (new ReflectionMethod($command, 'refreshStoppedQueues'))->invoke($command);

    expect((new ReflectionProperty($command, 'stoppedQueues'))->getValue($command))->toBe([]);
});

/**
 * Stands in for a real Redis-backed queue so the fallback decisions can be
 * driven directly. Skips the parent constructor: none of the state it sets up
 * is reachable from the methods under test.
 */
final class FakeApexRedisQueue extends ApexRedisQueue
{
    public bool $supports = true;

    public bool $blocked = false;

    public int $popCalls = 0;

    /** @var list<string> */
    public array $lastQueues = [];

    public function __construct() {}

    public function supportsBlmpop(): bool
    {
        return $this->supports;
    }

    public function lastCallBlocked(): bool
    {
        return $this->blocked;
    }

    public function popFromMultiple(array $queues, float $timeoutSeconds): ?array
    {
        $this->popCalls++;
        $this->lastQueues = array_values($queues);

        return null;
    }

    public function getConnectionName()
    {
        return 'primary';
    }
}
