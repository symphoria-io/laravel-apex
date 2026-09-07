<?php

namespace Symphoria\Apex\Console\Commands;

use Illuminate\Console\Command;
use Symphoria\Apex\Support\ApexConfig;

class ApexConfigShowCommand extends Command
{
    protected $signature = 'apex:config:show {--queue= : Show only the merged config for one queue} {--json : Output as JSON}';

    protected $description = 'Show the resolved Apex configuration (after merging profile, queue and environment overrides)';

    public function handle(): int
    {
        $config = ApexConfig::fromConfig();

        if ($queueName = $this->option('queue')) {
            if (! $config->hasQueue($queueName)) {
                $this->error("Unknown queue '{$queueName}'. Configured: ".implode(', ', $config->queueNames()));

                return self::FAILURE;
            }

            $resolved = $config->queue($queueName);

            if ($this->option('json')) {
                $this->line(json_encode($resolved, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            } else {
                foreach ($resolved as $key => $value) {
                    $this->line(sprintf('%-30s %s', $key, is_array($value) ? json_encode($value) : (string) $value));
                }
            }

            return self::SUCCESS;
        }

        $all = $config->allQueues();

        if ($this->option('json')) {
            $this->line(json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($all as $name => $q) {
            $rows[] = [
                $name,
                $q['profile'],
                implode(',', $q['queues']),
                $q['min_processes'],
                $q['min_processes_when_active'],
                $q['max_processes'],
                $q['use_activity'] ? 'yes' : 'no',
                $q['memory_mb'] ?? '-',
                $q['idle_timeout_seconds'] ?? '-',
            ];
        }

        $this->table(
            ['Queue', 'Profile', 'Reads', 'Min', 'Min(active)', 'Max', 'Activity', 'Mem MB', 'Idle s'],
            $rows,
        );

        $this->renderStore($config);

        return self::SUCCESS;
    }

    /**
     * Which store is active and, for the settings that have a per-store
     * default, whether the effective value was configured or inherited.
     * Without the provenance column "1000ms" tells you nothing about why.
     */
    private function renderStore(ApexConfig $config): void
    {
        $store = $config->store();
        $explicit = (string) (config('apex.store') ?? '');
        $raw = (array) config('apex.master', []);
        $master = $config->master();

        $this->newLine();
        $this->line(sprintf(
            '%-30s %s',
            'store',
            $store.($explicit !== '' ? ' (apex.store)' : ' (derived from queue driver)'),
        ));
        $this->line(sprintf('%-30s %s', 'queue depth probe', $config->queueDriverIsRedis() ? 'redis' : 'database'));

        $rows = [];
        foreach (array_keys((array) config("apex.stores.{$store}", [])) as $key) {
            $configured = $raw[$key] ?? null;

            $rows[] = [
                $key,
                (string) ($master[$key] ?? '-'),
                $configured === null || $configured === '' ? "default for '{$store}'" : 'configured',
            ];
        }

        if ($rows !== []) {
            $this->table(['Setting', 'Effective', 'Source'], $rows);
        }
    }
}
