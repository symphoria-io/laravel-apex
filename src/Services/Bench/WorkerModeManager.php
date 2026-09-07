<?php

namespace Symphoria\Apex\Services\Bench;

use RuntimeException;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Symphoria\Apex\Ipc\HeartbeatStore;
use Symphoria\Apex\Ipc\MetricsStore;
use Symphoria\Apex\Support\ApexConfig;

class WorkerModeManager
{
    public const MODE_APEX = 'apex';

    public const MODE_QUEUE = 'queue';

    public const MODE_SYNC = 'sync';

    public const MODES = [self::MODE_APEX, self::MODE_QUEUE, self::MODE_SYNC];

    /** @var Process[] */
    private array $processes = [];

    private ?string $originalQueueConnection = null;

    public function __construct(
        private readonly ApexConfig $apexConfig,
        private readonly MetricsStore $metrics,
        private readonly HeartbeatStore $heartbeats,
    ) {}

    public function activate(string $mode, string $queue): void
    {
        $this->ensureClean();

        match ($mode) {
            self::MODE_SYNC => $this->activateSync(),
            self::MODE_QUEUE => $this->activateQueue($queue),
            self::MODE_APEX => $this->activateApex(),
            default => throw new RuntimeException("Unknown bench mode: {$mode}"),
        };
    }

    public function deactivate(string $mode): void
    {
        if ($mode === self::MODE_SYNC) {
            if ($this->originalQueueConnection !== null) {
                config(['queue.default' => $this->originalQueueConnection]);
                $this->originalQueueConnection = null;
            }

            return;
        }

        foreach ($this->processes as $process) {
            if ($process->isRunning()) {
                $process->stop(10, defined('SIGTERM') ? SIGTERM : 15);
            }
        }

        $this->processes = [];
    }

    private function activateSync(): void
    {
        $this->originalQueueConnection = config('queue.default');
        config(['queue.default' => 'sync']);
    }

    private function activateQueue(string $queue): void
    {
        $php = $this->phpBinary();
        $artisan = base_path('artisan');

        $process = new Process([
            $php,
            $artisan,
            'queue:work',
            '--queue='.$queue,
            '--tries=1',
            '--timeout=60',
            '--sleep=0',
        ], base_path(), null, null, null);

        $process->setTimeout(null);
        $process->start();

        $this->processes[] = $process;
        usleep(750_000);

        if (! $process->isRunning()) {
            throw new RuntimeException(
                'Failed to start plain queue:work worker for queue '.$queue.': '
                    .trim($process->getOutput()."\n".$process->getErrorOutput()),
            );
        }
    }

    private function activateApex(): void
    {
        $snapshot = $this->metrics->readSnapshot();

        if ($snapshot === null) {
            throw new RuntimeException(
                'Apex master is not running. Start it first with `php artisan apex:start`, then re-run the benchmark.',
            );
        }
    }

    private function ensureClean(): void
    {
        if (! empty($this->processes)) {
            throw new RuntimeException('WorkerModeManager already has active worker processes. Call deactivate() first.');
        }
    }

    private function phpBinary(): string
    {
        $finder = new PhpExecutableFinder;
        $php = $finder->find();

        if (! $php) {
            throw new RuntimeException('Could not locate PHP binary for spawning worker processes.');
        }

        return $php;
    }
}
