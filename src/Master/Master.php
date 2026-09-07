<?php

namespace Symphoria\Apex\Master;

use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\Log;
use Symphoria\Apex\Contracts\QueueDepthProbe;
use Symphoria\Apex\Contracts\QueueSuspensionSource;
use Symphoria\Apex\Ipc\ActivityTracker;
use Symphoria\Apex\Ipc\ControlChannel;
use Symphoria\Apex\Ipc\HeartbeatStore;
use Symphoria\Apex\Ipc\MasterLock;
use Symphoria\Apex\Ipc\MetricsStore;
use Symphoria\Apex\Ipc\WakeSignal;
use Symphoria\Apex\Models\QueueState;
use Symphoria\Apex\Process\ProcessFactory;
use Symphoria\Apex\Support\ApexConfig;
use Symphoria\Apex\Support\NullSuspensionSource;

class Master
{
    private bool $running = true;

    private float $startedAt;

    private int $tickCount = 0;

    private ?array $lastCpuSample = null;

    /** @var array<string, float> per-queue last burst spawn timestamp (microtime). */
    private array $lastBurstAt = [];

    /** @var array<string, float> per-queue first time depth went above burst threshold. */
    private array $burstThresholdSince = [];

    /** @var array<string, float> per-queue last burst_blocked_cpu emit timestamp. */
    private array $lastBurstBlockedAt = [];

    /** @var array<int, float> pid => microtime when shrink SIGTERM was last sent. */
    private array $shrinkSignalledAt = [];

    /** @var array<string, float> per-queue first tick at which baseline exceeded the desired count. */
    private array $overBaselineSince = [];

    /** @var array<string, array<string, mixed>> apexId => heartbeat, refreshed once per tick. */
    private array $heartbeatCache = [];

    /** @var array<string, array{paused: bool, suspended: bool}> refreshed once per tick. */
    private array $queueStates = [];

    /** True when the previous tick saw no workers, no depth and no user activity. */
    private bool $lastTickIdle = false;

    /**
     * How long an idle master may coast on the wake key before doing a full
     * read anyway. Bounds the damage if something pushes onto a queue without
     * going through this application's dispatcher.
     */
    private const FULL_TICK_INTERVAL_SECONDS = 30;

    private float $lastFullTickAt = 0.0;

    private ProcessMemoryReader $memoryReader;

    private float $lastTailScore = 0.0;

    private float $lastTailStartScore = 0.0;

    public function __construct(
        private readonly ApexConfig $config,
        private readonly ProcessFactory $processFactory,
        private readonly WorkerRegistry $registry,
        private readonly BootThrottle $bootThrottle,
        private readonly ScalingDecider $decider,
        private readonly QueueDepthProbe $depthReader,
        private readonly HeartbeatStore $heartbeats,
        private readonly ControlChannel $control,
        private readonly ActivityTracker $activity,
        private readonly MetricsStore $metrics,
        private readonly WakeSignal $wake,
        private readonly SystemLoad $systemLoad,
        private readonly MetricsAggregator $aggregator,
        private readonly QueueSuspensionSource $suspension = new NullSuspensionSource,
        private readonly ?OutputStyle $output = null,
        private readonly bool $tailJobs = false,
        ?ProcessMemoryReader $memoryReader = null,
        private readonly ?MasterLock $lock = null,
    ) {
        $this->memoryReader = $memoryReader ?? new ProcessMemoryReader;
        $this->startedAt = microtime(true);
        $this->lastFullTickAt = $this->startedAt;
        $this->lastTailScore = microtime(true);
        $this->lastTailStartScore = $this->lastTailScore;
    }

    public function run(): void
    {
        $this->registerSignalHandlers();
        $this->control->clearShutdown();
        $this->reconcileSuspendedQueues();
        $this->rehydratePauseState();
        $this->log('info', 'Apex master starting');

        try {
            $this->loop();
        } finally {
            $this->shutdown();
        }
    }

