<?php

namespace Symphoria\Apex\Jobs\Bench;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Symphoria\Apex\Services\Bench\BenchResultRecorder;

class BenchNoOpJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(
        public string $runId,
        public int $sequence,
        public string $benchQueue = 'flex',
        public int $busyMicros = 0,
    ) {
        $this->onQueue($this->benchQueue);
    }

    public function handle(BenchResultRecorder $recorder): void
    {
        $start = microtime(true);
        $recorder->recordStart($this->runId, $this->sequence, $start);

        if ($this->busyMicros > 0) {
            usleep($this->busyMicros);
        }

        $recorder->recordFinish($this->runId, $this->sequence, microtime(true), 'completed');
    }

    public function failed(\Throwable $e): void
    {
        app(BenchResultRecorder::class)->recordFinish(
            $this->runId,
            $this->sequence,
            microtime(true),
            'failed',
            $e->getMessage(),
        );
    }
}
