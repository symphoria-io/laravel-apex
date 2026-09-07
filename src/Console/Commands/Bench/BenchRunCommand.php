<?php

namespace Symphoria\Apex\Console\Commands\Bench;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symphoria\Apex\Services\Bench\BenchContext;
use Symphoria\Apex\Services\Bench\BenchmarkRunner;
use Symphoria\Apex\Services\Bench\BenchResult;
use Symphoria\Apex\Services\Bench\ResultReporter;
use Symphoria\Apex\Services\Bench\ScenarioRegistry;
use Symphoria\Apex\Services\Bench\WorkerModeManager;

class BenchRunCommand extends Command
{
    protected $signature = 'apex:bench:run
                            {--scenario=noop : Scenario name(s): single name, comma-list, or "all" (see apex:bench:scenarios)}
                            {--mode=all : Worker mode: apex, queue, sync, or all}
                            {--iterations=20 : Iterations per mode}
                            {--concurrency=1 : Parallel dispatchers per mode}
                            {--await-timeout=30000 : Max milliseconds to wait for a single job to finish}
                            {--option=* : Scenario-specific key=value options (repeatable)}
                            {--output= : Write JSON results to this path (default: storage/bench/results/{timestamp})}
                            {--dry-run : Print plan only, do not execute}
                            {--force : Skip pre-flight production safety check}';

    protected $description = 'Run a benchmark scenario under one or more queue worker modes and compare latency/throughput.';

