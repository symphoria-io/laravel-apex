<?php

namespace Symphoria\Apex\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symphoria\Apex\Master\QueueMetrics;
use Symphoria\Apex\Master\ScalingDecider;

class ScalingDeciderTest extends TestCase
{
    private function config(array $overrides = []): array
    {
        return array_merge([
            'profile' => 'background',
            'strategy' => 'depth',
            'jobs_per_worker' => 10,
            'min_processes' => 0,
            'min_processes_when_active' => 0,
            'max_processes' => 5,
            'use_activity' => false,
        ], $overrides);
    }

    public function test_returns_zero_when_paused(): void
    {
        $decider = new ScalingDecider;
        $result = $decider->decide($this->config(['min_processes' => 3]), new QueueMetrics(100, null, 0), false, true, 3);

        $this->assertSame(0, $result->desiredBaseline);
    }

    public function test_respects_min_processes_with_empty_queue(): void
    {
        $decider = new ScalingDecider;
        $result = $decider->decide($this->config(['min_processes' => 2]), new QueueMetrics(0, null, 0), false, false, 0);

        $this->assertSame(2, $result->desiredBaseline);
    }

    public function test_uses_active_min_when_activity_enabled_and_active(): void
    {
        $decider = new ScalingDecider;
        $config = $this->config([
            'min_processes' => 0,
            'min_processes_when_active' => 3,
            'use_activity' => true,
        ]);

        $result = $decider->decide($config, new QueueMetrics(0, null, 0), true, false, 0);

        $this->assertSame(3, $result->desiredBaseline);
    }

    public function test_uses_base_min_when_activity_disabled_even_if_active(): void
    {
        $decider = new ScalingDecider;
        $config = $this->config([
            'min_processes' => 0,
            'min_processes_when_active' => 5,
            'use_activity' => false,
        ]);

        $result = $decider->decide($config, new QueueMetrics(0, null, 0), true, false, 0);

        $this->assertSame(0, $result->desiredBaseline);
    }

    public function test_scales_to_demand_via_depth_strategy(): void
    {
        $decider = new ScalingDecider;
        $config = $this->config(['jobs_per_worker' => 10, 'max_processes' => 10]);

        $result = $decider->decide($config, new QueueMetrics(35, null, 0), false, false, 0);

        $this->assertSame(4, $result->desiredBaseline);
    }

    public function test_clamps_to_max(): void
    {
        $decider = new ScalingDecider;
        $config = $this->config(['jobs_per_worker' => 1, 'max_processes' => 3]);

        $result = $decider->decide($config, new QueueMetrics(1000, null, 0), false, false, 0);

        $this->assertSame(3, $result->desiredBaseline);
    }

    public function test_exposes_effective_min_so_master_can_recognise_floor_workers(): void
    {
        $decider = new ScalingDecider;
        $config = $this->config([
            'min_processes' => 1,
            'min_processes_when_active' => 3,
            'use_activity' => true,
        ]);

        $this->assertSame(3, $decider->decide($config, new QueueMetrics(0, null, 0), true, false, 0)->effectiveMin);
        $this->assertSame(1, $decider->decide($config, new QueueMetrics(0, null, 0), false, false, 0)->effectiveMin);
    }

    public function test_bursts_when_demand_saturates_max_even_though_flex_covers_the_queue(): void
    {
        $decider = new ScalingDecider;
        $config = $this->config([
            'jobs_per_worker' => 1,
            'max_processes' => 2,
            'burst_enabled' => true,
            'burst_threshold_depth' => 5,
            'burst_max_extra' => 4,
        ]);

        // Demand (100) saturates max long before flex coverage is considered.
        // Deducting the idle flex worker first used to hide that and disabled
        // bursting exactly when the backlog was deepest.
        $result = $decider->decide($config, new QueueMetrics(100, null, 0), false, false, 0, 0, 1);

        $this->assertTrue($result->wantsBurst);
    }

    public function test_does_not_burst_when_flex_coverage_actually_satisfies_demand(): void
    {
        $decider = new ScalingDecider;
        $config = $this->config([
            'jobs_per_worker' => 10,
            'max_processes' => 2,
            'burst_enabled' => true,
            'burst_threshold_depth' => 5,
            'burst_max_extra' => 4,
        ]);

        $result = $decider->decide($config, new QueueMetrics(10, null, 0), false, false, 0, 0, 1);

        $this->assertFalse($result->wantsBurst);
        $this->assertSame(0, $result->desiredBaseline);
    }
}
