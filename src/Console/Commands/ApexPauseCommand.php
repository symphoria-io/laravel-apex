<?php

namespace Symphoria\Apex\Console\Commands;

use Illuminate\Console\Command;
use Symphoria\Apex\Ipc\ControlChannel;
use Symphoria\Apex\Support\ApexConfig;

class ApexPauseCommand extends Command
{
    protected $signature = 'apex:pause {queue : Queue name to pause}';

    protected $description = 'Pause spawning new workers for a queue and stop existing idle workers';

    public function handle(ControlChannel $control): int
    {
        $queue = (string) $this->argument('queue');
        $config = ApexConfig::fromConfig();

        if (! $config->hasQueue($queue)) {
            $this->error("Unknown queue '{$queue}'. Configured: ".implode(', ', $config->queueNames()));

            return self::FAILURE;
        }

        $control->pauseQueue($queue);
        $this->info("Queue '{$queue}' paused.");

        return self::SUCCESS;
    }
}