    private function loop(): void
    {
        $master = $this->config->master();
        $tickIntervalUs = (int) ($master['tick_interval_ms'] ?? 250) * 1000;
        $idleTickIntervalUs = max($tickIntervalUs, (int) ($master['idle_tick_interval_ms'] ?? 1000) * 1000);
        $snapshotIntervalSeconds = (int) ($master['metrics_snapshot_interval_seconds'] ?? 1);
        $idleSnapshotIntervalSeconds = max($snapshotIntervalSeconds, (int) ($master['idle_snapshot_interval_seconds'] ?? 5));
        $lastSnapshotAt = 0;

        while ($this->running) {
            $tickStart = microtime(true);

            try {
                $this->tick();
            } catch (\Throwable $e) {
                $this->log('error', 'Tick failed: '.$e->getMessage());
            }

            // A master with no workers, no backlog and no active users has
            // nothing to react to. Backing off there is what keeps an idle
            // installation from polling Redis several hundred times a second.
            $snapshotEvery = $this->lastTickIdle ? $idleSnapshotIntervalSeconds : $snapshotIntervalSeconds;

            // Reporting is not the supervisor's job. A store that cannot
            // answer must not take down the process that owns the workers.
            try {
                if (time() - $lastSnapshotAt >= $snapshotEvery) {
                    $this->writeSnapshot();
                    $lastSnapshotAt = time();
                }

                if ($this->tailJobs) {
                    $this->printTailJobs();
                }
            } catch (\Throwable $e) {
                $this->log('error', 'Post-tick bookkeeping failed: '.$e->getMessage());
            }

            // Deliberately its own try: if a failing snapshot could skip this,
            // a broken store would make the master unstoppable through the
            // control channel, which is the one way left to stop it cleanly.
            try {
                if ($this->control->isShutdownRequested()) {
                    $this->log('info', 'Shutdown requested via control channel');
                    $this->running = false;
                }
            } catch (\Throwable $e) {
                $this->log('error', 'Could not read the control channel: '.$e->getMessage());
            }

            try {
                $this->lock?->refresh();

                if ($this->lock !== null && ! $this->lock->heldByThisProcess()) {
                    $this->log('error', 'Lost the master lock; another master has taken over. Standing down.');
                    $this->running = false;
                }
            } catch (\Throwable $e) {
                $this->log('error', 'Could not refresh the master lock: '.$e->getMessage());
            }

            if (! $this->running) {
                break;
            }

            $intervalUs = $this->lastTickIdle ? $idleTickIntervalUs : $tickIntervalUs;
            $elapsedUs = (int) ((microtime(true) - $tickStart) * 1_000_000);

            if (! ($elapsedUs > $intervalUs)) {
                usleep($intervalUs - $elapsedUs);
            }
        }
    }

    private function tick(): void
    {
        $this->tickCount++;

        if ($this->canSkipIdleTick()) {
            return;
        }

        $allQueues = $this->config->allQueues();

        // One batched read per Redis structure, reused by every helper below
        // and by writeSnapshot(). Without this the same queue depth and worker
        // heartbeat are fetched three or four times per tick.
        $this->depthReader->resetCache();
        $this->depthReader->prefetch(array_map(
            fn (string $name, array $cfg) => $cfg['queues'] ?? [$name],
            array_keys($allQueues),
            $allQueues,
        ));
        $this->heartbeatCache = $this->heartbeats->many($this->registry->apexIds());
        $this->queueStates = $this->control->statesFor(array_keys($allQueues));

        $this->reapDeadProcesses();

        $isActive = $this->activity->isActive();
        $busyByQueue = $this->countBusyByQueue();
        $flexIdleByQueue = $this->countFlexIdleByQueue();
        $processedByQueue = $this->metrics->processedRecentMany(array_keys($allQueues), 1);

        // First pass: baseline scaling per queue. Collect queues that want to burst.
        $burstCandidates = [];
        $actionableDepth = 0;
        foreach ($allQueues as $name => $queueConfig) {
            $state = $this->queueStates[$name] ?? ['paused' => false, 'suspended' => false];

            // Backlog on a paused or disabled queue is not something this
            // master may act on, so it must not keep the master spinning at
            // full tick rate waiting for work it is forbidden to pick up.
            if (! $state['paused'] && ! $state['suspended']) {
                $actionableDepth += $this->depthReader->depth($queueConfig['queues'] ?? [$name]);
            }

            $decision = $this->scaleQueue(
                $name,
                $queueConfig,
                $isActive,
                $busyByQueue[$name] ?? 0,
                $flexIdleByQueue[$name] ?? 0,
                $processedByQueue[$name] ?? 0,
            );
            if ($decision !== null && $decision->wantsBurst) {
                $burstCandidates[$name] = [$queueConfig, $decision];
            }
        }

        if ($burstCandidates !== []) {
            $this->processBurstPass($burstCandidates);
        } else {
            // Nothing wants to burst — do not sample CPU at all.
            $this->lastCpuSample = null;
        }

        $this->bootThrottle->expireStuckBoots(60);

        $this->lastTickIdle = ! $isActive
            && $actionableDepth === 0
            && $this->registry->totalCount() === 0;
    }

    /**
     * An idle master with no workers still reads every queue depth, every
     * control flag and every counter on each tick, only to conclude nothing
     * happened. Dispatching sets a single wake key, so the idle tick can
     * answer the same question with one read instead of dozens.
     *
     * Safe because it is not in the pickup path: a warm worker takes a job
     * off the queue itself: the master only decides whether to scale. And it
     * only applies with zero workers running, so there is nothing to reap or
     * shrink either.
     *
     * The periodic full tick is the safety net for work that entered the
     * queue without passing through this application's dispatcher.
     */
    private function canSkipIdleTick(): bool
    {
        if (! $this->lastTickIdle || $this->registry->totalCount() > 0) {
            return false;
        }

        $now = microtime(true);

        if ($now - $this->lastFullTickAt >= self::FULL_TICK_INTERVAL_SECONDS) {
            $this->lastFullTickAt = $now;

            return false;
        }

        // Someone arriving is a reason to scale even though nothing was
        // dispatched: `min_processes_when_active` exists so that nobody waits
        // for a cold boot, and a skipped tick never reads activity at all.
        if ($this->activity->isActive()) {
            $this->lastFullTickAt = $now;

            return false;
        }

        $wake = $this->wake;

        if (! $wake->isSet()) {
            return true;
        }

        $wake->clear();
        $this->lastFullTickAt = $now;

        return false;
    }

