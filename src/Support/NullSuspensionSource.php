<?php

declare(strict_types=1);

namespace Symphoria\Apex\Support;

use Symphoria\Apex\Contracts\QueueSuspensionSource;

/**
 * Default binding: nothing is suspended, so Apex works out of the box in an
 * application that has no opinion about it.
 */
final class NullSuspensionSource implements QueueSuspensionSource
{
    public function suspendedQueues(): array
    {
        return [];
    }
}
