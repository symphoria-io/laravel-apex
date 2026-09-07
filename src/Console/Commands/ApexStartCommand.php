<?php

namespace Symphoria\Apex\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\Console\Terminal;
use Symphoria\Apex\Contracts\QueueDepthProbe;
use Symphoria\Apex\Contracts\QueueSuspensionSource;
use Symphoria\Apex\Ipc\ActivityTracker;
use Symphoria\Apex\Ipc\ControlChannel;
use Symphoria\Apex\Ipc\HeartbeatStore;
use Symphoria\Apex\Ipc\MasterLock;
use Symphoria\Apex\Ipc\MetricsStore;
use Symphoria\Apex\Ipc\WakeSignal;
use Symphoria\Apex\Master\BootThrottle;
use Symphoria\Apex\Master\Master;
use Symphoria\Apex\Master\MetricsAggregator;
use Symphoria\Apex\Master\ScalingDecider;
use Symphoria\Apex\Master\SystemLoad;
use Symphoria\Apex\Master\WorkerRegistry;
use Symphoria\Apex\Process\ProcessFactory;
use Symphoria\Apex\Support\ApexConfig;

class ApexStartCommand extends Command
{
    protected $signature = 'apex:start
                            {--watch : Print every processed job inline (live tail)}
                            {--force : Start even when another master holds the lock}';

    protected $description = 'Start the Apex queue worker supervisor (master daemon)';

    public function handle(): int
    {
        $this->stopTelescopeRecording();

        $config = ApexConfig::fromConfig();

        // If preload is configured but not yet active, re-exec ourselves with
        // the right -d flags. This must happen BEFORE any heavy boot work so
        // the preload-equipped child gets the maximum benefit.
        $this->maybeReexecWithPreload($config);

        $lock = app(MasterLock::class);

        if (! $lock->acquire((bool) $this->option('force'))) {
            $this->renderLockHeld($lock);

            return self::FAILURE;
        }

        $master = new Master(
            config: $config,
            processFactory: app(ProcessFactory::class),
            registry: new WorkerRegistry,
            bootThrottle: BootThrottle::fromConfig($config),
            decider: new ScalingDecider,
            depthReader: app(QueueDepthProbe::class),
            heartbeats: app(HeartbeatStore::class),
            control: app(ControlChannel::class),
            activity: app(ActivityTracker::class),
            metrics: app(MetricsStore::class),
            wake: app(WakeSignal::class),
            systemLoad: app(SystemLoad::class),
            aggregator: app(MetricsAggregator::class),
            suspension: app(QueueSuspensionSource::class),
            output: $this->output,
            tailJobs: (bool) $this->option('watch'),
            lock: $lock,
        );

        $this->renderBanner($config);
        $this->checkQueueDriver();
        $this->checkRedisVersion($config);
        $this->checkForwardedQueues($config);

        $master->run();

        $this->newLine();
        $this->components->info('Apex master stopped.');

        return self::SUCCESS;
    }

    /**
     * Two masters on one installation cannot see each other's workers, so they
     * scale the same queues independently and spawn past every maximum. The
     * symptom looks like erratic scaling, which is why this refuses loudly.
     */
    private function renderLockHeld(MasterLock $lock): void
    {
        $holder = $lock->holder();

        $this->newLine();
        $this->output->writeln('  <fg=red;options=bold>✗  ANOTHER APEX MASTER IS ALREADY RUNNING</>');
        $this->newLine();

        if ($holder !== null) {
            $this->output->writeln(sprintf(
                '     <fg=gray>held by</> <fg=white>%s</> <fg=gray>pid</> <fg=white>%d</><fg=gray>, since</> <fg=white>%s</>',
                $holder['host'],
                $holder['pid'],
                $holder['since'] > 0 ? date('Y-m-d H:i:s', $holder['since']) : 'unknown',
            ));
            $this->newLine();
        }

        $this->output->writeln('     <fg=gray>Stop it first, or pass</> <fg=white>--force</> <fg=gray>if you are certain it is gone.</>');
        $this->newLine();
    }

