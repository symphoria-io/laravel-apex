<?php

namespace Symphoria\Apex\Console\Commands;

use Illuminate\Console\Command;
use Symphoria\Apex\Contracts\ApexStore;
use Symphoria\Apex\Models\QueueMetricsHistory;

/**
 * Prune `the metrics history table` rows older than the given retention
 * window. The `--days=` option is required so this command can never be run
 * accidentally without specifying intent (e.g. `apex:prune-history --days=0`
 * would wipe everything — only allowed if explicitly passed).
 */
class ApexPruneHistoryCommand extends Command
{
    protected $signature = 'apex:prune-history
        {--days= : Retention window in days. Required. Rows with bucket_at older than now()-days are deleted.}';

    protected $description = 'Prune Apex queue metrics history older than --days. Run from the scheduler.';

    public function handle(): int
    {
        $daysOption = $this->option('days');

        if ($daysOption === null || $daysOption === '') {
            $this->error('--days is required (e.g. --days=30). Refusing to run without an explicit retention window.');

            return self::INVALID;
        }

        if (! is_numeric($daysOption)) {
            $this->error("--days must be numeric, got: {$daysOption}");

            return self::INVALID;
        }

        $days = (int) $daysOption;
        if ($days < 0) {
            $this->error('--days must be >= 0.');

            return self::INVALID;
        }

        $cutoff = now()->subDays($days);

        $deleted = QueueMetricsHistory::query()
            ->where('bucket_at', '<', $cutoff)
            ->delete();

        $this->info(sprintf(
            'Pruned %d queue-metrics-history rows older than %s (%d day%s).',
            $deleted,
            $cutoff->toDateTimeString(),
            $days,
            $days === 1 ? '' : 's',
        ));

        $this->sweepExpiredStoreRows();

        return self::SUCCESS;
    }

    /**
     * A store that expires lazily accumulates lapsed rows. Sweeping here
     * rather than on a schedule of its own keeps the number of moving parts
     * down: this is the maintenance command an installation already runs.
     */
    private function sweepExpiredStoreRows(): void
    {
        $swept = app(ApexStore::class)->sweep();

        if ($swept > 0) {
            $this->info(sprintf('Swept %d expired Apex store rows.', $swept));
        }
    }
}
