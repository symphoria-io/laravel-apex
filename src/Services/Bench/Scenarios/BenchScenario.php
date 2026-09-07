<?php

namespace Symphoria\Apex\Services\Bench\Scenarios;

use Symphoria\Apex\Services\Bench\BenchContext;
use Symphoria\Apex\Services\Bench\BenchSample;

interface BenchScenario
{
    public function name(): string;

    public function description(): string;

    /**
     * Queue this scenario uses, or null when it is fully synchronous (no worker involved).
     */
    public function queue(): ?string;

    public function prepare(BenchContext $context): void;

    /**
     * Dispatch one iteration. Must return a BenchSample. When the workload is asynchronous,
     * the scenario is responsible for awaiting completion (via the BenchResultRecorder
     * shared key, polling DB state, etc.) before returning.
     */
    public function dispatch(BenchContext $context, int $sequence): BenchSample;

    public function cleanup(BenchContext $context): void;
}
