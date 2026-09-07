<?php

namespace Symphoria\Apex\Console\Commands;

use Illuminate\Console\Command;
use Symphoria\Apex\Ipc\MetricsStore;

class ApexStatusCommand extends Command
{
    protected $signature = 'apex:status
        {--json : Output as JSON}
        {--watch : Continuously refresh (like watch -n)}
        {--interval=2 : Refresh interval in seconds when --watch is set}';

    protected $description = 'Show current Apex master and queue status';

    public function handle(MetricsStore $metrics): int
    {
        $isJson = (bool) $this->option('json');

        if (! $this->option('watch')) {
            return $this->render($metrics, $isJson);
        }

        $interval = max(1, (int) $this->option('interval'));

        while (true) {
            if (DIRECTORY_SEPARATOR !== '\\' && ! $isJson) {
                $this->output->write("\033[2J\033[H");
            }
            $this->render($metrics, $isJson);
            sleep($interval);
        }
    }

    private function render(MetricsStore $metrics, bool $isJson): int
    {
        $snapshot = $metrics->readSnapshot();

        if ($snapshot === null) {
            $this->warn('No Apex master snapshot found. Is apex:start running?');

            return self::FAILURE;
        }

        if ($isJson) {
            $this->line(json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $master = $snapshot['master'] ?? [];
        $totals = $snapshot['totals'] ?? [];

        $this->line('');
        $this->info('Apex master');
        $this->line(sprintf('  Uptime: %ds   Ticks: %d   Workers: %d   Memory: %.1f MB   Active: %s',
            $master['uptime_seconds'] ?? 0,
            $master['tick_count'] ?? 0,
            $totals['workers'] ?? 0,
            $totals['memory_mb'] ?? 0,
            ($master['is_active'] ?? false) ? 'yes' : 'no',
        ));
        $this->line('');

        $rows = [];
        foreach ($snapshot['queues'] ?? [] as $name => $q) {
            $rows[] = [
                $name,
                $q['profile'] ?? '-',
                $q['current_processes'] ?? 0,
                sprintf('%d/%d/%d', $q['min_processes'] ?? 0, $q['effective_min'] ?? 0, $q['max_processes'] ?? 0),
                $q['depth'] ?? 0,
                $q['oldest_wait_seconds'] !== null ? ($q['oldest_wait_seconds'].'s') : '-',
                $q['processed_last_minute'] ?? 0,
                $q['is_paused'] ? 'paused' : 'running',
            ];
        }

        $this->table(
            ['Queue', 'Profile', 'Workers', 'Min/Eff/Max', 'Depth', 'Oldest', 'Last 60s', 'State'],
            $rows,
        );

        return self::SUCCESS;
    }
}
