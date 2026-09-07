<?php

declare(strict_types=1);

namespace Symphoria\Apex\Models;

use Illuminate\Database\Eloquent\Model;
use Symphoria\Apex\Apex;

/**
 * Per-minute aggregate of one Apex queue's runtime metrics. Written by the
 * master at the end of each clock minute; consumed by the dashboard history
 * endpoint. Older rows pruned by `apex:prune-history`.
 */
class QueueMetricsHistory extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'queue_name',
        'bucket_at',
        'processed_count',
        'failed_count',
        'avg_runtime_ms',
        'max_runtime_ms',
        'avg_depth',
        'max_depth',
        'avg_oldest_wait_sec',
        'max_oldest_wait_sec',
        'avg_workers',
        'max_workers',
        'sample_count',
    ];

    protected $casts = [
        'bucket_at' => 'datetime',
        'processed_count' => 'integer',
        'failed_count' => 'integer',
        'avg_runtime_ms' => 'integer',
        'max_runtime_ms' => 'integer',
        'avg_depth' => 'integer',
        'max_depth' => 'integer',
        'avg_oldest_wait_sec' => 'integer',
        'max_oldest_wait_sec' => 'integer',
        'avg_workers' => 'integer',
        'max_workers' => 'integer',
        'sample_count' => 'integer',
    ];

    public function getTable(): string
    {
        return Apex::table('queue_metrics_history');
    }
}
