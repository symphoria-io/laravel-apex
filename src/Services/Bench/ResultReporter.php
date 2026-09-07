<?php

namespace Symphoria\Apex\Services\Bench;

use Illuminate\Console\Command;

class ResultReporter
{
    /**
     * @param  BenchResult[]  $results
     */
    public function renderConsole(Command $command, array $results): void
    {
        if (empty($results)) {
            $command->warn('No bench results to display.');

            return;
        }

        $first = $results[0];
        $context = $first->context;

        $command->line('');
        $command->info(sprintf(
            'Scenario: %s   Iterations: %d   Concurrency: %d   Run id: %s',
            $context->scenario,
            $context->iterations,
            $context->concurrency,
            $context->runId,
        ));
        $command->line('');

        $headers = ['Metric'];
        foreach ($results as $result) {
            $headers[] = strtoupper($result->context->mode);
        }

        $rows = [];
        $metrics = [
            ['Completed', 'completed', null],
            ['Failed', 'failed', null],
            ['Walltime (s)', 'walltime_sec', null],
            ['Throughput (op/s)', 'throughput_per_sec', null],
            ['Total p50 (ms)', 'total_ms', 'p50'],
            ['Total p95 (ms)', 'total_ms', 'p95'],
            ['Total p99 (ms)', 'total_ms', 'p99'],
            ['Total avg (ms)', 'total_ms', 'avg'],
            ['Total max (ms)', 'total_ms', 'max'],
            ['Wait p50 (ms)', 'wait_ms', 'p50'],
            ['Wait p95 (ms)', 'wait_ms', 'p95'],
            ['Run p50 (ms)', 'run_ms', 'p50'],
            ['Run p95 (ms)', 'run_ms', 'p95'],
            ['Dispatch p50 (ms)', 'dispatch_ms', 'p50'],
            ['Memory peak avg (MB)', 'memory_mb', 'avg'],
            ['Memory peak max (MB)', 'memory_mb', 'max'],
        ];

        $summaries = array_map(fn (BenchResult $r) => $r->summary(), $results);

        foreach ($metrics as [$label, $key, $sub]) {
            $row = [$label];
            foreach ($summaries as $summary) {
                $row[] = $this->format($summary, $key, $sub);
            }
            $rows[] = $row;
        }

        $command->table($headers, $rows);

        if (count($results) >= 2) {
            $this->renderDelta($command, $summaries);
        }

        $errors = [];
        foreach ($summaries as $summary) {
            foreach ($summary['errors'] ?? [] as $err) {
                if ($err) {
                    $errors[] = sprintf('[%s] %s', $summary['mode'], $err);
                }
            }
        }

        if (! empty($errors)) {
            $command->line('');
            $command->warn('Errors observed:');
            foreach (array_slice($errors, 0, 10) as $err) {
                $command->line('  - '.$err);
            }
        }
    }

    /**
     * @param  BenchResult[]  $results
     */
    public function toJson(array $results, array $systemInfo = []): string
    {
        return json_encode([
            'generated_at' => date(DATE_ATOM),
            'system' => $systemInfo,
            'runs' => array_map(fn (BenchResult $r) => $r->toArray(), $results),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param  array<int,array<string,mixed>>  $summaries
     */
    private function renderDelta(Command $command, array $summaries): void
    {
        $command->line('');
        $command->info('Delta vs first mode ('.$summaries[0]['mode'].')');

        $base = $summaries[0];
        $headers = ['Metric'];
        $rows = [];

        for ($i = 1; $i < count($summaries); $i++) {
            $headers[] = strtoupper($summaries[$i]['mode']).' vs '.strtoupper($base['mode']);
        }

        $metricRows = [
            ['Total p50', 'total_ms', 'p50'],
            ['Total p95', 'total_ms', 'p95'],
            ['Total avg', 'total_ms', 'avg'],
            ['Throughput', 'throughput_per_sec', null],
        ];

        foreach ($metricRows as [$label, $key, $sub]) {
            $row = [$label];
            $baseVal = $this->value($base, $key, $sub);

            for ($i = 1; $i < count($summaries); $i++) {
                $val = $this->value($summaries[$i], $key, $sub);
                $row[] = $this->formatDelta($baseVal, $val, $key === 'throughput_per_sec');
            }

            $rows[] = $row;
        }

        $command->table($headers, $rows);
    }

    private function value(array $summary, string $key, ?string $sub): ?float
    {
        $val = $summary[$key] ?? null;

        if (is_array($val)) {
            $val = $val[$sub] ?? null;
        }

        return is_numeric($val) ? (float) $val : null;
    }

    private function format(array $summary, string $key, ?string $sub): string
    {
        $val = $this->value($summary, $key, $sub);

        if ($val === null) {
            return '-';
        }

        return number_format($val, 2);
    }

    private function formatDelta(?float $base, ?float $other, bool $higherIsBetter): string
    {
        if ($base === null || $other === null || $base == 0.0) {
            return '-';
        }

        $diffPct = (($other - $base) / $base) * 100;
        $sign = $diffPct >= 0 ? '+' : '';
        $arrow = '';

        if ($higherIsBetter) {
            $arrow = $diffPct > 5 ? ' (better)' : ($diffPct < -5 ? ' (worse)' : '');
        } else {
            $arrow = $diffPct < -5 ? ' (better)' : ($diffPct > 5 ? ' (worse)' : '');
        }

        return sprintf('%s%s%%%s', $sign, number_format($diffPct, 1), $arrow);
    }
}