    /**
     * @return array<string, int>
     */
    private function countBusyByQueue(): array
    {
        $busy = [];
        foreach ($this->registry->all() as $worker) {
            $state = $this->heartbeatCache[$worker->apexId]['state'] ?? null;
            if ($state === 'working') {
                $busy[$worker->queueName] = ($busy[$worker->queueName] ?? 0) + 1;
            }
        }

        return $busy;
    }

    /**
     * Build a map of `actualQueueName => idleFlexWorkerCount`.
     *
     * For every flex worker that is currently IDLE (not processing a job), we
     * credit each of the actual Laravel queues it listens on. The scheduler
     * subtracts this from each dedicated queue's desired worker count so the
     * dedicated pool only spawns when flex workers can no longer cover demand.
     *
     * Idle is defined narrowly as state in {waiting, booted} — a worker that's
     * shutting down or paused doesn't count.
     *
     * @return array<string, int>
     */
    private function countFlexIdleByQueue(): array
    {
        $flexConfigs = $this->config->flexQueues();
        if ($flexConfigs === []) {
            return [];
        }

        // A flex worker only "covers" its sub-queues when the flex pool
        // itself isn't saturated. If flex's own backlog already exceeds what
        // its workers can immediately drain, an idle moment is just a brief
        // gap between two flex jobs — it will not realistically serve
        // dedicated queues. In that case, credit nothing so dedicated queues
        // are free to spawn their own (standby) workers.
        $flexCovers = [];
        foreach ($flexConfigs as $flexName => $flexCfg) {
            $subQueues = $flexCfg['queues'] ?? [];
            if ($subQueues === []) {
                $flexCovers[$flexName] = false;

                continue;
            }
            $flexDepth = $this->depthReader->depth($subQueues);
            $flexMax = max(1, (int) ($flexCfg['max_processes'] ?? 1));
            // Saturated when total backlog meaningfully exceeds the pool's
            // immediate capacity (heuristic: more pending work than workers).
            $flexCovers[$flexName] = $flexDepth <= $flexMax;
        }

        $out = [];
        foreach ($this->registry->all() as $worker) {
            if (! isset($flexConfigs[$worker->queueName])) {
                continue;
            }
            if (! ($flexCovers[$worker->queueName] ?? false)) {
                continue;
            }
            $hb = $this->heartbeatCache[$worker->apexId] ?? null;
            $state = $hb['state'] ?? null;
            // Count as idle when:
            //  - actively waiting/booted (state present), OR
            //  - heartbeat not yet written (freshly spawned in-flight) — this
            //    closes the recycle race where a replacement is mid-boot but
            //    dedicated queues would otherwise see flex_idle drop and
            //    spawn unnecessary workers that get killed next tick.
            // Exclude only workers that are clearly NOT going to serve soon:
            // working, stopping, paused, idle-exit, shutdown-requested.
            $idleStates = ['waiting', 'booted', null];
            if (! in_array($state, $idleStates, true)) {
                continue;
            }
            // Trust heartbeat 'queues' first (worker's actual listen list);
            // fall back to the flex config if the heartbeat is sparse or
            // the worker hasn't reported yet.
            $listen = $hb['queues'] ?? $flexConfigs[$worker->queueName]['queues'] ?? [];
            foreach ($listen as $q) {
                $q = is_string($q) ? trim($q) : '';
                if ($q === '') {
                    continue;
                }
                $out[$q] = ($out[$q] ?? 0) + 1;
            }
        }

        return $out;
    }

    /**
     * Compute the bottleneck coverage = minimum idle flex workers across all
     * sub-queues this dedicated queue listens on. If any sub-queue has zero
     * idle flex coverage, the dedicated queue gets zero (the missing sub
     * would block dispatching anyway).
     *
     * @param  array<int, string>  $subQueues
     * @param  array<string, int>  $flexIdleByQueue
     */
    private function minFlexCovering(array $subQueues, array $flexIdleByQueue): int
    {
        if ($subQueues === []) {
            return 0;
        }
        $min = PHP_INT_MAX;
        foreach ($subQueues as $sub) {
            $min = min($min, (int) ($flexIdleByQueue[$sub] ?? 0));
        }

        return $min === PHP_INT_MAX ? 0 : $min;
    }