    public function handle(
        ScenarioRegistry $registry,
        BenchmarkRunner $runner,
        ResultReporter $reporter,
    ): int {
        $scenarioArg = (string) $this->option('scenario');
        $modeArg = (string) $this->option('mode');
        $iterations = (int) $this->option('iterations');
        $concurrency = max(1, (int) $this->option('concurrency'));
        $awaitTimeoutMs = (int) $this->option('await-timeout');

        if ($iterations < 1) {
            $this->error('--iterations must be at least 1.');

            return self::FAILURE;
        }

        $modes = $this->resolveModes($modeArg);

        if (empty($modes)) {
            $this->error('No valid modes selected.');

            return self::FAILURE;
        }

        $scenarioNames = $this->resolveScenarioNames($scenarioArg, $registry);

        if (empty($scenarioNames)) {
            $this->error('No valid scenarios selected.');

            return self::FAILURE;
        }

        $extraOptions = $this->parseOptions((array) $this->option('option'));
        $extraOptions['await_timeout_ms'] = $awaitTimeoutMs;

        $isMulti = count($scenarioNames) > 1;

        $this->line('');
        $this->info('Apex bench plan');
        $this->line(sprintf('  Scenarios    : %s', implode(', ', $scenarioNames)));
        $this->line(sprintf('  Modes        : %s', implode(', ', $modes)));
        $this->line(sprintf('  Iterations   : %d per mode', $iterations));
        $this->line(sprintf('  Concurrency  : %d', $concurrency));
        $this->line('');

        if ($this->option('dry-run')) {
            $this->comment('--dry-run: skipping execution.');

            return self::SUCCESS;
        }

        if (! $this->preFlightCheck()) {
            return self::FAILURE;
        }

        $outputPath = $this->option('output');

        if ($outputPath === null || $outputPath === '') {
            $outputPath = 'storage/bench/results/'.date('Ymd-His');
            $isMulti = true;
            $this->comment('No --output specified, defaulting to '.$outputPath);
        }

        $anyResults = false;

        foreach ($scenarioNames as $scenarioName) {
            $scenario = $registry->resolve($scenarioName);

            $this->line('');
            $this->info(sprintf('### Scenario: %s — %s', $scenario->name(), $scenario->description()));
            $this->line(sprintf('    Queue: %s', $scenario->queue() ?? '<sync, no queue>'));

            /** @var BenchResult[] $results */
            $results = [];

            foreach ($modes as $mode) {
                if ($mode !== 'sync' && $scenario->queue() === null) {
                    $this->comment(sprintf('    Skipping %s/%s: scenario is sync-only.', $scenario->name(), $mode));

                    continue;
                }

                $context = new BenchContext(
                    runId: $this->generateRunId($scenario->name(), $mode),
                    scenario: $scenario->name(),
                    mode: $mode,
                    iterations: $iterations,
                    concurrency: $concurrency,
                    options: $extraOptions,
                );

                $this->line('');
                $this->info(sprintf('>>> Running %s under mode=%s', $scenario->name(), $mode));

                $bar = $this->output->createProgressBar($iterations);
                $bar->start();

                try {
                    $result = $runner->run($scenario, $context, function () use ($bar) {
                        $bar->advance();
                    });
                } catch (\Throwable $e) {
                    $bar->finish();
                    $this->line('');
                    $this->error(sprintf('Mode %s failed: %s', $mode, $e->getMessage()));

                    continue;
                }

                $bar->finish();
                $this->line('');
                $results[] = $result;
            }

            if (empty($results)) {
                $this->warn(sprintf('No successful runs for scenario %s.', $scenario->name()));

                continue;
            }

            $anyResults = true;
            $reporter->renderConsole($this, $results);

            if ($outputPath) {
                $absolute = $this->resolveScenarioOutputPath((string) $outputPath, $scenario->name(), $isMulti);
                File::ensureDirectoryExists(dirname($absolute));
                File::put($absolute, $reporter->toJson($results, $this->systemInfo()));
                $this->line('');
                $this->info('Results written to '.$absolute);
            }
        }

        if (! $anyResults) {
            $this->error('No benchmark runs completed successfully.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function resolveScenarioNames(string $arg, ScenarioRegistry $registry): array
    {
        $available = $registry->names();

        if ($arg === 'all') {
            return $available;
        }

        $requested = array_values(array_filter(array_map('trim', explode(',', $arg)), fn ($n) => $n !== ''));
        $valid = [];
        $unknown = [];

        foreach ($requested as $name) {
            if (in_array($name, $available, true)) {
                $valid[] = $name;
            } else {
                $unknown[] = $name;
            }
        }

        if (! empty($unknown)) {
            $this->warn('Unknown scenarios skipped: '.implode(', ', $unknown).'. Available: '.implode(', ', $available));
        }

        return $valid;
    }

    private function resolveScenarioOutputPath(string $path, string $scenarioName, bool $isMulti): string
    {
        $absolute = $this->resolveOutputPath($path);

        if (! $isMulti) {
            return $absolute;
        }

        $isDir = is_dir($absolute) || str_ends_with($path, '/') || str_ends_with($path, '\\') || ! str_contains(basename($absolute), '.');

        if ($isDir) {
            return rtrim($absolute, '/\\').DIRECTORY_SEPARATOR.$scenarioName.'.json';
        }

        $info = pathinfo($absolute);
        $ext = isset($info['extension']) ? '.'.$info['extension'] : '.json';

        return ($info['dirname'] ?? '.').DIRECTORY_SEPARATOR.$info['filename'].'-'.$scenarioName.$ext;
    }

    private function resolveModes(string $arg): array
    {
        if ($arg === 'all') {
            return WorkerModeManager::MODES;
        }

        $modes = array_map('trim', explode(',', $arg));
        $modes = array_values(array_filter($modes, fn ($m) => in_array($m, WorkerModeManager::MODES, true)));

        return $modes;
    }

    private function parseOptions(array $pairs): array
    {
        $out = [];

        foreach ($pairs as $pair) {
            if (! str_contains($pair, '=')) {
                continue;
            }

            [$k, $v] = explode('=', $pair, 2);
            $k = trim($k);
            $v = trim($v);

            if (is_numeric($v)) {
                $v = str_contains($v, '.') ? (float) $v : (int) $v;
            } elseif (strtolower($v) === 'true') {
                $v = true;
            } elseif (strtolower($v) === 'false') {
                $v = false;
            }

            $out[$k] = $v;
        }

        return $out;
    }

    private function generateRunId(string $scenario, string $mode): string
    {
        return sprintf('%s-%s-%s', $scenario, $mode, substr(bin2hex(random_bytes(4)), 0, 8));
    }

    private function preFlightCheck(): bool
    {
        $env = app()->environment();

        if ($env === 'production' && ! $this->option('force')) {
            $this->error('Refusing to run benchmark in production without --force.');

            return false;
        }

        return true;
    }

    private function resolveOutputPath(string $path): string
    {
        if ($this->isAbsolutePath($path)) {
            return $path;
        }

        return base_path($path);
    }

    private function isAbsolutePath(string $path): bool
    {
        return $path !== '' && (
            $path[0] === '/'
            || (strlen($path) >= 3 && ctype_alpha($path[0]) && $path[1] === ':' && ($path[2] === '\\' || $path[2] === '/'))
        );
    }

    private function systemInfo(): array
    {
        return [
            'php' => PHP_VERSION,
            'os' => PHP_OS_FAMILY,
            'env' => app()->environment(),
            'app_version' => config('app.version'),
            'queue_default' => config('queue.default'),
        ];
    }
}
