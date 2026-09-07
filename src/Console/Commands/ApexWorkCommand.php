<?php

namespace Symphoria\Apex\Console\Commands;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Console\WorkCommand;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Queue\Worker;
use Illuminate\Support\Facades\Event;
use Symphoria\Apex\Ipc\ControlChannel;
use Symphoria\Apex\Ipc\HeartbeatStore;
use Symphoria\Apex\Ipc\MetricsStore;
use Symphoria\Apex\Queue\ApexWorker;
use Symphoria\Apex\Support\ApexConfig;

class ApexWorkCommand extends WorkCommand
{
    protected $signature = 'apex:work
                            {connection? : The name of the queue connection to work}
                            {--name=apex : The name of the worker}
                            {--apex-id= : Unique apex worker id used for heartbeat tracking}
                            {--queue= : The names of the queues to work}
                            {--idle-timeout=60 : Stop the worker after N seconds without a job}
                            {--daemon : Run the worker in daemon mode (Deprecated)}
                            {--once : Only process the next job on the queue}
                            {--stop-when-empty : Stop when the queue is empty}
                            {--stop-when-empty-for=0 : Stop when no jobs have been processed for the given number of seconds}
                            {--delay=0 : The number of seconds to delay failed jobs (Deprecated)}
                            {--backoff=0 : The number of seconds to wait before retrying a job that encountered an uncaught exception}
                            {--max-jobs=0 : The number of jobs to process before stopping}
                            {--max-time=0 : The maximum number of seconds the worker should run}
                            {--force : Force the worker to run even in maintenance mode}
                            {--memory=128 : The memory limit in megabytes}
                            {--sleep=3 : The number of seconds to sleep when no job is available}
                            {--rest=0 : The number of seconds to rest between jobs}
                            {--timeout=60 : The number of seconds a child process can run}
                            {--tries=1 : The number of times to attempt a job before logging it failed}
                            {--json : Output the queue worker information as JSON}';

    protected $description = 'Run an Apex-managed queue worker (extends queue:work with idle-timeout, heartbeat and apex shutdown signal)';

    private int $lastJobAt;

    private string $apexId;

    private ApexConfig $apexConfig;

    private HeartbeatStore $heartbeats;

    private ControlChannel $control;

    private MetricsStore $metrics;

    private int $jobsProcessed = 0;

    private float $startedAt;

    private float $jobStartedAt = 0.0;

    private ?\Throwable $jobException = null;

    /** @var list<string> Laravel queue names currently stopped, refreshed on the loop. */
    private array $stoppedQueues = [];

    private float $stoppedQueuesReadAt = 0.0;

    public function __construct(Worker $worker, Cache $cache)
    {
        parent::__construct($worker, $cache);
    }

    public function handle()
    {
        $this->stopTelescopeRecording();

        $this->apexConfig = ApexConfig::fromConfig();
        $this->heartbeats = app(HeartbeatStore::class);
        $this->control = app(ControlChannel::class);
        $this->metrics = app(MetricsStore::class);

        $this->apexId = (string) ($this->option('apex-id') ?: bin2hex(random_bytes(8)));
        $this->startedAt = microtime(true);
        $this->lastJobAt = time();

        $this->writeHeartbeat('booted');

        // A queue can be stopped while this worker is running. Filtering on
        // every pop keeps it from draining a queue Apex was told to leave
        // alone, without having to kill an otherwise healthy process.
        if ($this->worker instanceof ApexWorker) {
            $this->worker->filterQueuesUsing(fn (array $queues): array => array_values(
                array_diff($queues, $this->stoppedQueues),
            ));
        }

        Event::listen(JobProcessing::class, function (JobProcessing $event): void {
            $this->jobStartedAt = microtime(true);
            $this->jobException = null;
            $this->writeHeartbeat('working');

            try {
                $uuid = method_exists($event->job, 'uuid') ? $event->job->uuid() : null;

                $this->metrics->recordRecentJobStart([
                    'started_at' => $this->jobStartedAt,
                    'name' => $event->job->resolveName(),
                    'queue' => $event->job->getQueue() ?? (string) $this->option('name'),
                    'apex_queue' => (string) $this->option('name'),
                    'apex_id' => $this->apexId,
                    'attempts' => $event->job->attempts(),
                ]);

                $this->metrics->markJobProcessing($uuid, [
                    'uuid' => $uuid,
                    'name' => $event->job->resolveName(),
                    'queue' => $event->job->getQueue() ?? (string) $this->option('name'),
                    'apex_queue' => (string) $this->option('name'),
                    'apex_id' => $this->apexId,
                    'attempts' => $event->job->attempts(),
                    'started_at' => $this->jobStartedAt,
                ]);
            } catch (\Throwable $e) {
                // best-effort
            }
        });

        Event::listen(JobExceptionOccurred::class, function (JobExceptionOccurred $event): void {
            $this->jobException = $event->exception;
        });

        Event::listen(JobFailed::class, function (JobFailed $event): void {
            $this->jobException = $event->exception;
            $this->captureRecentJob($event->job, 'failed');
        });

        Event::listen(JobProcessed::class, function (JobProcessed $event): void {
            $this->jobsProcessed++;
            $this->lastJobAt = time();
            $queue = $event->job->getQueue() ?? (string) $this->option('name');
            $this->metrics->recordJob($queue);
            $status = $event->job->hasFailed() ? 'failed' : ($this->jobException ? 'errored' : 'completed');
            $this->captureRecentJob($event->job, $status);
            $this->writeHeartbeat('working');
        });

        Event::listen(Looping::class, function (): void {
            $this->onLoop();
        });

        Event::listen(WorkerStopping::class, function (): void {
            $this->writeHeartbeat('stopping');
            $this->heartbeats->delete($this->apexId);
        });

        return parent::handle();
    }

