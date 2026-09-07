<?php

namespace Symphoria\Apex\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symphoria\Apex\Support\ApexConfig;

class ApexConfigMergeTest extends TestCase
{
    private function baseConfig(): array
    {
        return [
            'connection' => 'default',
            'master' => [],
            'dashboard' => [],
            'activity' => [],
            'store_keys' => [],
            'profiles' => [
                'background' => [
                    'strategy' => 'depth',
                    'jobs_per_worker' => 50,
                    'idle_timeout_seconds' => 30,
                    'memory_mb' => 128,
                    'tries' => 2,
                    'timeout_seconds' => 300,
                ],
            ],
            'queues' => [
                'chat' => [
                    'profile' => 'background',
                    'queues' => ['chat'],
                    'min_processes' => 1,
                    'min_processes_when_active' => 3,
                    'max_processes' => 10,
                    'use_activity' => true,
                ],
            ],
            'environments' => [
                'production' => [
                    'queues' => [
                        'chat' => ['min_processes' => 2, 'max_processes' => 30],
                    ],
                ],
            ],
        ];
    }

    public function test_merges_profile_into_queue(): void
    {
        $config = new ApexConfig($this->baseConfig());
        $resolved = $config->queue('chat', 'testing');

        $this->assertSame('depth', $resolved['strategy']);
        $this->assertSame(50, $resolved['jobs_per_worker']);
        $this->assertSame(128, $resolved['memory_mb']);
        $this->assertSame(1, $resolved['min_processes']);
    }

    public function test_environment_override_wins_over_queue(): void
    {
        $config = new ApexConfig($this->baseConfig());
        $resolved = $config->queue('chat', 'production');

        $this->assertSame(2, $resolved['min_processes']);
        $this->assertSame(30, $resolved['max_processes']);
        $this->assertSame(3, $resolved['min_processes_when_active']);
    }

    public function test_unknown_environment_falls_back_to_queue_defaults(): void
    {
        $config = new ApexConfig($this->baseConfig());
        $resolved = $config->queue('chat', 'staging');

        $this->assertSame(1, $resolved['min_processes']);
        $this->assertSame(10, $resolved['max_processes']);
    }

    public function test_normalizes_min_processes_when_active_to_at_least_min(): void
    {
        $cfg = $this->baseConfig();
        $cfg['queues']['chat']['min_processes'] = 5;
        $cfg['queues']['chat']['min_processes_when_active'] = 2;

        $config = new ApexConfig($cfg);
        $resolved = $config->queue('chat', 'testing');

        $this->assertGreaterThanOrEqual(5, $resolved['min_processes_when_active']);
    }

    public function test_clamps_min_to_max(): void
    {
        $cfg = $this->baseConfig();
        $cfg['queues']['chat']['min_processes'] = 50;
        $cfg['queues']['chat']['max_processes'] = 10;

        $config = new ApexConfig($cfg);
        $resolved = $config->queue('chat', 'testing');

        $this->assertLessThanOrEqual($resolved['max_processes'], $resolved['min_processes']);
    }

    public function test_has_queue(): void
    {
        $config = new ApexConfig($this->baseConfig());

        $this->assertTrue($config->hasQueue('chat'));
        $this->assertFalse($config->hasQueue('nope'));
    }
}