    private function scaleQueue(
        string $name,
        array $queueConfig,
        bool $isActive,
        int $busyWorkers,
        int $flexAvailable = 0,
        int $processedRecent = 0,
    ): ?ScalingDecision {
        $queues = $queueConfig['queues'] ?? [$name];
        $depth = $this->depthReader->depth($queues);
        $oldest = $this->depthReader->oldestWaitSeconds($queues);

        $metrics = new QueueMetrics($depth, $oldest, $processedRecent);

        $state = $this->queueStates[$name] ?? ['paused' => false, 'suspended' => false];
        $isPaused = $state['paused'] || $state['suspended'];
        $current = $this->registry->countForQueue($name);
        $baselineCurrent = $this->registry->countBaselineForQueue($name);

        $decision = $this->decider->decide(
            $queueConfig,
            $metrics,
            $isActive,
            $isPaused,
            $current,
            $busyWorkers,
            $flexAvailable,
        );

        $max = (int) ($queueConfig['max_processes'] ?? $current);

        if (config('apex.debug_scaling') && ($depth > 0 || $current > 0 || $decision->desiredBaseline > 0)) {
            $this->log('debug', sprintf(
                'scale queue=%s depth=%d oldest=%s active=%s busy=%d flex_idle=%d current=%d/baseline=%d desired=%d max=%d burst_wanted=%s',
                $name,
                $depth,
                $oldest === null ? '-' : $oldest.'s',
                $isActive ? 'y' : 'n',
                $busyWorkers,
                $flexAvailable,
                $current,
                $baselineCurrent,
                $decision->desiredBaseline,
                $max,
                $decision->wantsBurst ? 'y' : 'n',
            ));
        }

        if ($decision->desiredBaseline > $baselineCurrent) {
            unset($this->overBaselineSince[$name]);
            $needed = $decision->desiredBaseline - $baselineCurrent;
            for ($i = 0; $i < $needed; $i++) {
                if (! $this->bootThrottle->canSpawn()) {
                    $this->recordEvent('boot_throttled', $name, [
                        'depth' => $depth,
                        'oldest_wait_seconds' => $oldest,
                        'current' => $current,
                        'desired' => $decision->desiredBaseline,
                    ]);
                    break;
                }
                // Workers that make up the queue's floor are meant to stay
                // warm; they must not self-exit on idle or the master will
                // immediately respawn them (spawn/idle-exit churn).
                $isFloor = ($baselineCurrent + 1) <= $decision->effectiveMin;
                $this->spawnWorker(
                    $name,
                    $queueConfig,
                    $depth,
                    $oldest,
                    $current,
                    $decision->desiredBaseline,
                    false,
                    $decision->standbyReason,
                    null,
                    $isFloor,
                );
                $current++;
                $baselineCurrent++;
            }
        } elseif ($isPaused && $current > 0) {
            $this->signalQueueShrink($name, $current, $depth, 'paused');
        } elseif ($baselineCurrent > $max) {
            $this->signalQueueShrink($name, $baselineCurrent - $max, $depth, 'over_max');
        } elseif ($baselineCurrent > $decision->desiredBaseline) {
            // Active scale-down: we have more baseline workers than the
            // decider wants (e.g. flex coverage came online, or active->idle
            // transition lowered min_processes_when_active). signalQueueShrink
            // only kills idle workers (booted/waiting/idle-exit), so busy
            // workers are never interrupted.
            //
            // Scale down asymmetrically: up immediately, down only after the
            // surplus has held for a while. Flex coverage flaps for a second
            // or two whenever a flex worker recycles, and reacting to that
            // flap is what produced the spawn/kill oscillation.
            $since = $this->overBaselineSince[$name] ??= microtime(true);
            $debounce = (float) ($this->config->master()['scale_down_debounce_seconds'] ?? 20);

            if ((microtime(true) - $since) >= $debounce) {
                $this->signalQueueShrink(
                    $name,
                    $baselineCurrent - $decision->desiredBaseline,
                    $depth,
                    'over_baseline',
                );
            }
        } else {
            unset($this->overBaselineSince[$name]);
        }

        return $decision;
    }

    /**
     * @param  array<string, array{0: array, 1: ScalingDecision}>  $candidates
     */
    private function processBurstPass(array $candidates): void
    {
        $burstCfg = $this->config->burst();

        if (! ($burstCfg['enabled'] ?? true)) {
            $this->lastCpuSample = null;

            return;
        }

        $sample = $this->systemLoad->sample((int) ($burstCfg['cpu_sample_cache_ms'] ?? 2000));
        $this->lastCpuSample = $sample;
        $cpuMax = (int) ($burstCfg['cpu_max_percent'] ?? 70);
        $globalMaxExtra = (int) ($burstCfg['global_max_extra'] ?? 5);
        $maxPerTick = max(1, (int) ($burstCfg['max_per_tick'] ?? 1));
        $spawnIntervalMs = max(0, (int) ($burstCfg['spawn_interval_ms'] ?? 1500));
        $burstedTotal = $this->registry->countBurstTotal();
        $spawnedThisTick = 0;
        $now = microtime(true);

        if ($sample['percent'] >= $cpuMax) {
            // Throttle: don't spam the event log every tick while CPU stays
            // above the threshold. Emit at most once per queue per 30s.
            foreach ($candidates as $name => [$queueConfig, $decision]) {
                $lastBlocked = $this->lastBurstBlockedAt[$name] ?? 0.0;
                if (($now - $lastBlocked) < 30.0) {
                    continue;
                }
                $this->recordEvent('burst_blocked_cpu', $name, [
                    'cpu_percent' => $sample['percent'],
                    'cpu_max_percent' => $cpuMax,
                ]);
                $this->lastBurstBlockedAt[$name] = $now;
            }

            return;
        }

        foreach ($candidates as $name => [$queueConfig, $decision]) {
            if ($spawnedThisTick >= $maxPerTick) {
                break;
            }
            if ($burstedTotal >= $globalMaxExtra) {
                $this->recordEvent('burst_global_capped', $name, [
                    'global_max_extra' => $globalMaxExtra,
                    'cpu_percent' => $sample['percent'],
                ]);
                break;
            }

            $perQueueMax = max(0, (int) ($queueConfig['burst_max_extra'] ?? 0));
            $currentBurst = $this->registry->countBurstForQueue($name);
            if ($currentBurst >= $perQueueMax) {
                continue;
            }

            // Per-queue cooldown: give the previous burst worker time to start
            // doing work before we spawn another one.
            $lastAt = $this->lastBurstAt[$name] ?? 0.0;
            if ($spawnIntervalMs > 0 && (($now - $lastAt) * 1000) < $spawnIntervalMs) {
                continue;
            }

            $depth = $this->depthReader->depth($queueConfig['queues'] ?? [$name]);
            $threshold = (int) ($queueConfig['burst_threshold_depth'] ?? 0);
            if ($depth < $threshold) {
                unset($this->burstThresholdSince[$name]);

                continue;
            }

            // Debounce: depth must stay above the threshold for a configurable
            // sustained window before the FIRST burst worker spawns. This
            // prevents overreacting to short spikes that drain on their own.
            // Once at least one burst is up, additional bursts skip the
            // sustained check (cooldown + per-queue cap already throttle).
            $sustainedMs = max(0, (int) ($queueConfig['burst_threshold_sustained_ms']
                ?? $burstCfg['threshold_sustained_ms'] ?? 0));
            if ($currentBurst === 0 && $sustainedMs > 0) {
                $since = $this->burstThresholdSince[$name] ?? null;
                if ($since === null) {
                    $this->burstThresholdSince[$name] = $now;

                    continue;
                }
                if ((($now - $since) * 1000) < $sustainedMs) {
                    continue;
                }
            }

            if (! $this->bootThrottle->canSpawn()) {
                $this->recordEvent('boot_throttled', $name, [
                    'depth' => $depth,
                    'burst' => true,
                ]);

                continue;
            }

            $this->spawnWorker(
                $name,
                $queueConfig,
                $depth,
                null,
                $this->registry->countForQueue($name),
                $decision->desiredBaseline + $currentBurst + 1,
                true,
                null,
                $sample['percent'],
            );
            $burstedTotal++;
            $spawnedThisTick++;
            $this->lastBurstAt[$name] = $now;
            unset($this->burstThresholdSince[$name]);
        }
    }