    /**
     * Telescope buffers every query, redis call and log entry in memory and only
     * flushes them when the script exits. In a long-running worker that means
     * unbounded growth until OOM. Disable recording for the worker process.
     */
    private function stopTelescopeRecording(): void
    {
        $telescope = 'Laravel\\Telescope\\Telescope';
        if (class_exists($telescope)) {
            $telescope::stopRecording();
        }
    }

    private function onLoop(): void
    {
        $idleTimeout = (int) $this->option('idle-timeout');

        if ($idleTimeout > 0 && (time() - $this->lastJobAt) > $idleTimeout) {
            $this->writeHeartbeat('idle-exit');
            $this->worker->shouldQuit = true;

            return;
        }

        if ($this->control->isShutdownRequested()) {
            $this->writeHeartbeat('shutdown-requested');
            $this->worker->shouldQuit = true;

            return;
        }

        $this->refreshStoppedQueues();

        // Pause state is keyed by Apex group, and this worker belongs to
        // exactly one: `--name` is the group, `--queue` is what it reads.
        if ($this->control->isQueueStopped((string) $this->option('name'))) {
            $this->writeHeartbeat('paused');
            $this->worker->shouldQuit = true;

            return;
        }

        $this->writeHeartbeat('waiting');
    }

    /**
     * Which Laravel queues are off limits right now, across every configured
     * group. A flex pool reads queues it does not own, so it has to respect a
     * stop on the group that does.
     */
    private function refreshStoppedQueues(): void
    {
        $now = microtime(true);

        if ($this->stoppedQueuesReadAt > 0.0 && ($now - $this->stoppedQueuesReadAt) < 1.0) {
            return;
        }

        $this->stoppedQueuesReadAt = $now;

        try {
            $groups = $this->apexConfig->allQueues();
            $states = $this->control->statesFor(array_keys($groups));
        } catch (\Throwable $e) {
            // Leave the previous answer in place; a store blip must not
            // silently open a queue an operator closed.
            return;
        }

        $stopped = [];

        foreach ($groups as $group => $settings) {
            $state = $states[$group] ?? null;

            if ($state === null || (! $state['paused'] && ! $state['suspended'])) {
                continue;
            }

            foreach ($settings['queues'] ?? [$group] as $queue) {
                $stopped[] = (string) $queue;
            }
        }

        $this->stoppedQueues = array_values(array_unique($stopped));
    }

    private function writeHeartbeat(string $state): void
    {
        $this->heartbeats->write($this->apexId, [
            'pid' => getmypid(),
            'queue' => $this->option('name'),
            'queues' => array_map('trim', explode(',', (string) $this->option('queue'))),
            'state' => $state,
            'memory_mb' => round(memory_get_usage(true) / 1024 / 1024, 1),
            'jobs_processed' => $this->jobsProcessed,
            'last_job_at' => $this->lastJobAt,
            'started_at' => (int) $this->startedAt,
            'uptime_seconds' => max(0, time() - (int) $this->startedAt),
        ]);
    }

    /**
     * Build a rich entry for the recent-jobs inspector and push it into the metrics store.
     */
    private function captureRecentJob(Job $job, string $status): void
    {
        $finishedAt = microtime(true);
        $startedAt = $this->jobStartedAt > 0 ? $this->jobStartedAt : $finishedAt;
        $runtimeMs = (int) round(($finishedAt - $startedAt) * 1000);

        $cfg = $this->apexConfig->recentJobs();
        $maxBytes = (int) ($cfg['payload_max_bytes'] ?? 16384);
        $capturePayload = (bool) ($cfg['capture_payload'] ?? true);

        $payloadRaw = $job->getRawBody();
        $payload = null;
        $displayName = $job->resolveName();

        if ($capturePayload && is_string($payloadRaw)) {
            $decoded = json_decode($payloadRaw, true);
            if (is_array($decoded)) {
                $payload = $decoded;
                if (isset($payload['data']['command']) && is_string($payload['data']['command'])) {
                    $payload['data']['command'] = '<'.strlen($payload['data']['command']).' bytes serialized>';
                }
                $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
                if ($encoded !== false && strlen($encoded) > $maxBytes) {
                    $payload = ['_truncated' => true, '_size' => strlen($encoded)];
                }
            }
        }

        $entry = [
            'id' => bin2hex(random_bytes(8)),
            'uuid' => method_exists($job, 'uuid') ? $job->uuid() : null,
            'job_id' => method_exists($job, 'getJobId') ? $job->getJobId() : null,
            'name' => $displayName,
            'queue' => $job->getQueue() ?? (string) $this->option('name'),
            'apex_queue' => (string) $this->option('name'),
            'apex_id' => $this->apexId,
            'attempts' => $job->attempts(),
            'status' => $status,
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
            'runtime_ms' => $runtimeMs,
            'memory_mb' => round(memory_get_usage(true) / 1024 / 1024, 1),
            'payload' => $payload,
        ];

        if ($this->jobException !== null) {
            $entry['exception'] = [
                'class' => get_class($this->jobException),
                'message' => $this->jobException->getMessage(),
                'file' => $this->jobException->getFile(),
                'line' => $this->jobException->getLine(),
                'trace' => mb_substr($this->jobException->getTraceAsString(), 0, 8192),
            ];
        }

        try {
            $this->metrics->recordRecentJob($entry);
            $this->metrics->removeInflight($entry['uuid'] ?? null);
        } catch (\Throwable $e) {
            // best-effort; never break the worker
        }
    }
}
