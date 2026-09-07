<?php

namespace Symphoria\Apex\Services\Bench;

use Symphoria\Apex\Services\Bench\Support\PercentileCalculator;

class BenchResult
{
    /** @var BenchSample[] */
    private array $samples = [];

    private float $startedAt;

    private float $endedAt = 0.0;

    public function __construct(
        public readonly BenchContext $context,
    ) {
        $this->startedAt = microtime(true);
    }

    public function addSample(BenchSample $sample): void
    {
        $this->samples[] = $sample;
    }

    public function finish(): void
    {
        $this->endedAt = microtime(true);
    }

    /** @return BenchSample[] */
    public function samples(): array
    {
        return $this->samples;
    }

    public function summary(): array
    {
        $ok = array_values(array_filter($this->samples, fn (BenchSample $s) => $s->status === 'completed'));
        $failed = array_values(array_filter($this->samples, fn (BenchSample $s) => $s->status !== 'completed'));

        $total = array_map(fn (BenchSample $s) => $s->totalMs, $ok);
        $run = array_map(fn (BenchSample $s) => $s->runMs, array_filter($ok, fn (BenchSample $s) => $s->runMs !== null));
        $wait = array_map(fn (BenchSample $s) => $s->waitMs, array_filter($ok, fn (BenchSample $s) => $s->waitMs !== null));
        $dispatch = array_map(fn (BenchSample $s) => $s->dispatchMs, $ok);
        $memory = array_map(fn (BenchSample $s) => $s->memoryMb, array_filter($ok, fn (BenchSample $s) => $s->memoryMb !== null));

        $walltime = max($this->endedAt - $this->startedAt, 0.0001);

        return [
            'scenario' => $this->context->scenario,
            'mode' => $this->context->mode,
            'run_id' => $this->context->runId,
            'iterations' => $this->context->iterations,
            'concurrency' => $this->context->concurrency,
            'completed' => count($ok),
            'failed' => count($failed),
            'walltime_sec' => round($walltime, 3),
            'throughput_per_sec' => count($ok) > 0 ? round(count($ok) / $walltime, 3) : 0.0,
            'total_ms' => PercentileCalculator::summarize($total),
            'run_ms' => PercentileCalculator::summarize($run),
            'wait_ms' => PercentileCalculator::summarize($wait),
            'dispatch_ms' => PercentileCalculator::summarize($dispatch),
            'memory_mb' => PercentileCalculator::summarize($memory),
            'errors' => array_slice(array_map(fn (BenchSample $s) => $s->error, $failed), 0, 5),
        ];
    }

    public function toArray(): array
    {
        return [
            'summary' => $this->summary(),
            'samples' => array_map(fn (BenchSample $s) => $s->toArray(), $this->samples),
        ];
    }
}
