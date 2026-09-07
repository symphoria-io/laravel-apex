<?php

namespace Symphoria\Apex\Console\Commands;

use Illuminate\Console\Command;
use Symphoria\Apex\Ipc\ControlChannel;

class ApexRestartCommand extends Command
{
    protected $signature = 'apex:restart';

    protected $description = 'Request graceful shutdown of all running Apex workers (supervisor will respawn the master)';

    public function handle(ControlChannel $control): int
    {
        $control->requestShutdown();
        $this->info('Restart signal sent. Workers will quit; supervisor must respawn master.');

        return self::SUCCESS;
    }
}
