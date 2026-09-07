<?php

namespace Symphoria\Apex\Console\Commands;

use Illuminate\Console\Command;
use Symphoria\Apex\Ipc\MetricsStore;

/**
 * Live-tail completed jobs across all Apex queues, similar in spirit to
 * `tail -f`. For a refreshing snapshot view use `apex:status --watch`.
 */
class ApexTailCommand extends Command
{
    protected $signature = 'apex:tail
        {--interval=1 : Poll interval in seconds}
        {--queue= : Only show jobs for this Apex queue}
        {--status= : Only show jobs with this status (completed|errored|failed)}
        {--limit=200 : Max entries to scan per poll}';

    protected $description = 'Live tail of completed jobs across all Apex queues.';

    public function handle(MetricsStore $metrics): int
    {
        $interval = max(1, (int) $this->option('interval'));
        $queue = $this->option('queue') ?: null;
        $status = $this->option('status') ?: null;
        $limit = max(1, (int) $this->option('limit'));

        $lastSeenAt = 0.0;
        $initial = $metrics->recentJobs($queue, $status, 1);
        if (! empty($initial)) {
            $lastSeenAt = (float) ($initial[0]['finished_at'] ?? microtime(true));
        }

        $lastStartAt = microtime(true);

        $this->info(sprintf(
            'Tailing Apex jobs%s%s (Ctrl+C to stop)',
            $queue ? " queue=$queue" : '',
            $status ? " status=$status" : '',
        ));

        while (true) {
            // Starts (only when no status filter is set — starts don't have one)
            if ($status === null) {
                $starts = $metrics->recentJobStartsSince($lastStartAt, $limit);
                foreach ($starts as $entry) {
                    $startedAt = (float) ($entry['started_at'] ?? 0);
                    if ($startedAt <= $lastStartAt) {
                        continue;
                    }
                    if ($queue !== null && (string) ($entry['apex_queue'] ?? $entry['queue'] ?? '') !== $queue) {
                        $lastStartAt = max($lastStartAt, $startedAt);

                        continue;
                    }
                    $this->printStart($entry, $startedAt);
                    $lastStartAt = $startedAt;
                }
            }

            // Completions
            $jobs = $metrics->recentJobs($queue, $status, $limit);
            $newest = $lastSeenAt;
            foreach (array_reverse($jobs) as $job) {
                $finishedAt = (float) ($job['finished_at'] ?? 0);
                if ($finishedAt <= $lastSeenAt) {
                    continue;
                }
                $this->printJob($job, $finishedAt);
                if ($finishedAt > $newest) {
                    $newest = $finishedAt;
                }
            }
            $lastSeenAt = $newest;

            sleep($interval);
        }
    }

    private function printStart(array $entry, float $startedAt): void
    {
        $name = (string) ($entry['name'] ?? '-');
        $this->line(sprintf(
            '<fg=gray>[%s]</> <fg=blue>▶</> %-12s %-10s %-40s',
            date('H:i:s', (int) $startedAt),
            (string) ($entry['apex_queue'] ?? $entry['queue'] ?? '-'),
            'started',
            $this->truncate($name, 40),
        ));
    }

    private function printJob(array $job, float $finishedAt): void
    {
        $time = date('H:i:s', (int) $finishedAt);
        $queue = (string) ($job['apex_queue'] ?? $job['queue'] ?? '-');
        $name = (string) ($job['name'] ?? '-');
        $status = (string) ($job['status'] ?? '-');
        $runtime = $this->formatRuntime($job['runtime_ms'] ?? null);

        [$marker, $color] = match ($status) {
            'completed' => ['✓', 'info'],
            'errored' => ['!', 'comment'],
            'failed' => ['✗', 'error'],
            default => ['·', 'line'],
        };

        $this->line(sprintf(
            '<fg=gray>[%s]</> <%s>%s</> %-12s %-10s %-40s %s',
            $time,
            $color,
            $marker,
            $queue,
            $status,
            $this->truncate($name, 40),
            $runtime,
        ));

        $err = $job['exception']['message'] ?? $job['error'] ?? null;
        if (is_string($err) && $err !== '' && $status !== 'completed') {
            $this->line('           <fg=red>↳ '.$this->truncate($err, 200).'</>');
        }
    }

    private function formatRuntime(mixed $ms): string
    {
        if ($ms === null) {
            return '';
        }
        $ms = (float) $ms;
        if ($ms < 1000) {
            return sprintf('%dms', (int) $ms);
        }

        return sprintf('%.2fs', $ms / 1000);
    }

    private function truncate(string $value, int $max): string
    {
        if (mb_strlen($value) <= $max) {
            return $value;
        }

        return mb_substr($value, 0, $max - 1).'…';
    }
}