    private function spawnWorker(
        string $queueName,
        array $queueConfig,
        int $depth = 0,
        ?int $oldest = null,
        int $current = 0,
        int $desired = 0,
        bool $burst = false,
        ?string $reason = null,
        ?float $cpuPercent = null,
        bool $floor = false,
    ): void {
        $apexId = bin2hex(random_bytes(8));

        try {
            $handle = $this->processFactory->spawnWorker($apexId, $queueName, $queueConfig, $burst, $floor);
        } catch (\Throwable $e) {
            $this->log('error', "Failed to spawn worker for {$queueName}: ".$e->getMessage());
            $this->recordEvent('spawn_failed', $queueName, [
                'depth' => $depth,
                'oldest_wait_seconds' => $oldest,
                'current' => $current,
                'desired' => $desired,
                'error' => $e->getMessage(),
                'burst' => $burst,
            ]);

            return;
        }

        $this->registry->add($handle);
        $this->bootThrottle->noteSpawn($handle->pid);
        $this->log('info', sprintf(
            'Spawned %sworker pid=%d queue=%s%s',
            $burst ? 'burst ' : '',
            $handle->pid,
            $queueName,
            $reason ? " reason={$reason}" : '',
        ));

        $extra = [
            'pid' => $handle->pid,
            'apex_id' => $apexId,
            'depth' => $depth,
            'oldest_wait_seconds' => $oldest,
            'current' => $current + 1,
            'desired' => $desired,
        ];
        if ($burst) {
            $extra['burst'] = true;
            $extra['cpu_percent'] = $cpuPercent;
        }
        if ($reason !== null) {
            $extra['reason'] = $reason;
        }
        $this->recordEvent($burst ? 'burst_spawned' : 'spawned', $queueName, $extra);
    }

    private function signalQueueShrink(string $queueName, int $excess, int $depth = 0, string $reason = 'over_max'): void
    {
        $workers = $this->registry->forQueue($queueName);

        usort($workers, fn (WorkerHandle $a, WorkerHandle $b) => $a->startedAt <=> $b->startedAt);

        $now = microtime(true);

        // Never kill a worker that has barely started. A worker costs a full
        // framework boot; killing one seconds after spawning it is pure waste
        // and is the signature of two control loops fighting. Overload and
        // operator-driven stops are exempt.
        $minLifetime = (float) ($this->config->master()['shrink_min_lifetime_seconds'] ?? 45);
        $enforceMinLifetime = ! in_array($reason, ['paused', 'over_max', 'shutdown'], true);

        for ($i = 0; $i < min($excess, count($workers)); $i++) {
            $worker = $workers[$i];

            if ($enforceMinLifetime && ($now - $worker->startedAt) < $minLifetime) {
                continue;
            }

            // Exactly one component may retire a given worker, or the two
            // race each other. A worker that can time itself out owns that
            // decision; the master only steps in for workers that cannot
            // (floor workers, spawned with --idle-timeout=0). This applies
            // when the queue is simply idle — a real surplus (flex coverage
            // arriving while work is still queued) is still handled below.
            if ($reason === 'over_baseline' && $depth === 0 && ! $worker->isFloor) {
                continue;
            }

            $hb = $this->heartbeatCache[$worker->apexId] ?? $this->heartbeats->read($worker->apexId);

            if (! $hb || ! in_array($hb['state'] ?? '', ['booted', 'waiting', 'idle-exit'], true)) {
                continue;
            }

            // Already signalled and still winding down — skip to avoid log
            // spam and event flood while the worker exits gracefully.
            // The worker is removed from this map when reaped.
            if (isset($this->shrinkSignalledAt[$worker->pid])) {
                continue;
            }

            $this->processFactory->terminate($worker);
            $this->shrinkSignalledAt[$worker->pid] = $now;
            $this->log('info', "Signalled idle worker pid={$worker->pid} queue={$queueName} to stop");
            $this->recordEvent('shrink_signalled', $queueName, [
                'pid' => $worker->pid,
                'apex_id' => $worker->apexId,
                'depth' => $depth,
                'reason' => $reason,
            ]);
        }
    }

