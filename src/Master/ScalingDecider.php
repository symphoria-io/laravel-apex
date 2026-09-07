<?php

namespace Symphoria\Apex\Master;

use InvalidArgumentException;

class ScalingDecider
{
    /**
     * Decide how many workers a queue should have right now.
     *
     * @param  int  $busyWorkers  number of currently-working (non-idle) workers
     * @param  int  $flexAvailable  number of IDLE flex workers currently listening
     *                              on this queue. They count toward the queue's
     *                              min_processes_when_active and (if
     *                              `standby_counts_flex` is true) toward the
     *                              standby buffer, so the dedicated pool only
     *                              spawns when the flex pool is saturated.
     */
    public function decide(
        array $queueConfig,
        QueueMetrics $metrics,
        bool $isActive,
        bool $isPaused,
        int $currentWorkers,
        int $busyWorkers = 0,
        int $flexAvailable = 0,
    ): ScalingDecision {
        if ($isPaused) {
            return new ScalingDecision(0, 0, false);
        }

        $minBase = (int) ($queueConfig['min_processes'] ?? 0);
        $minActive = (int) ($queueConfig['min_processes_when_active'] ?? $minBase);
        $useActivity = (bool) ($queueConfig['use_activity'] ?? false);
        $max = (int) ($queueConfig['max_processes'] ?? max(1, $minActive));
        $standby = max(0, (int) ($queueConfig['standby_when_active'] ?? 0));
        $standbyCountsFlex = (bool) ($queueConfig['standby_counts_flex'] ?? true);
        $isFlex = (bool) ($queueConfig['is_flex'] ?? false);

        // A flex queue cannot subtract itself — it serves the others.
        if ($isFlex) {
            $flexAvailable = 0;
        }

        $effectiveMin = $useActivity && $isActive ? $minActive : $minBase;

        $strategy = $this->resolveStrategy($queueConfig['strategy'] ?? 'depth');
        $demand = $strategy->demand($queueConfig, $metrics);

        $standbyReason = null;
        if ($useActivity && $isActive && $standby > 0) {
            $standbyTarget = $busyWorkers + $standby;
            if ($standbyTarget > $demand) {
                $demand = $standbyTarget;
                $standbyReason = 'standby_when_active';
            }
        }

        $desiredBaseline = max($effectiveMin, $demand);

        // Gate bursting on the demand this queue has *before* flex coverage is
        // subtracted. Comparing the post-deduction figure to `max` means a deep
        // queue that happens to have idle flex cover could never burst.
        $demandBeforeFlex = min($max, $desiredBaseline);

        // Subtract idle flex workers — they already cover this queue. For
        // standby specifically, allow opt-out via `standby_counts_flex`.
        if ($flexAvailable > 0) {
            $deductible = $flexAvailable;
            if ($standbyReason === 'standby_when_active' && ! $standbyCountsFlex) {
                // Cap the deduction so the standby buffer survives.
                $deductible = max(0, $flexAvailable - $standby);
            }
            $desiredBaseline = max(0, $desiredBaseline - $deductible);
        }

        $desiredBaseline = min($max, max(0, $desiredBaseline));

        $wantsBurst = false;
        $burstMaxExtra = max(0, (int) ($queueConfig['burst_max_extra'] ?? 0));
        $burstThreshold = max(0, (int) ($queueConfig['burst_threshold_depth'] ?? 0));

        if (
            $burstMaxExtra > 0
            && $burstThreshold > 0
            && $demandBeforeFlex >= $max
            && $metrics->depth >= $burstThreshold
        ) {
            $wantsBurst = true;
        }

        return new ScalingDecision(
            desiredBaseline: $desiredBaseline,
            desiredBurst: 0,
            wantsBurst: $wantsBurst,
            standbyReason: $standbyReason,
            effectiveMin: $effectiveMin,
        );
    }

    private function resolveStrategy(string $name): ScalingStrategy
    {
        return match ($name) {
            'depth' => new Profiles\DepthStrategy,
            'wait' => new Profiles\WaitStrategy,
            'hybrid' => new Profiles\HybridStrategy,
            default => throw new InvalidArgumentException("Unknown apex scaling strategy: {$name}"),
        };
    }
}