    /**
     * Telescope buffers every query, redis call and log entry in memory and only
     * flushes them when the script exits. In a long-running daemon that means
     * unbounded growth until OOM. Disable recording for the master process.
     */
    private function stopTelescopeRecording(): void
    {
        $telescope = 'Laravel\\Telescope\\Telescope';
        if (class_exists($telescope)) {
            $telescope::stopRecording();
        }
    }

    private function renderBanner(ApexConfig $config): void
    {
        $env = app()->environment();
        $queues = $config->queueNames();

        $this->newLine();
        $this->output->writeln('  <fg=magenta;options=bold>APEX</> <fg=gray>queue supervisor</>');
        $this->output->writeln('  <fg=gray>env:</> <fg=cyan>'.$env.'</>  <fg=gray>queues:</> <fg=green>'.count($queues).'</>  <fg=gray>tick:</> <fg=cyan>'.($config->master()['tick_interval_ms'] ?? 250).'ms</>');

        if ($preloaded = $this->preloadStats()) {
            $this->output->writeln('  <fg=gray>opcache preload:</> <fg=green>'.$preloaded.'</>');
        }

        $this->output->writeln(
            '  <fg=gray>queues:</> '
            .implode('<fg=gray>, </>', array_map(fn ($q) => '<fg=white>'.$q.'</>', $queues))
        );
    }

    /**
     * Probe the Redis server we're talking to. If it's < 7 we warn that
     * blocking multi-key pops (BLMPOP, used for sub-second pickup latency)
     * are unavailable.
     */
    private function checkRedisVersion(ApexConfig $config): void
    {
        // BLMPOP is about how workers pick jobs up, so this is a question
        // about the queue driver, not about where Apex keeps its own state.
        if (! $config->queueDriverIsRedis()) {
            return;
        }

        try {
            $info = Redis::connection($config->connection())->info('server');
            $version = is_array($info)
                ? ($info['redis_version'] ?? ($info['Server']['redis_version'] ?? null))
                : null;
        } catch (\Throwable $e) {
            return;
        }

        if (! is_string($version) || $version === '') {
            return;
        }

        $major = (int) explode('.', $version)[0];

        $clientIsPhpredis = $this->redisClientIsPhpredis();
        $blmpopConfigured = (bool) config('apex.blmpop_enabled', true);

        if ($major >= 7) {
            if ($blmpopConfigured && $clientIsPhpredis) {
                $this->output->writeln('  <fg=gray>redis:</> <fg=green>'.$version.'</> <fg=gray>(BLMPOP active — sub-50ms pickup)</>');
            } elseif (! $blmpopConfigured) {
                $this->output->writeln('  <fg=gray>redis:</> <fg=green>'.$version.'</> <fg=yellow>(BLMPOP disabled via APEX_BLMPOP_ENABLED=false — using sleep polling)</>');
            } else {
                $this->output->writeln('  <fg=gray>redis:</> <fg=green>'.$version.'</> <fg=yellow>(predis client — BLMPOP path inactive, using sleep polling)</>');
            }

            return;
        }

        $isCritical = $major <= 5;
        $color = $isCritical ? 'red' : 'yellow';
        $marker = $isCritical ? '✗' : '⚠';

        $this->output->writeln('  <fg=gray>redis:</> <fg='.$color.'>'.$version.'</>');
        $this->output->writeln('  <fg='.$color.'>'.$marker.'</> <fg='.$color.'>Redis '.$version.' detected — Redis 7+ recommended (8 ideal).</>');
        $this->output->writeln('    <fg='.$color.'>BLMPOP (multi-queue blocking pop) requires 7+. Apex falls back to</>');
        $this->output->writeln('    <fg='.$color.'>polling with sleep_seconds — fine, just slightly less efficient.</>');
    }

    private function redisClientIsPhpredis(): bool
    {
        return ((string) config('database.redis.client', 'phpredis')) === 'phpredis'
            && class_exists('Redis');
    }

