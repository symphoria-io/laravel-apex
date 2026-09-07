<?php

namespace Symphoria\Apex\Services\Bench;

class BenchSample
{
    public function __construct(
        public readonly int $sequence,
        public readonly string $status,
        public readonly float $dispatchMs,
        public readonly ?float $waitMs,
        public readonly ?float $runMs,
        public readonly float $totalMs,
        public readonly ?float $memoryMb = null,
        public readonly ?string $error = null,
        public readonly array $extra = [],
    ) {}

    public function toArray(): array
    {
        return [
            'sequence' => $this->sequence,
            'status' => $this->status,
            'dispatch_ms' => $this->dispatchMs,
            'wait_ms' => $this->waitMs,
            'run_ms' => $this->runMs,
            'total_ms' => $this->totalMs,
            'memory_mb' => $this->memoryMb,
            'error' => $this->error,
            'extra' => $this->extra,
        ];
    }
}