    private function reapDeadProcesses(): void
    {
        foreach ($this->registry->all() as $worker) {
            if (! $this->processFactory->isAlive($worker)) {
                $this->processFactory->close($worker);
                $this->registry->remove($worker->pid);
                $this->bootThrottle->noteDied($worker->pid);
                $this->heartbeats->delete($worker->apexId);
                unset($this->shrinkSignalledAt[$worker->pid]);
                $this->log('info', "Reaped dead worker pid={$worker->pid} queue={$worker->queueName}");
                $queueDepth = 0;
                try {
                    $queueConfig = $this->config->allQueues()[$worker->queueName] ?? null;
                    $queueDepth = $queueConfig
                        ? $this->depthReader->depth($queueConfig['queues'] ?? [$worker->queueName])
                        : 0;
                } catch (\Throwable $e) {
                    // best-effort; do not break reap
                }
                $this->recordEvent('reaped', $worker->queueName, [
                    'pid' => $worker->pid,
                    'apex_id' => $worker->apexId,
                    'depth' => $queueDepth,
                    'lifetime_seconds' => (int) (microtime(true) - $worker->startedAt),
                ]);

                continue;
            }

            $hb = $this->heartbeatCache[$worker->apexId] ?? null;
            if ($hb && ($hb['state'] ?? '') !== 'booted') {
                $this->bootThrottle->noteBooted($worker->pid);
            }
        }
    }

    private function writeSnapshot(): void
    {
        $queues = [];
        $isActive = $this->activity->isActive();
        $flexIdleByQueue = $this->countFlexIdleByQueue();

        // Read worker heartbeats first so we can attribute memory to queues.
        // Master overrides the worker-reported PHP allocator value with a
        // fresh /proc RSS read whenever available.
        $workersMemory = 0;
        $workers = [];
        $memoryByQueue = []; // queue name => ['total' => float, 'count' => int]
        foreach ($this->heartbeats->many($this->registry->apexIds()) as $apexId => $hb) {
            $pid = (int) ($hb['pid'] ?? 0);
            $rss = $this->memoryReader->residentMb($pid);
            if ($rss > 0) {
                $hb['memory_mb'] = $rss;
            }
            $workers[] = $hb;
            $mem = (float) ($hb['memory_mb'] ?? 0);
            $workersMemory += $mem;

            $queueName = (string) ($hb['queue'] ?? '');
            if ($queueName !== '') {
                $memoryByQueue[$queueName] ??= ['total' => 0.0, 'count' => 0];
                $memoryByQueue[$queueName]['total'] += $mem;
                $memoryByQueue[$queueName]['count']++;
            }
        }
        $masterMemory = $this->memoryReader->residentMb(getmypid() ?: 0);
        $totalMemory = $masterMemory + $workersMemory;

        $allQueues = $this->config->allQueues();
        $queueNamesAll = array_keys($allQueues);
        $processedByQueue = $this->metrics->processedRecentMany($queueNamesAll, 1);
        $summaries = $this->metrics->queueSummaries($queueNamesAll);

        foreach ($allQueues as $name => $queueConfig) {
            $queueNames = $queueConfig['queues'] ?? [$name];
            $depth = $this->depthReader->depth($queueNames);
            $oldest = $this->depthReader->oldestWaitSeconds($queueNames);
            $current = $this->registry->countForQueue($name);
            $burstCurrent = $this->registry->countBurstForQueue($name);
            $state = $this->queueStates[$name] ?? ['paused' => false, 'suspended' => false];
            $isPaused = $state['paused'];
            $isSuspended = $state['suspended'];
            $effectiveMin = ($queueConfig['use_activity'] ?? false) && $isActive
                ? $queueConfig['min_processes_when_active']
                : $queueConfig['min_processes'];

            $memEntry = $memoryByQueue[$name] ?? ['total' => 0.0, 'count' => 0];
            $memTotal = round($memEntry['total'], 1);
            $memAvg = $memEntry['count'] > 0 ? round($memEntry['total'] / $memEntry['count'], 1) : 0.0;

            $queues[$name] = [
                'name' => $name,
                'profile' => $queueConfig['profile'] ?? null,
                'queues' => $queueNames,
                'depth' => $depth,
                'oldest_wait_seconds' => $oldest,
                'processed_last_minute' => $processedByQueue[$name] ?? 0,
                'processed_total' => $summaries[$name]['processed_total'] ?? 0,
                'current_processes' => $current,
                'baseline_processes' => $this->registry->countBaselineForQueue($name),
                'burst_processes' => $burstCurrent,
                'min_processes' => $queueConfig['min_processes'],
                'min_processes_when_active' => $queueConfig['min_processes_when_active'],
                'effective_min' => $effectiveMin,
                'max_processes' => $queueConfig['max_processes'],
                'use_activity' => $queueConfig['use_activity'],
                'standby_when_active' => (int) ($queueConfig['standby_when_active'] ?? 0),
                'burst_enabled' => (bool) ($queueConfig['burst_enabled'] ?? false),
                'burst_threshold_depth' => (int) ($queueConfig['burst_threshold_depth'] ?? 0),
                'burst_max_extra' => (int) ($queueConfig['burst_max_extra'] ?? 0),
                'is_paused' => $isPaused,
                'is_suspended' => $isSuspended,
                'group' => $queueConfig['group'] ?? null,
                'is_flex' => (bool) ($queueConfig['is_flex'] ?? false),
                'standby_counts_flex' => (bool) ($queueConfig['standby_counts_flex'] ?? true),
                // For dedicated queues: minimum idle flex workers across all
                // sub-queues this queue listens on (= the actual coverage
                // bottleneck). Drives the dashboard "+N flex covers" hint
                // and explains why dedicated workers may not have spawned.
                // Always 0 for flex queues themselves.
                'flex_covering' => (bool) ($queueConfig['is_flex'] ?? false)
                    ? 0
                    : $this->minFlexCovering($queueNames, $flexIdleByQueue),
                'memory_mb_total' => $memTotal,
                'memory_mb_avg' => $memAvg,
                'last_job' => $summaries[$name]['last_job'] ?? null,
            ];

            $this->aggregator->recordSample($name, $queues[$name]);
        }

        $processedTotal = 0;
        $processedLastMinute = 0;
        foreach ($queues as $q) {
            $processedTotal += (int) ($q['processed_total'] ?? 0);
            $processedLastMinute += (int) ($q['processed_last_minute'] ?? 0);
        }

        $this->metrics->writeSnapshot([
            'master' => [
                'started_at' => (int) $this->startedAt,
                'uptime_seconds' => max(0, time() - (int) $this->startedAt),
                'tick_count' => $this->tickCount,
                'is_active' => $isActive,
                'last_activity_at' => $this->activity->lastActivityAt(),
            ],
            'totals' => [
                'workers' => count($workers),
                'memory_mb' => round($totalMemory, 1),
                'master_memory_mb' => round($masterMemory, 1),
                'workers_memory_mb' => round($workersMemory, 1),
                'queues' => count($queues),
                'processed_total' => $processedTotal,
                'processed_last_minute' => $processedLastMinute,
                'burst_workers' => $this->registry->countBurstTotal(),
            ],
            'cpu' => $this->lastCpuSample,
            'queues' => $queues,
            'workers' => $workers,
        ]);
    }

