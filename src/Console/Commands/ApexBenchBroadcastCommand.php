<?php

namespace Symphoria\Apex\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Symphoria\Apex\Events\ApexTestBroadcast;

class ApexBenchBroadcastCommand extends Command
{
    protected $signature = 'apex:bench:broadcast
        {count=10000 : Number of broadcast jobs to dispatch}
        {--chunk=500 : Dispatch in chunks of this size (brief sleep between chunks to avoid Redis pipeline overload)}
        {--now : Use ShouldBroadcastNow — bypasses the broadcasts queue and fires inline (useful for comparison)}';

    protected $description = 'Dispatch N test broadcast events onto the broadcasts queue to benchmark Reverb throughput.';

    public function handle(): int
    {
        $count = max(1, (int) $this->argument('count'));
        $chunkSize = max(1, (int) $this->option('chunk'));
        $now = (bool) $this->option('now');
        $batchId = Str::uuid()->toString();

        $this->info(sprintf(
            'Dispatching %s test broadcasts (batch %s, chunk=%d, mode=%s)',
            number_format($count),
            $batchId,
            $chunkSize,
            $now ? 'now (inline)' : 'queue (broadcasts)',
        ));

        $bar = $this->output->createProgressBar($count);
        $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s%');
        $bar->start();

        $dispatched = 0;
        $startedAt = microtime(true);

        for ($i = 1; $i <= $count; $i++) {
            $event = new ApexTestBroadcast(
                sequence: $i,
                batchId: $batchId,
                dispatchedAt: microtime(true),
            );

            if ($now) {
                broadcast($event)->toOthers();
            } else {
                event($event);
            }

            $dispatched++;
            $bar->advance();

            if ($dispatched % $chunkSize === 0 && $dispatched < $count) {
                usleep(5000);
            }
        }

        $bar->finish();
        $this->newLine();

        $elapsed = round(microtime(true) - $startedAt, 2);
        $rate = $elapsed > 0 ? round($count / $elapsed) : '∞';

        $this->table(
            ['Metric', 'Value'],
            [
                ['Dispatched', number_format($count)],
                ['Batch ID', $batchId],
                ['Elapsed', $elapsed.'s'],
                ['Rate', $rate.' events/s'],
                ['Mode', $now ? 'inline (ShouldBroadcastNow)' : 'queued → broadcasts'],
            ],
        );

        return self::SUCCESS;
    }
}