    /**
     * Loud red-background warning when the active queue driver is one Apex
     * cannot read backlog from. Redis and database are both supported; the
     * rest expose no way to ask "how much work is waiting", which is the one
     * thing the supervisor needs to decide anything.
     */
    private function checkQueueDriver(): void
    {
        $defaultConnection = (string) config('queue.default', 'sync');
        $driver = (string) config("queue.connections.{$defaultConnection}.driver", $defaultConnection);

        if (in_array($driver, ['redis', 'database'], true)) {
            return;
        }

        $width = max(60, min(96, (int) ($this->terminalWidth() ?? 80)));
        $inner = $width - 4;

        $line = fn (string $s = '') => '  <bg=red;fg=white>'.str_pad($s, $inner).'</>';
        $bold = fn (string $s = '') => '  <bg=red;fg=white;options=bold>'.str_pad($s, $inner).'</>';

        $this->newLine();
        $this->output->writeln($bold(''));
        $this->output->writeln($bold('  ✗  UNSUPPORTED QUEUE DRIVER'));
        $this->output->writeln($bold(''));
        $this->output->writeln($line("  queue.default = '{$defaultConnection}' (driver: {$driver})"));
        $this->output->writeln($line('  Apex reads pending work straight from the queue backend to'));
        $this->output->writeln($line("  decide how many workers to run. The '{$driver}' driver offers no"));
        $this->output->writeln($line('  way to do that, so jobs will NOT be picked up by Apex workers.'));
        $this->output->writeln($line(''));
        $this->output->writeln($bold('  Fix: set QUEUE_CONNECTION=redis or =database'));
        $this->output->writeln($bold(''));
        $this->newLine();
    }

    /**
     * Warn when `Queue::forward()` (Laravel 13.26+) sends dispatches somewhere
     * other than where Apex is looking.
     *
     * Apex reads backlog by queue name. A forwarded queue keeps receiving no
     * work while Apex watches it, so it reports zero depth forever and never
     * scales up — with nothing in the logs to explain why.
     */
    private function checkForwardedQueues(ApexConfig $config): void
    {
        // Laravel 12 binds nothing here, which is the whole version guard:
        // this file never names the class, so it stays loadable there.
        if (! app()->bound('queue.routes')) {
            return;
        }

        $routes = app('queue.routes');

        $connection = (string) config('queue.default');
        $forwarded = [];

        foreach ($config->allQueues() as $name => $settings) {
            foreach ($settings['queues'] ?? [$name] as $queue) {
                $target = (string) $routes->forwardedQueue((string) $queue, $connection);

                if ($target !== (string) $queue) {
                    $forwarded[] = [(string) $name, (string) $queue, $target];
                }
            }
        }

        if ($forwarded === []) {
            return;
        }

        $this->newLine();
        $this->output->writeln('  <fg=yellow>⚠  Queue::forward() redirects queues Apex is watching</>');

        foreach ($forwarded as [$group, $queue, $target]) {
            $this->output->writeln(sprintf(
                '     <fg=gray>%s reads</> <fg=white>%s</> <fg=gray>but dispatches now go to</> <fg=white>%s</>',
                $group,
                $queue,
                $target,
            ));
        }

        $this->output->writeln('     <fg=yellow>Point apex.queues at the new names, or Apex will see an empty queue.</>');
        $this->newLine();
    }

