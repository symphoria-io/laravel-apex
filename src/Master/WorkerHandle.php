<?php

namespace Symphoria\Apex\Master;

class WorkerHandle
{
    public function __construct(
        public readonly int $pid,
        public readonly string $queueName,
        public readonly string $apexId,
        public readonly float $startedAt,
        /** @var resource */
        public readonly mixed $process,
        public readonly bool $isBurst = false,
        /**
         * Floor workers exist to satisfy `min_processes` and are spawned with
         * `--idle-timeout=0`, so they never exit on their own. The master is
         * therefore the only thing that can retire them.
         */
        public readonly bool $isFloor = false,
    ) {}
}
