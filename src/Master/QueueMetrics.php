<?php

namespace Symphoria\Apex\Master;

use InvalidArgumentException;

class QueueMetrics
{
    public function __construct(
        public readonly int $depth,
        public readonly ?int $oldestWaitSeconds,
        public readonly int $jobsProcessedRecent,
    ) {
        if ($depth < 0) {
            throw new InvalidArgumentException('depth must be >= 0');
        }
    }
}