    private function terminalWidth(): ?int
    {
        try {
            return (new Terminal)->getWidth();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * If apex.master.preload is enabled and PHP wasn't started with
     * opcache.preload, replace this process with a new PHP invocation that
     * has the right -d flags. Uses pcntl_exec for clean PID continuity (so
     * systemd/supervisor sees the same process). Falls through silently if
     * preload is disabled, already active, the script is missing, or
     * pcntl_exec is unavailable.
     */
    private function maybeReexecWithPreload(ApexConfig $config): void
    {
        $master = $config->master();
        $cfg = (array) ($master['preload'] ?? []);

        if (! ($cfg['enabled'] ?? true)) {
            return;
        }

        // Avoid infinite re-exec loops if something goes wrong.
        if (getenv('APEX_PRELOAD_REEXECED') === '1') {
            return;
        }

        // Already preloaded? Nothing to do.
        if (function_exists('opcache_get_status')) {
            $status = @opcache_get_status(false);
            if (! empty($status['preload_statistics']['scripts'])) {
                return;
            }
        }

        // pcntl_exec is required for clean re-exec; on Windows or stripped
        // PHP builds we just skip preload silently.
        if (! function_exists('pcntl_exec')) {
            return;
        }

        $script = $cfg['script'] ?: __DIR__.'/../../Support/preload.php';
        $script = realpath($script) ?: $script;
        if (! is_file($script)) {
            return;
        }

        $args = [
            '-d', 'opcache.enable_cli=1',
            '-d', 'opcache.memory_consumption='.max(64, (int) ($cfg['memory_mb'] ?? 256)),
            '-d', 'opcache.max_accelerated_files='.max(5000, (int) ($cfg['max_files'] ?? 30000)),
            '-d', 'opcache.preload='.$script,
        ];

        // OPcache emits "Can't preload unlinked class" warnings directly from
        // the extension during preload phase, bypassing user error handlers.
        // The only reliable way to silence them is via PHP CLI flags. Set
        // APEX_PRELOAD_VERBOSE=1 if you want to see them for debugging.
        if (! getenv('APEX_PRELOAD_VERBOSE')) {
            $args[] = '-d';
            $args[] = 'display_errors=0';
            $args[] = '-d';
            $args[] = 'error_reporting='.(E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR);
        }

        // PHP requires opcache.preload_user when the master process is root,
        // otherwise it refuses to start preload as a security measure.
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $user = (string) ($cfg['preload_user'] ?? 'www-data');
            if ($user !== '') {
                $args[] = '-d';
                $args[] = 'opcache.preload_user='.$user;
            }
        }

        $args[] = base_path('artisan');
        $args[] = 'apex:start';
        if ($this->option('watch')) {
            $args[] = '--watch';
        }

        // Mark via env so the new process knows not to re-exec again.
        // Use getenv() (no args) to capture the FULL environment — $_ENV is
        // often partial (depends on php.ini variables_order) and would drop
        // TERM, COLORTERM, LANG etc., which makes Symfony Console disable
        // ANSI colors in the re-execed process. Force colors on regardless
        // by setting CLICOLOR_FORCE as a belt-and-braces signal.
        $env = getenv();
        if (! is_array($env)) {
            $env = $_ENV;
        }
        $env['APEX_PRELOAD_REEXECED'] = '1';
        $env['CLICOLOR_FORCE'] = '1';
        if (! isset($env['TERM']) || $env['TERM'] === '') {
            $env['TERM'] = 'xterm-256color';
        }
        putenv('APEX_PRELOAD_REEXECED=1');

        $this->components?->info('Apex: re-execing with OPcache preload ('.basename($script).')');

        // pcntl_exec replaces this process image. Returns false on failure;
        // on success it never returns. If it fails, we fall through and run
        // without preload (degraded but functional).
        @pcntl_exec(PHP_BINARY, $args, $env);
    }

    /**
     * Returns a short string describing the current preload state, or null
     * if preload isn't active. Used for the startup banner.
     */
    private function preloadStats(): ?string
    {
        if (! function_exists('opcache_get_status')) {
            return null;
        }
        $status = @opcache_get_status(false);
        if (empty($status['preload_statistics']['scripts'])) {
            return null;
        }
        // preload_statistics.scripts often only counts the top-level preload
        // file, not files compiled via opcache_compile_file(). The accurate
        // count lives under opcache_statistics.num_cached_scripts (when the
        // ENTIRE current shm content is preload — true for our master since
        // it hasn't run any user code yet at banner time).
        $scripts = (int) $status['preload_statistics']['scripts'];
        if (! empty($status['opcache_statistics']['num_cached_scripts'])) {
            $scripts = max($scripts, (int) $status['opcache_statistics']['num_cached_scripts']);
        }

        $memMb = isset($status['preload_statistics']['memory_consumption'])
            ? round($status['preload_statistics']['memory_consumption'] / 1024 / 1024, 1)
            : null;

        return $memMb !== null
            ? "{$scripts} files, {$memMb} MB shared"
            : "{$scripts} files";
    }
}
