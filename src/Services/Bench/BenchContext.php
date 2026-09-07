<?php

namespace Symphoria\Apex\Services\Bench;

class BenchContext
{
    public function __construct(
        public readonly string $runId,
        public readonly string $scenario,
        public readonly string $mode,
        public readonly int $iterations,
        public readonly int $concurrency,
        public readonly array $options = [],
    ) {}

    public function option(string $key, mixed $default = null): mixed
    {
        return $this->options[$key] ?? $default;
    }
}
