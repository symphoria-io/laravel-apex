<?php

namespace Symphoria\Apex\Master;

interface ScalingStrategy
{
    public function demand(array $queueConfig, QueueMetrics $metrics): int;
}
