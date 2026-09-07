<?php

namespace Symphoria\Apex\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symphoria\Apex\Master\BootThrottle;
use Symphoria\Apex\Master\Clock;

class FakeClock implements Clock
{
    public function __construct(public float $time = 1000.0) {}

    public function now(): float
    {
        return $this->time;
    }

    public function advanceMs(int $ms): void
    {
        $this->time += $ms / 1000;
    }
}

class BootThrottleTest extends TestCase
{
    public function test_first_spawn_allowed(): void
    {
        $throttle = new BootThrottle(2, 100, new FakeClock);

        $this->assertTrue($throttle->canSpawn());
    }

    public function test_blocks_when_max_concurrent_reached(): void
    {
        $clock = new FakeClock;
        $throttle = new BootThrottle(2, 0, $clock);

        $throttle->noteSpawn(1);
        $throttle->noteSpawn(2);

        $this->assertFalse($throttle->canSpawn());
    }

    public function test_allows_after_booted(): void
    {
        $clock = new FakeClock;
        $throttle = new BootThrottle(2, 0, $clock);

        $throttle->noteSpawn(1);
        $throttle->noteSpawn(2);
        $throttle->noteBooted(1);

        $this->assertTrue($throttle->canSpawn());
    }

    public function test_throttles_by_spawn_interval(): void
    {
        $clock = new FakeClock;
        $throttle = new BootThrottle(10, 200, $clock);

        $throttle->noteSpawn(1);

        $this->assertFalse($throttle->canSpawn());

        $clock->advanceMs(199);
        $this->assertFalse($throttle->canSpawn());

        $clock->advanceMs(2);
        $this->assertTrue($throttle->canSpawn());
    }

    public function test_expires_stuck_boots(): void
    {
        $clock = new FakeClock;
        $throttle = new BootThrottle(2, 0, $clock);

        $throttle->noteSpawn(1);
        $clock->advanceMs(70_000);
        $throttle->noteSpawn(2);

        $expired = $throttle->expireStuckBoots(60);

        $this->assertSame([1], $expired);
        $this->assertSame(1, $throttle->bootingCount());
    }
}
