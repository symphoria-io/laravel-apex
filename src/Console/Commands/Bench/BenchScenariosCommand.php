<?php

namespace Symphoria\Apex\Console\Commands\Bench;

use Illuminate\Console\Command;
use Symphoria\Apex\Services\Bench\ScenarioRegistry;

class BenchScenariosCommand extends Command
{
    protected $signature = 'apex:bench:scenarios';

    protected $description = 'List available benchmark scenarios.';

    public function handle(ScenarioRegistry $registry): int
    {
        $rows = [];

        foreach ($registry->names() as $name) {
            $scenario = $registry->resolve($name);
            $rows[] = [
                $scenario->name(),
                $scenario->queue() ?? '<sync>',
                $scenario->description(),
            ];
        }

        $this->table(['Name', 'Queue', 'Description'], $rows);

        return self::SUCCESS;
    }
}
