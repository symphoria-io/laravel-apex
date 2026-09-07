<?php

declare(strict_types=1);

namespace Symphoria\Apex\Tests\Fixtures;

use Symphoria\Apex\Models\QueueState;

/**
 * Stands in for the model a consumer puts in config. Proves that model
 * overriding actually works rather than merely being promised.
 */
class CustomQueueState extends QueueState
{
    public function shout(): string
    {
        return strtoupper($this->queue_name);
    }
}
