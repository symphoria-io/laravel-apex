<?php

namespace Symphoria\Apex\Master;

class ScalingDecision
{
    public function __construct(
        public readonly int $desiredBaseline,
        public readonly int $desiredBurst = 0,
        public readonly bool $wantsBurst = false,
        public readonly bool $burstBlockedByCpu = false,
        public readonly ?string $standbyReason = null,
        /** Floor for this queue right now (min_processes, or the _when_active variant). */
        public readonly int $effectiveMin = 0,
    ) {}
}
