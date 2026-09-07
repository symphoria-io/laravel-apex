<?php

namespace Symphoria\Apex\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symphoria\Apex\Models\QueueMetricsHistory;
use Symphoria\Apex\Support\ApexConfig;

class ApexHistoryController
{
    public function index(Request $request): JsonResponse
    {
        $config = ApexConfig::fromConfig();

        $queue = (string) $request->input('queue', '');
        $hours = max(1, min(168, (int) $request->input('hours', 6)));

        // Empty queue = aggregate across all configured queues. Sum
        // throughput, average depth/wait/workers across queues per bucket.
        $aggregateAll = $queue === '';

        if (! $aggregateAll && ! $config->hasQueue($queue)) {
            return response()->json(['error' => "Unknown queue '{$queue}'"], 422);
        }

        $from = Carbon::now()->subHours($hours);

        $query = QueueMetricsHistory::query()
            ->where('bucket_at', '>=', $from);

        if (! $aggregateAll) {
            $query->where('queue_name', $queue);
        }

        if ($aggregateAll) {
            $rows = $query
                ->selectRaw('bucket_at')
                ->selectRaw('SUM(processed_count) as processed_count')
                ->selectRaw('SUM(failed_count) as failed_count')
                ->selectRaw('AVG(avg_runtime_ms) as avg_runtime_ms')
                ->selectRaw('MAX(max_runtime_ms) as max_runtime_ms')
                ->selectRaw('SUM(avg_depth) as avg_depth')
                ->selectRaw('SUM(max_depth) as max_depth')
                ->selectRaw('AVG(avg_oldest_wait_sec) as avg_oldest_wait_sec')
                ->selectRaw('MAX(max_oldest_wait_sec) as max_oldest_wait_sec')
                ->selectRaw('SUM(avg_workers) as avg_workers')
                ->selectRaw('SUM(max_workers) as max_workers')
                ->selectRaw('SUM(sample_count) as sample_count')
                ->groupBy('bucket_at')
                ->orderBy('bucket_at')
                ->get()
                ->map(fn ($row) => [
                    'bucket_at' => Carbon::parse($row->bucket_at)->toIso8601String(),
                    'processed_count' => (int) $row->processed_count,
                    'failed_count' => (int) $row->failed_count,
                    'avg_runtime_ms' => (int) $row->avg_runtime_ms,
                    'max_runtime_ms' => (int) $row->max_runtime_ms,
                    'avg_depth' => (int) $row->avg_depth,
                    'max_depth' => (int) $row->max_depth,
                    'avg_oldest_wait_sec' => (int) $row->avg_oldest_wait_sec,
                    'max_oldest_wait_sec' => (int) $row->max_oldest_wait_sec,
                    'avg_workers' => (int) $row->avg_workers,
                    'max_workers' => (int) $row->max_workers,
                    'sample_count' => (int) $row->sample_count,
                ])
                ->values();
        } else {
            $rows = $query
                ->orderBy('bucket_at')
                ->get([
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
                ])
                ->map(fn (QueueMetricsHistory $row) => [
                    'bucket_at' => $row->bucket_at?->toIso8601String(),
                    'processed_count' => (int) $row->processed_count,
                    'failed_count' => (int) $row->failed_count,
                    'avg_runtime_ms' => (int) $row->avg_runtime_ms,
                    'max_runtime_ms' => (int) $row->max_runtime_ms,
                    'avg_depth' => (int) $row->avg_depth,
                    'max_depth' => (int) $row->max_depth,
                    'avg_oldest_wait_sec' => (int) $row->avg_oldest_wait_sec,
                    'max_oldest_wait_sec' => (int) $row->max_oldest_wait_sec,
                    'avg_workers' => (int) $row->avg_workers,
                    'max_workers' => (int) $row->max_workers,
                    'sample_count' => (int) $row->sample_count,
                ])
                ->values();
        }

        return response()->json([
            'queue' => $aggregateAll ? null : $queue,
            'hours' => $hours,
            'from' => $from->toIso8601String(),
            'rows' => $rows,
        ])->withHeaders([
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        ]);
    }
}
