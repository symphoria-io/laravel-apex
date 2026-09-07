<?php

declare(strict_types=1);

use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Symphoria\Apex\Contracts\ApexStore;
use Symphoria\Apex\Ipc\ControlChannel;
use Symphoria\Apex\Ipc\WakeSignal;
use Symphoria\Apex\Providers\ApexServiceProvider;
use Symphoria\Apex\Support\ApexConfig;
use Symphoria\Apex\Support\LaravelPauseSuspensionSource;
use Symphoria\Apex\Tests\Fixtures\NoopJob;

beforeEach(function () {
    config()->set('queue.default', 'primary');
    config()->set('queue.connections.primary', ['driver' => 'database', 'queue' => 'default', 'table' => 'jobs']);
});

it('writes the wake key once per burst instead of once per job', function () {
    // Bus::bulk() of ten thousand jobs used to mean ten thousand writes, and
    // on the database store every one of those is a query.
    $store = new CountingStore(app(ApexStore::class));
    $wake = new WakeSignal(ApexConfig::fromConfig(), $store);

    foreach (range(1, 500) as $ignored) {
        $wake->mark();
    }

    expect($store->writes)->toBe(1)
        ->and($wake->isSet())->toBeTrue();
});

it('writes again after the key is consumed', function () {
    $wake = app(WakeSignal::class);

    $wake->mark();
    expect($wake->isSet())->toBeTrue();

    $wake->clear();
    expect($wake->isSet())->toBeFalse();

    $wake->mark();
    expect($wake->isSet())->toBeTrue();
});

it('refreshes the key well before it expires', function () {
    config()->set('apex.master.wake_ttl_seconds', 2);

    $store = new CountingStore(app(ApexStore::class));
    $wake = new WakeSignal(ApexConfig::fromConfig(), $store);

    $wake->mark();
    $wake->mark();

    expect($store->writes)->toBe(1);

    // Past half the TTL, so a dispatch burst spanning the window cannot leave
    // the master blind.
    sleep(2);
    $wake->mark();

    expect($store->writes)->toBe(2);
});

it('marks the wake key when a job is dispatched', function () {
    $this->createJobsTable();

    expect(app(WakeSignal::class)->isSet())->toBeFalse();

    Queue::push(new NoopJob);

    expect(app(WakeSignal::class)->isSet())->toBeTrue();
});

it('still wakes the master when recent-job tracking is switched off', function () {
    // recent_jobs governs what the dashboard shows. Turning a reporting
    // feature off used to take the wake listener with it, so an idle master
    // stopped noticing dispatches and only scaled up at the next full tick.
    config()->set('apex.recent_jobs.track_queued', false);

    Event::forget(JobQueued::class);

    $provider = new ApexServiceProvider(app());
    (new ReflectionMethod($provider, 'registerWakeSignal'))->invoke($provider);
    (new ReflectionMethod($provider, 'registerQueuedJobTracking'))->invoke($provider);

    $this->createJobsTable();

    Queue::push(new NoopJob);

    expect(app(WakeSignal::class)->isSet())->toBeTrue();
});

it('wakes the master when a queue is resumed', function () {
    // Nothing is dispatched when an operator lifts a pause, so without this
    // the backlog that piled up waits for the periodic full tick.
    $control = app(ControlChannel::class);

    $control->pauseQueue('mail');
    app(WakeSignal::class)->clear();

    $control->continueQueue('mail');

    expect(app(WakeSignal::class)->isSet())->toBeTrue();
});

it('wakes the master when a suspended queue is released', function () {
    $control = app(ControlChannel::class);

    $control->suspendQueue('mail');
    app(WakeSignal::class)->clear();

    $control->resumeQueue('mail');

    expect(app(WakeSignal::class)->isSet())->toBeTrue();
});

it('reports nothing suspended when no queue is paused', function () {
    config()->set('apex.queues', ['mail' => ['queues' => ['mail']]]);

    expect((new LaravelPauseSuspensionSource(ApexConfig::fromConfig()))->suspendedQueues())->toBe([]);
});

it('suspends a group once laravel pauses its queue', function () {
    config()->set('apex.queues', ['mail' => ['queues' => ['mail']], 'chat' => ['queues' => ['chat']]]);

    Queue::pause('primary', 'mail');

    expect((new LaravelPauseSuspensionSource(ApexConfig::fromConfig()))->suspendedQueues())->toBe(['mail']);
});

it('only suspends a multi-queue group when every queue is paused', function () {
    config()->set('apex.queues', ['chat' => ['queues' => ['chat', 'chat-priority']]]);

    Queue::pause('primary', 'chat');

    // Work is still arriving on chat-priority; scaling to zero would strand it.
    expect((new LaravelPauseSuspensionSource(ApexConfig::fromConfig()))->suspendedQueues())->toBe([]);

    Queue::pause('primary', 'chat-priority');

    expect((new LaravelPauseSuspensionSource(ApexConfig::fromConfig()))->suspendedQueues())->toBe(['chat']);
});

it('suspends everything on queue:pause --all', function () {
    config()->set('apex.queues', ['mail' => ['queues' => ['mail']], 'chat' => ['queues' => ['chat']]]);

    Queue::pauseAll();

    expect((new LaravelPauseSuspensionSource(ApexConfig::fromConfig()))->suspendedQueues())
        ->toEqualCanonicalizing(['mail', 'chat']);
});

/**
 * Counts writes so the throttle can be asserted on rather than inferred.
 */
final class CountingStore implements ApexStore
{
    public int $writes = 0;

    public function __construct(private readonly ApexStore $inner) {}

    public function put(string $key, string $value, ?int $ttlSeconds = null): void
    {
        $this->writes++;

        $this->inner->put($key, $value, $ttlSeconds);
    }

    public function add(string $key, string $value, ?int $ttlSeconds = null): bool
    {
        return $this->inner->add($key, $value, $ttlSeconds);
    }

    public function get(string $key): ?string
    {
        return $this->inner->get($key);
    }

    public function forget(string $key): void
    {
        $this->inner->forget($key);
    }

    public function exists(string $key): bool
    {
        return $this->inner->exists($key);
    }

    public function many(array $keys): array
    {
        return $this->inner->many($keys);
    }

    public function existsMany(array $keys): array
    {
        return $this->inner->existsMany($keys);
    }

    public function keys(string $prefix): array
    {
        return $this->inner->keys($prefix);
    }

    public function increment(string $key, int $by = 1, ?int $ttlSeconds = null): int
    {
        return $this->inner->increment($key, $by, $ttlSeconds);
    }

    public function hashPut(string $key, string $field, string $value): void
    {
        $this->inner->hashPut($key, $field, $value);
    }

    public function hashGet(string $key, string $field): ?string
    {
        return $this->inner->hashGet($key, $field);
    }

    public function hashForget(string $key, array $fields): void
    {
        $this->inner->hashForget($key, $fields);
    }

    public function hashAll(string $key): array
    {
        return $this->inner->hashAll($key);
    }

    public function timelineAdd(string $key, float $score, string $value): void
    {
        $this->inner->timelineAdd($key, $score, $value);
    }

    public function timelineNewest(string $key, int $limit): array
    {
        return $this->inner->timelineNewest($key, $limit);
    }

    public function timelineSince(string $key, float $exclusiveMin, int $limit): array
    {
        return $this->inner->timelineSince($key, $exclusiveMin, $limit);
    }

    public function timelineTrim(string $key, ?float $minScore, ?int $maxEntries): void
    {
        $this->inner->timelineTrim($key, $minScore, $maxEntries);
    }

    public function sweep(): int
    {
        return $this->inner->sweep();
    }
}
