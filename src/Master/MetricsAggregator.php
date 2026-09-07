<?php

namespace Symphoria\Apex\Master;

use Symphoria\Apex\Ipc\MetricsStore;
use Symphoria\Apex\Models\QueueMetricsHistory;
use Throwable;

/**
 * Per-minute rolling aggregate of queue metrics. Fed by the master once per
 * snapshot (≈1 Hz). When the wall-clock minute rolls over, the buffered
 * samples are flushed as one row per queue into `the metrics history table`.
 *
 * Aggregation matters because depth/wait can swing wildly within a minute;
 * a single sample at the boundary would miss bursts. We track avg + max so
 * the UI can show both "typical" and "worst" lines.
 */
class MetricsAggregator
{
    /** @var array<string, array{depth: int[], oldest: int[], workers: int[]}> */
    private array $buffer = [];

    private int $bucketMinute;

    public function __construct(
        private readonly MetricsStore $metrics,
    ) {
        $this->bucketMinute = $this->currentMinute();
    }

    /**
     * Record one snapshot tick into the in-memory buffer for the current minute.
     *
     * @param  array<string, mixed>  $queueSnapshot
     */
    public function recordSample(string $queue, array $queueSnapshot): void
    {
        $minute = $this->currentMinute();
        if ($minute !== $this->bucketMinute) {
            $this->flush();
            $this->bucketMinute = $minute;
        }

        $this->buffer[$queue]['depth'][] = (int) ($queueSnapshot['depth'] ?? 0);
        $this->buffer[$queue]['oldest'][] = (int) ($queueSnapshot['oldest_wait_seconds'] ?? 0);
        $this->buffer[$queue]['workers'][] = (int) ($queueSnapshot['current_processes'] ?? 0);
    }

    /**
     * Flush the current buffer to the DB. Called automatically on minute roll-over;
     * callers may invoke directly on master shutdown to persist a partial bucket.
     */
    public function flush(): void
    {
        if ($this->buffer === []) {
            return;
        }

        $bucketAt = date('Y-m-d H:i:00', $this->bucketMinute * 60);

        try {
            foreach ($this->buffer as $queue => $samples) {
                $depth = $samples['depth'] ?? [];
                $oldest = $samples['oldest'] ?? [];
                $workers = $samples['workers'] ?? [];
                $count = count($depth);
                if ($count === 0) {
                    continue;
                }

                $processed = $this->metrics->processedInMinute($queue, $this->bucketMinute);
                // No direct "failed last minute" counter exists yet; we count
                // failed entries in the recent-jobs ringbuffer that fall in
                // this minute. Best-effort, may miss a few seconds either way.
                $failed = $this->failedInBucket($queue, $this->bucketMinute);

                $runtimeStats = $this->runtimeStatsInBucket($queue, $this->bucketMinute);

                QueueMetricsHistory::updateOrCreate(
                    ['queue_name' => $queue, 'bucket_at' => $bucketAt],
                    [
                        'processed_count' => $processed,
                        'failed_count' => $failed,
                        'avg_runtime_ms' => $runtimeStats['avg'],
                        'max_runtime_ms' => $runtimeStats['max'],
                        'avg_depth' => (int) round(array_sum($depth) / $count),
                        'max_depth' => max($depth),
                        'avg_oldest_wait_sec' => (int) round(array_sum($oldest) / $count),
                        'max_oldest_wait_sec' => max($oldest),
                        'avg_workers' => (int) round(array_sum($workers) / $count),
                        'max_workers' => max($workers),
                        'sample_count' => $count,
                    ],
                );
            }
        } catch (Throwable $e) {
            // Never break the master loop on a metrics-history write failure.
            report($e);
        } finally {
            $this->buffer = [];
        }
    }

    private function currentMinute(): int
    {
        return (int) floor(time() / 60);
    }

    /**
     * @return array{avg: int, max: int}
     */
    private function runtimeStatsInBucket(string $queue, int $minute): array
    {
        $jobs = $this->metrics->recentJobs($queue, null, 500);
        $from = $minute * 60;
        $to = $from + 60;
        $runtimes = [];
        foreach ($jobs as $job) {
            $finishedAt = (float) ($job['finished_at'] ?? 0);
            if ($finishedAt < $from || $finishedAt >= $to) {
                continue;
            }
            $rt = $job['runtime_ms'] ?? null;
            if ($rt !== null) {
                $runtimes[] = (int) $rt;
            }
        }
        if ($runtimes === []) {
            return ['avg' => 0, 'max' => 0];
        }

        return [
            'avg' => (int) round(array_sum($runtimes) / count($runtimes)),
            'max' => max($runtimes),
        ];
    }

    private function failedInBucket(string $queue, int $minute): int
    {
        $jobs = $this->metrics->recentJobs($queue, null, 500);
        $from = $minute * 60;
        $to = $from + 60;
        $count = 0;
        foreach ($jobs as $job) {
            $finishedAt = (float) ($job['finished_at'] ?? 0);
            if ($finishedAt < $from || $finishedAt >= $to) {
                continue;
            }
            $status = $job['status'] ?? null;
            if ($status === 'failed' || $status === 'errored') {
                $count++;
            }
        }

        return $count;
    }
}
