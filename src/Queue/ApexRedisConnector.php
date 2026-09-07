<?php

namespace Symphoria\Apex\Queue;

use Illuminate\Queue\Connectors\RedisConnector;

/**
 * Returns ApexRedisQueue instead of Laravel's stock RedisQueue. Registered as
 * the 'redis' driver via Queue::extend in ApexServiceProvider — strict subclass
 * so non-Apex code keeps working unchanged.
 */
class ApexRedisConnector extends RedisConnector
{
    public function connect(array $config)
    {
        return new ApexRedisQueue(
            $this->redis,
            $config['queue'],
            $config['connection'] ?? $this->connection,
            $config['retry_after'] ?? 60,
            $config['block_for'] ?? null,
            $config['after_commit'] ?? null,
            $config['migration_batch_size'] ?? -1,
        );
    }
}
