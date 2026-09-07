<?php

declare(strict_types=1);

namespace Symphoria\Apex\Console\Commands;

use Illuminate\Console\Command;
use Symphoria\Apex\Contracts\QueueSuspensionSource;
use Symphoria\Apex\Ipc\ControlChannel;
use Symphoria\Apex\Support\ApexConfig;

class ApexReconcileCommand extends Command
{
    /** @var string */
    protected $signature = 'apex:reconcile';

    /** @var string */
    protected $description = 'Reconcile Apex Redis state with the application QueueSuspensionSource. Useful after upgrades or manual Redis edits.';

    public function handle(ControlChannel $control, ApexConfig $config, QueueSuspensionSource $suspension): int
    {
        $suspended = $suspension->suspendedQueues();
        $rows = [];

        foreach ($config->queueNames() as $queue) {
            $wasPaused = $control->isQueuePaused($queue);
            $wasSuspended = $control->isQueueSuspended($queue);
            $shouldRun = ! in_array($queue, $suspended, true);

            if ($shouldRun) {
                $control->resumeQueue($queue);
                $action = $wasSuspended ? 'cleared suspension' : 'no-op';
            } else {
                if ($wasPaused) {
                    $control->continueQueue($queue);
                }

                $control->suspendQueue($queue);
                $action = ($wasPaused ? 'cleared stale pause + ' : '')
                    .($wasSuspended ? 'already suspended' : 'suspended');
            }

            $rows[] = [
                $queue,
                $shouldRun ? 'y' : 'n',
                $wasPaused ? 'y' : 'n',
                $wasSuspended ? 'y' : 'n',
                $action,
            ];
        }

        if ($rows === []) {
            $this->info('No queues configured.');

            return self::SUCCESS;
        }

        $this->table(['queue', 'should_run', 'was_paused', 'was_suspended', 'action'], $rows);

        return self::SUCCESS;
    }
}
