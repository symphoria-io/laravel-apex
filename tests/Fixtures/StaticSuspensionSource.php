<?php

declare(strict_types=1);

namespace Symphoria\Apex\Tests\Fixtures;

use Symphoria\Apex\Contracts\QueueSuspensionSource;

/**
 * Stands in for an application that suspends queues for its own reasons —
 * a disabled feature, a tenant over quota. Apex never learns what the reason
 * was.
 */
class StaticSuspensionSource implements QueueSuspensionSource
{
    /** @var list<string> */
    public static array $suspended = [];

    public function suspendedQueues(): array
    {
        return self::$suspended;
    }
}
