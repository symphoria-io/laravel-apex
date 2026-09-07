<?php

namespace Symphoria\Apex\Master\Profiles;

use Symphoria\Apex\Master\QueueMetrics;
use Symphoria\Apex\Master\ScalingStrategy;

class WaitStrategy implements ScalingStrategy
{
    public function demand(array $queueConfig, QueueMetrics $metrics): int
    {
        if ($metrics->depth === 0 || $metrics->oldestWaitSeconds === null) {
            return 0;
        }

        $target = max(1, (int) ($queueConfig['target_wait_seconds'] ?? 2));

        if ($metrics->oldestWaitSeconds <= $target) {
            return 1;
        }

        $factor = $metrics->oldestWaitSeconds / $target;

        return max(1, (int) ceil($factor));
    }
}
