<?php

namespace Symphoria\Apex\Services\Bench;

use Symphoria\Apex\Ipc\ActivityTracker;
use Symphoria\Apex\Services\Bench\Scenarios\BenchScenario;

class BenchmarkRunner
{
    public function __construct(
        private readonly WorkerModeManager $workerManager,
        private readonly ActivityTracker $activity,
    ) {}

    /**
     * @param  callable(int $sequence, BenchSample $sample): void|null  $onSample
     */
    public function run(BenchScenario $scenario, BenchContext $context, ?callable $onSample = null): BenchResult
    {
        $result = new BenchResult($context);

        $queue = $scenario->queue();
        $needsWorker = $queue !== null;

        if ($needsWorker) {
            $this->workerManager->activate($context->mode, $queue);
        }

        if ($context->mode === WorkerModeManager::MODE_APEX) {
            $this->warmFlexPool();
        }

        try {
            $scenario->prepare($context);

            for ($i = 0; $i < $context->iterations; $i++) {
                $sample = $scenario->dispatch($context, $i);
                $result->addSample($sample);

                if ($onSample !== null) {
                    $onSample($i, $sample);
                }
            }

            $result->finish();
        } finally {
            try {
                $scenario->cleanup($context);
            } catch (\Throwable $e) {
                // swallow cleanup errors to not mask actual failures
            }

            if ($needsWorker) {
                $this->workerManager->deactivate($context->mode);
            }
        }

        return $result;
    }

    /**
     * Simulate active web traffic so apex queues with `use_activity: true`
     * (flex, chat, chat-tools) use their `min_processes_when_active` baseline
     * instead of cold-starting on the first dispatch. Without this, a CLI
     * bench never trips the activity key the web middleware normally sets,
     * and we'd measure cold-spawn latency that real users never see.
     *
     * Waits briefly to give the master a few ticks to spawn the warm pool.
     */
    private function warmFlexPool(): void
    {
        $this->activity->mark();
        usleep(2_000_000);
    }
}
