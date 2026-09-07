<?php

namespace Symphoria\Apex\Console\Commands;

use Illuminate\Console\Command;
use Symphoria\Apex\Ipc\ControlChannel;

class ApexStopCommand extends Command
{
    protected $signature = 'apex:stop';

    protected $description = 'Request graceful shutdown of the running Apex master';

    public function handle(ControlChannel $control): int
    {
        $control->requestShutdown();
        $this->info('Shutdown signal sent. Apex master will exit on next tick and drain workers.');

        return self::SUCCESS;
    }
}
