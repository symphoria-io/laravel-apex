<?php

namespace Symphoria\Apex\Ipc;

use Symphoria\Apex\Contracts\ApexStore;
use Symphoria\Apex\Support\ApexConfig;

class MetricsStore
{
    public function __construct(
        private readonly ApexConfig $config,
        private readonly ApexStore $store,
    ) {}

    public function writeSnapshot(array $snapshot): void
    {
        $this->store->put(
            $this->config->storeKey('metrics_snapshot'),
            (string) json_encode($snapshot + ['generated_at' => time()], JSON_UNESCAPED_SLASHES),
            10,
        );
    }

    public function readSnapshot(): ?array
    {
        $raw = $this->store->get($this->config->storeKey('metrics_snapshot'));

        if (! $raw) {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Increment per-minute and total counters for a queue.
     * Called from ApexWorkCommand on every JobProcessed event.
     */
    public function recordJob(string $queue): void
    {
        $key = $this->config->storeKey('metrics_queue_prefix').$queue.':processed';
        $bucket = (int) floor(time() / 60);

        $this->store->increment($key.':'.$bucket, 1, 600);
        $this->store->increment($key.':total');
    }

    /**
     * Push a rich job-completion entry into the recent-jobs sorted set
     * (used by the dashboard inspector). No-op when recent jobs disabled.
     *
     * @param  array<string, mixed>  $entry
     */
    public function recordRecentJob(array $entry): void
    {
        $cfg = $this->config->recentJobs();

        if (! ($cfg['enabled'] ?? true)) {
            return;
        }

        $score = (float) ($entry['finished_at'] ?? microtime(true));
        $key = $this->config->storeKey('recent_jobs');

        $this->store->timelineAdd($key, $score, (string) json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));

        $retentionSeconds = (int) ($cfg['retention_minutes'] ?? 60) * 60;

        // Per-queue last-job marker (used by the dashboard queues panel).
        $apexQueue = (string) ($entry['apex_queue'] ?? $entry['queue'] ?? '');
        if ($apexQueue !== '') {
            $marker = [
                'at' => $score,
                'name' => $entry['name'] ?? null,
                'status' => $entry['status'] ?? null,
                'runtime_ms' => $entry['runtime_ms'] ?? null,
            ];
            $this->store->put(
                $this->config->storeKey('metrics_queue_prefix').$apexQueue.':last_job',
                (string) json_encode($marker, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
                max(60, $retentionSeconds),
            );
        }

        $this->store->timelineTrim(
            $key,
            $score - $retentionSeconds,
            (int) ($cfg['max_entries'] ?? 1000),
        );
    }

    /**
     * Upsert a "queued" in-flight entry (JobQueued event). Keyed by job uuid
     * so it can be transitioned to "processing" and then removed when the job
     * reaches a terminal state. No-op when recent jobs / queued tracking off.
     *
     * @param  array<string, mixed>  $entry
     */
    public function recordJobQueued(array $entry): void
    {
        $cfg = $this->config->recentJobs();

        if (! ($cfg['enabled'] ?? true) || ! ($cfg['track_queued'] ?? true)) {
            return;
        }

        $uuid = (string) ($entry['uuid'] ?? '');
        if ($uuid === '') {
            return;
        }

        $now = microtime(true);
        $entry['id'] = $entry['id'] ?? $uuid;
        $entry['status'] = 'queued';
        $entry['queued_at'] = $entry['queued_at'] ?? $now;
        $entry['updated_at'] = $now;

        $this->store->hashPut(
            $this->config->storeKey('inflight_jobs'),
            $uuid,
            (string) json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
        );
    }

    /**
     * Transition an in-flight job to "processing", merging into any existing
     * queued entry (preserving queued_at / payload). Creates a fresh entry when
     * the job was not seen at dispatch time (e.g. queued before tracking began).
     *
     * @param  array<string, mixed>  $patch
     */
    public function markJobProcessing(?string $uuid, array $patch): void
    {
        $cfg = $this->config->recentJobs();

        if (! ($cfg['enabled'] ?? true) || $uuid === null || $uuid === '') {
            return;
        }

        $key = $this->config->storeKey('inflight_jobs');

        $existingRaw = $this->store->hashGet($key, $uuid);
        $entry = is_string($existingRaw) ? json_decode($existingRaw, true) : null;
        if (! is_array($entry)) {
            $entry = [];
        }

        $entry = array_merge($entry, $patch);
        $entry['uuid'] = $uuid;
        $entry['id'] = $entry['id'] ?? $uuid;
        $entry['status'] = 'processing';
        $entry['updated_at'] = microtime(true);

        $this->store->hashPut(
            $key,
            $uuid,
            (string) json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
        );
    }

    /**
     * Drop an in-flight entry once the job reaches a terminal state (it is then
     * represented by the completed/failed entry in the recent-jobs set).
     */
    public function removeInflight(?string $uuid): void
    {
        if ($uuid === null || $uuid === '') {
            return;
        }

        $this->store->hashForget($this->config->storeKey('inflight_jobs'), [$uuid]);
    }

    /**
     * Read in-flight (queued / processing) jobs newest-first, optionally
     * filtered by queue and/or status. Prunes entries older than the retention
     * window on read so stale dispatches (no worker ever picked them up) clear.
     *
     * @return array<int, array<string, mixed>>
     */
    public function inflightJobs(?string $queue = null, ?string $status = null, int $limit = 200): array
    {
        $cfg = $this->config->recentJobs();

        if (! ($cfg['enabled'] ?? true)) {
            return [];
        }

        $key = $this->config->storeKey('inflight_jobs');
        $all = $this->store->hashAll($key);

        if ($all === []) {
            return [];
        }

        $retentionSeconds = (int) ($cfg['retention_minutes'] ?? 60) * 60;
        $cutoff = microtime(true) - $retentionSeconds;

        $stale = [];
        $out = [];
        foreach ($all as $field => $json) {
            $entry = json_decode((string) $json, true);
            if (! is_array($entry)) {
                $stale[] = (string) $field;

                continue;
            }

            $updatedAt = (float) ($entry['updated_at'] ?? $entry['queued_at'] ?? 0);
            if ($updatedAt > 0 && $updatedAt < $cutoff) {
                $stale[] = (string) $field;

                continue;
            }

            if ($queue !== null && $queue !== '' && ($entry['queue'] ?? null) !== $queue) {
                continue;
            }
            if ($status !== null && $status !== '' && ($entry['status'] ?? null) !== $status) {
                continue;
            }

            $out[] = $entry;
        }

        if ($stale !== []) {
            $this->store->hashForget($key, $stale);
        }

        usort($out, fn ($a, $b) => ($b['updated_at'] ?? 0) <=> ($a['updated_at'] ?? 0));

        return array_slice($out, 0, max(1, $limit));
    }

    /**
     * Merge in-flight (queued / processing) jobs with completed/failed entries
     * into a single newest-first list. In-flight entries whose job already has a
     * terminal entry are dropped to avoid duplicate rows during the transition.
     *
     * @return array<int, array<string, mixed>>
     */
    public function recentJobsMerged(?string $queue = null, ?string $status = null, int $limit = 200): array
    {
        $terminal = $this->recentJobs($queue, $status, $limit);
        $inflight = $this->inflightJobs($queue, $status, $limit);

        $terminalUuids = [];
        foreach ($terminal as $job) {
            $uuid = (string) ($job['uuid'] ?? '');
            if ($uuid !== '') {
                $terminalUuids[$uuid] = true;
            }
        }

        $merged = $terminal;
        foreach ($inflight as $job) {
            $uuid = (string) ($job['uuid'] ?? '');
            if ($uuid !== '' && isset($terminalUuids[$uuid])) {
                continue;
            }
            $merged[] = $job;
        }

        usort($merged, fn ($a, $b) => $this->jobSortTimestamp($b) <=> $this->jobSortTimestamp($a));

        return array_slice($merged, 0, max(1, $limit));
    }

    /**
     * Best timestamp to sort a job entry by, regardless of lifecycle state.
     */
    private function jobSortTimestamp(array $job): float
    {
        return (float) ($job['finished_at']
            ?? $job['started_at']
            ?? $job['updated_at']
            ?? $job['queued_at']
            ?? 0);
    }

    /**
     * Read recent jobs newest-first, optionally filtered by queue and/or status.
     *
     * @return array<int, array<string, mixed>>
     */
    public function recentJobs(?string $queue = null, ?string $status = null, int $limit = 200): array
    {
        $key = $this->config->storeKey('recent_jobs');

        // Over-read, because the filtering happens here rather than in the
        // store: a page of 200 with a queue filter would otherwise come back
        // near-empty on a busy installation.
        $raw = $this->store->timelineNewest($key, max(1, $limit * 4));

        $out = [];
        foreach ($raw as $json) {
            $entry = json_decode((string) $json, true);
            if (! is_array($entry)) {
                continue;
            }
            if ($queue !== null && $queue !== '' && ($entry['queue'] ?? null) !== $queue) {
                continue;
            }
            if ($status !== null && $status !== '' && ($entry['status'] ?? null) !== $status) {
                continue;
            }
            $out[] = $entry;
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    public function processedTotal(string $queue): int
    {
        $key = $this->config->storeKey('metrics_queue_prefix').$queue.':processed:total';

        return (int) ($this->store->get($key) ?? 0);
    }

    /**
     * Read recent jobs strictly newer than the given score (microtime float),
     * oldest-first. Used by the live tail in apex:start -v.
     *
     * @return array<int, array<string, mixed>>
     */
    public function recentJobsSince(float $since, int $limit = 200): array
    {
        $key = $this->config->storeKey('recent_jobs');
        $raw = $this->store->timelineSince($key, $since, $limit);

        $out = [];
        foreach ($raw as $json) {
            $entry = json_decode((string) $json, true);
            if (is_array($entry)) {
                $out[] = $entry;
            }
        }

        return $out;
    }

    /**
     * Record that a worker just picked up a job (JobProcessing event). Kept
     * in a small ringbuffer separate from completed jobs so the live tail
     * can show both pick-up and finish moments.
     *
     * @param  array<string, mixed>  $entry
     */
    public function recordRecentJobStart(array $entry): void
    {
        $score = (float) ($entry['started_at'] ?? microtime(true));
        $key = $this->config->storeKey('recent_job_starts');

        $this->store->timelineAdd($key, $score, (string) json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));

        // Hard cap at 500 entries; prune oldest. No retention timestamp
        // pruning — the buffer is only for live tail and dashboard freshness.
        $this->store->timelineTrim($key, null, 500);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function recentJobStartsSince(float $since, int $limit = 200): array
    {
        $key = $this->config->storeKey('recent_job_starts');
        $raw = $this->store->timelineSince($key, $since, $limit);

        $out = [];
        foreach ($raw as $json) {
            $entry = json_decode((string) $json, true);
            if (is_array($entry)) {
                $out[] = $entry;
            }
        }

        return $out;
    }

    /**
     * Read the last completed job marker for a supervisor queue, if any.
     *
     * @return array<string, mixed>|null
     */
    public function lastJob(string $queue): ?array
    {
        $raw = $this->store->get(
            $this->config->storeKey('metrics_queue_prefix').$queue.':last_job'
        );

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Push a worker lifecycle event (spawned / reaped / shrink-signalled / boot-throttled).
     *
     * @param  array<string, mixed>  $entry
     */
    public function recordWorkerEvent(array $entry): void
    {
        $cfg = $this->config->workerEvents();

        if (! ($cfg['enabled'] ?? true)) {
            return;
        }

        $score = (float) ($entry['at'] ?? microtime(true));
        $key = $this->config->storeKey('worker_events');

        $this->store->timelineAdd($key, $score, (string) json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));

        $this->store->timelineTrim(
            $key,
            $score - (int) ($cfg['retention_minutes'] ?? 60) * 60,
            (int) ($cfg['max_entries'] ?? 1000),
        );
    }

    /**
     * Read worker lifecycle events newest-first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function workerEvents(?string $queue = null, ?string $type = null, int $limit = 300): array
    {
        $key = $this->config->storeKey('worker_events');
        $raw = $this->store->timelineNewest($key, max(1, $limit * 4));

        $out = [];
        foreach ($raw as $json) {
            $entry = json_decode((string) $json, true);
            if (! is_array($entry)) {
                continue;
            }
            if ($queue !== null && $queue !== '' && ($entry['queue'] ?? null) !== $queue) {
                continue;
            }
            if ($type !== null && $type !== '' && ($entry['type'] ?? null) !== $type) {
                continue;
            }
            $out[] = $entry;
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    public function processedRecent(string $queue, int $windowMinutes = 1): int
    {
        return $this->processedRecentMany([$queue], $windowMinutes)[$queue] ?? 0;
    }

    /**
     * Batched variant of `processedRecent()` for the master loop, which needs
     * the counter for every queue on every tick.
     *
     * @param  array<int, string>  $queues
     * @return array<string, int>
     */
    public function processedRecentMany(array $queues, int $windowMinutes = 1): array
    {
        $queues = array_values(array_unique($queues));

        if ($queues === []) {
            return [];
        }

        $prefix = $this->config->storeKey('metrics_queue_prefix');
        $bucket = (int) floor(time() / 60);
        $windowMinutes = max(1, $windowMinutes);

        $keys = [];
        foreach ($queues as $queue) {
            for ($i = 0; $i < $windowMinutes; $i++) {
                $keys[] = $prefix.$queue.':processed:'.($bucket - $i);
            }
        }

        $values = $this->store->many($keys);

        $out = [];
        foreach ($queues as $queue) {
            $total = 0;

            for ($i = 0; $i < $windowMinutes; $i++) {
                $total += (int) ($values[$prefix.$queue.':processed:'.($bucket - $i)] ?? 0);
            }

            $out[$queue] = $total;
        }

        return $out;
    }

    /**
     * Batched read of the lifetime counter + last-job marker for many queues.
     * Used by the master's snapshot writer.
     *
     * @param  array<int, string>  $queues
     * @return array<string, array{processed_total: int, last_job: array<string, mixed>|null}>
     */
    public function queueSummaries(array $queues): array
    {
        $queues = array_values(array_unique($queues));

        if ($queues === []) {
            return [];
        }

        $prefix = $this->config->storeKey('metrics_queue_prefix');

        $keys = [];
        foreach ($queues as $queue) {
            $keys[] = $prefix.$queue.':processed:total';
            $keys[] = $prefix.$queue.':last_job';
        }

        $values = $this->store->many($keys);

        $out = [];
        foreach ($queues as $queue) {
            $lastJobRaw = $values[$prefix.$queue.':last_job'] ?? null;
            $lastJob = is_string($lastJobRaw) && $lastJobRaw !== ''
                ? json_decode($lastJobRaw, true)
                : null;

            $out[$queue] = [
                'processed_total' => (int) ($values[$prefix.$queue.':processed:total'] ?? 0),
                'last_job' => is_array($lastJob) ? $lastJob : null,
            ];
        }

        return $out;
    }

    /**
     * Read the processed counter for a specific minute bucket. Used by
     * `MetricsAggregator` so the per-minute history row records the count
     * for the bucket being flushed (not the new current minute).
     */
    public function processedInMinute(string $queue, int $minute): int
    {
        $key = $this->config->storeKey('metrics_queue_prefix').$queue.':processed:'.$minute;

        return (int) ($this->store->get($key) ?? 0);
    }
}
