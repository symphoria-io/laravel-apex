<?php

namespace Symphoria\Apex\Console\Commands;

use Illuminate\Console\Command;
use Symphoria\Apex\Ipc\ControlChannel;
use Symphoria\Apex\Support\ApexConfig;

class ApexContinueCommand extends Command
{
    protected $signature = 'apex:continue {queue : Queue name to resume}';

    protected $description = 'Resume worker scaling for a previously paused queue';

    public function handle(ControlChannel $control): int
    {
        $queue = (string) $this->argument('queue');
        $config = ApexConfig::fromConfig();

        if (! $config->hasQueue($queue)) {
            $this->error("Unknown queue '{$queue}'. Configured: ".implode(', ', $config->queueNames()));

            return self::FAILURE;
        }

        $control->continueQueue($queue);
        $this->info("Queue '{$queue}' resumed.");

        return self::SUCCESS;
    }
}