    private function shutdown(): void
    {
        $this->log('info', 'Shutting down workers');

        foreach ($this->registry->all() as $worker) {
            $this->processFactory->terminate($worker);
        }

        $deadline = time() + (int) ($this->config->master()['graceful_shutdown_seconds'] ?? 10);

        while (time() < $deadline && $this->registry->totalCount() > 0) {
            try {
                $this->reapDeadProcesses();
            } catch (\Throwable $e) {
                // Nothing here may stop the loop from reaching the kill below.
            }

            usleep(200_000);
        }

        // Whatever is still up ignored SIGTERM. close() waits for the process
        // to end, so without this the grace period is not a bound on anything
        // and a wedged worker hangs the master's own exit.
        foreach ($this->registry->all() as $worker) {
            $this->log('warning', "Worker pid={$worker->pid} queue={$worker->queueName} did not stop in time; killing");
            $this->processFactory->kill($worker);
        }

        foreach ($this->registry->all() as $worker) {
            $this->processFactory->close($worker);
            $this->registry->remove($worker->pid);
        }

        try {
            $this->aggregator->flush();
        } catch (\Throwable $e) {
            $this->log('error', 'Could not flush metrics on shutdown: '.$e->getMessage());
        }

        try {
            $this->lock?->release();
        } catch (\Throwable $e) {
            $this->log('error', 'Could not release the master lock: '.$e->getMessage());
        }

        $this->log('info', 'Apex master stopped');
    }

