<?php

namespace Symphoria\Apex\Master\Profiles;

use Symphoria\Apex\Master\QueueMetrics;
use Symphoria\Apex\Master\ScalingStrategy;

class HybridStrategy implements ScalingStrategy
{
    public function demand(array $queueConfig, QueueMetrics $metrics): int
    {
        $depth = (new DepthStrategy)->demand($queueConfig, $metrics);
        $wait = (new WaitStrategy)->demand($queueConfig, $metrics);

        return max($depth, $wait);
    }
}
