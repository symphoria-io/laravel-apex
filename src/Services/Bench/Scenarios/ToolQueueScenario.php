<?php

namespace Symphoria\Apex\Services\Bench\Scenarios;

use Illuminate\Support\Facades\Bus;
use Symphoria\Apex\Jobs\Bench\BenchNoOpJob;
use Symphoria\Apex\Services\Bench\BenchContext;
use Symphoria\Apex\Services\Bench\BenchResultRecorder;
use Symphoria\Apex\Services\Bench\BenchSample;

class ToolQueueScenario implements BenchScenario
{
    public function __construct(
        private readonly BenchResultRecorder $recorder,
    ) {}

    public function name(): string
    {
        return 'tool-queue';
    }

    public function description(): string
    {
        return 'No-op job on the chat-tools queue to isolate that queue\'s pickup overhead.';
    }

    public function queue(): ?string
    {
        return 'chat-tools';
    }

    public function prepare(BenchContext $context): void {}

    public function dispatch(BenchContext $context, int $sequence): BenchSample
    {
        $timeoutMs = (int) $context->option('await_timeout_ms', 30000);

        $dispatchStart = microtime(true);

        if ($context->mode === 'sync') {
            Bus::dispatchSync(
                new BenchNoOpJob($context->runId, $sequence, 'chat-tools', 0),
            );
        } else {
            BenchNoOpJob::dispatch($context->runId, $sequence, 'chat-tools', 0);
        }

        $dispatchedAt = microtime(true);
        $payload = $context->mode === 'sync'
            ? $this->recorder->read($context->runId, $sequence)
            : $this->recorder->await($context->runId, $sequence, $timeoutMs);

        if (! is_array($payload) || ! isset($payload['status'])) {
            return new BenchSample(
                sequence: $sequence,
                status: 'timeout',
                dispatchMs: round(($dispatchedAt - $dispatchStart) * 1000, 3),
                waitMs: null,
                runMs: null,
                totalMs: round((microtime(true) - $dispatchStart) * 1000, 3),
                error: 'No payload after '.$timeoutMs.' ms',
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
}