    private function registerSignalHandlers(): void
    {
        if (! function_exists('pcntl_signal')) {
            return;
        }

        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, fn () => $this->running = false);
        pcntl_signal(SIGINT, fn () => $this->running = false);
    }

    /**
     * Reconcile every queue with the application's QueueSuspensionSource.
     * Suspended queues get the `suspended` Redis key and any stale manual
     * pause key is cleared; everything else has the key removed. Runs on boot
     * so a Redis flush, an upgrade or a missed application event self-heals on
     * the next start.
     */
    private function reconcileSuspendedQueues(): void
    {
        try {
            $suspended = $this->suspension->suspendedQueues();
        } catch (\Throwable $e) {
            $this->log('warning', 'Could not reconcile suspended queues: '.$e->getMessage());

            return;
        }

        $cleared = 0;
        $disabled = 0;
        $enabled = 0;

        foreach ($this->config->queueNames() as $queue) {
            if (! in_array($queue, $suspended, true)) {
                $this->control->resumeQueue($queue);
                $enabled++;

                continue;
            }

            if ($this->control->isQueuePaused($queue)) {
                $this->control->continueQueue($queue);
                $cleared++;
            }

            $this->control->suspendQueue($queue);
            $disabled++;
        }

        $this->log('info', "Reconciled queues: running={$enabled}, suspended={$disabled}, stale-pause-keys-cleared={$cleared}");
    }

    /**
     * Mirror persistent manual-pause state from the database into Redis on
     * startup, so that a Redis flush (or a fresh master process) doesn't
     * silently un-pause queues that an admin had paused.
     */
    private function rehydratePauseState(): void
    {
        try {
            $rows = QueueState::query()
                ->where('is_paused_manually', true)
                ->pluck('queue_name');
        } catch (\Throwable $e) {
            // Migration may not have run yet, or DB unavailable. Don't block boot.
            $this->log('warning', 'Could not rehydrate pause state from DB: '.$e->getMessage());

            return;
        }

        $known = $this->config->queueNames();
        $rehydrated = 0;
        foreach ($rows as $queueName) {
            if (! in_array($queueName, $known, true)) {
                continue;
            }
            $this->control->pauseQueue($queueName);
            $rehydrated++;
        }

        if ($rehydrated > 0) {
            $this->log('info', "Rehydrated {$rehydrated} paused queue(s) from database");
        }
    }

    private function recordEvent(string $type, string $queue, array $extra = []): void
    {
        try {
            $this->metrics->recordWorkerEvent(array_merge([
                'at' => microtime(true),
                'type' => $type,
                'queue' => $queue,
            ], $extra));
        } catch (\Throwable $e) {
            // best-effort; never break the master loop
        }
    }

    /**
     * Print every job finished since the previous tail check. Called from the
     * main loop only when --watch was passed to apex:start.
     */
    private function printTailJobs(): void
    {
        if ($this->output === null) {
            return;
        }

        try {
            $starts = $this->metrics->recentJobStartsSince($this->lastTailStartScore, 200);
        } catch (\Throwable $e) {
            $starts = [];
        }

        foreach ($starts as $entry) {
            $startedAt = (float) ($entry['started_at'] ?? 0);
            if ($startedAt <= $this->lastTailStartScore) {
                continue;
            }
            $this->lastTailStartScore = $startedAt;

            $name = (string) ($entry['name'] ?? '-');
            if (mb_strlen($name) > 50) {
                $name = mb_substr($name, 0, 49).'…';
            }

            $this->output->writeln(sprintf(
                '<fg=blue;options=bold>[JOB]</> <fg=gray>%s</> <fg=blue>▶</> %-12s %-50s <fg=gray>started</>',
                date('H:i:s', (int) $startedAt),
                (string) ($entry['apex_queue'] ?? $entry['queue'] ?? '-'),
                $name,
            ));
        }

        try {
            $jobs = $this->metrics->recentJobsSince($this->lastTailScore, 200);
        } catch (\Throwable $e) {
            return;
        }

        foreach ($jobs as $job) {
            $finishedAt = (float) ($job['finished_at'] ?? 0);
            if ($finishedAt <= $this->lastTailScore) {
                continue;
            }
            $this->lastTailScore = $finishedAt;

            $status = (string) ($job['status'] ?? '-');
            [$marker, $color] = match ($status) {
                'completed' => ['✓', 'green'],
                'errored' => ['!', 'yellow'],
                'failed' => ['✗', 'red'],
                default => ['·', 'gray'],
            };

            $name = (string) ($job['name'] ?? '-');
            if (mb_strlen($name) > 50) {
                $name = mb_substr($name, 0, 49).'…';
            }

            $runtimeMs = (int) ($job['runtime_ms'] ?? 0);
            $runtime = $runtimeMs < 1000
                ? $runtimeMs.'ms'
                : sprintf('%.2fs', $runtimeMs / 1000);

            $this->output->writeln(sprintf(
                '<fg=%s;options=bold>[JOB]</> <fg=gray>%s</> <fg=%s>%s</> %-12s %-50s <fg=gray>%s</>',
                $color,
                date('H:i:s', (int) $finishedAt),
                $color,
                $marker,
                (string) ($job['apex_queue'] ?? $job['queue'] ?? '-'),
                $name,
                $runtime,
            ));
        }
    }

    private function log(string $level, string $message): void
    {
        if ($this->output !== null) {
            $tag = strtoupper($level);
            $time = date('H:i:s');
            $color = match ($level) {
                'error' => 'red',
                'warning' => 'yellow',
                'debug' => 'gray',
                default => 'cyan',
            };
            $line = sprintf(
                '<fg=%s;options=bold>[%s]</> <fg=gray>%s</> %s',
                $color,
                $tag,
                $time,
                $this->highlightMessage($message),
            );
            $this->output->writeln($line);
        }

        Log::channel('apex')->{$level}($message);
    }

    private function highlightMessage(string $message): string
    {
        $message = preg_replace('/\bpid=(\d+)\b/', '<fg=magenta>pid=$1</>', $message) ?? $message;
        $message = preg_replace('/\bqueue=([\w-]+)/', '<fg=green>queue=$1</>', $message) ?? $message;

        return $message;
    }
}
