<?php

namespace Symphoria\Apex\Services\Bench\Scenarios;

use Illuminate\Support\Facades\Bus;
use Symphoria\Apex\Jobs\Bench\BenchNoOpJob;
use Symphoria\Apex\Services\Bench\BenchContext;
use Symphoria\Apex\Services\Bench\BenchResultRecorder;
use Symphoria\Apex\Services\Bench\BenchSample;

class NoOpScenario implements BenchScenario
{
    public function __construct(
        private readonly BenchResultRecorder $recorder,
    ) {}

    public function name(): string
    {
        return 'noop';
    }

    public function description(): string
    {
        return 'Empty job to isolate dispatch/pickup/ack overhead per worker mode.';
    }

    public function queue(): ?string
    {
        return 'default';
    }

    public function prepare(BenchContext $context): void {}

    public function dispatch(BenchContext $context, int $sequence): BenchSample
    {
        $busyMicros = (int) $context->option('busy_micros', 0);
        $timeoutMs = (int) $context->option('await_timeout_ms', 30000);
        $queue = (string) $context->option('queue', 'default');

        if ($context->mode === 'sync') {
            $dispatchStart = microtime(true);
            $job = new BenchNoOpJob($context->runId, $sequence, $queue, $busyMicros);
            $dispatchedAt = microtime(true);
            Bus::dispatchSync($job);
            $endedAt = microtime(true);

            $payload = $this->recorder->read($context->runId, $sequence) ?? [];

            return new BenchSample(
                sequence: $sequence,
                status: $payload['status'] ?? 'completed',
                dispatchMs: round(($dispatchedAt - $dispatchStart) * 1000, 3),
                waitMs: 0.0,
                runMs: $this->runMs($payload),
                totalMs: round(($endedAt - $dispatchStart) * 1000, 3),
                memoryMb: $payload['memory_mb'] ?? null,
                error: $payload['error'] ?? null,
            );
        }

        $dispatchStart = microtime(true);
        BenchNoOpJob::dispatch($context->runId, $sequence, $queue, $busyMicros);
        $dispatchedAt = microtime(true);

        $payload = $this->recorder->await($context->runId, $sequence, $timeoutMs);

        if ($payload === null || ! isset($payload['status'])) {
            return new BenchSample(
                sequence: $sequence,
                status: 'timeout',
                dispatchMs: round(($dispatchedAt - $dispatchStart) * 1000, 3),
                waitMs: null,
                runMs: null,
                totalMs: round((microtime(true) - $dispatchStart) * 1000, 3),
                error: 'Worker did not finish job within '.$timeoutMs.' ms',
            );
        }

        $startedAt = (float) ($payload['started_at'] ?? $dispatchedAt);
        $finishedAt = (float) ($payload['finished_at'] ?? microtime(true));

        return new BenchSample(
            sequence: $sequence,
            status: $payload['status'],
            dispatchMs: round(($dispatchedAt - $dispatchStart) * 1000, 3),
            waitMs: round(($startedAt - $dispatchedAt) * 1000, 3),
            runMs: round(($finishedAt - $startedAt) * 1000, 3),
            totalMs: round(($finishedAt - $dispatchStart) * 1000, 3),
            memoryMb: $payload['memory_mb'] ?? null,
            error: $payload['error'] ?? null,
        );
    }

    public function cleanup(BenchContext $context): void
    {
        $this->recorder->forgetRun($context->runId, $context->iterations);
    }

    private function runMs(array $payload): ?float
    {
        if (! isset($payload['started_at'], $payload['finished_at'])) {
            return null;
        }

        return round(((float) $payload['finished_at'] - (float) $payload['started_at']) * 1000, 3);
    }
}
