<?php

namespace Symphoria\Apex\Master\Profiles;

use Symphoria\Apex\Master\QueueMetrics;
use Symphoria\Apex\Master\ScalingStrategy;

class DepthStrategy implements ScalingStrategy
{
    public function demand(array $queueConfig, QueueMetrics $metrics): int
    {
        if ($metrics->depth === 0) {
            return 0;
        }

        $jobsPerWorker = max(1, (int) ($queueConfig['jobs_per_worker'] ?? 20));

        return (int) ceil($metrics->depth / $jobsPerWorker);
    }
}
