<?php

namespace Symphoria\Apex\Providers;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Symphoria\Apex\Apex;
use Symphoria\Apex\Console\Commands\ApexBenchBroadcastCommand;
use Symphoria\Apex\Console\Commands\ApexConfigShowCommand;
use Symphoria\Apex\Console\Commands\ApexContinueCommand;
use Symphoria\Apex\Console\Commands\ApexPauseCommand;
use Symphoria\Apex\Console\Commands\ApexPruneHistoryCommand;
use Symphoria\Apex\Console\Commands\ApexReconcileCommand;
use Symphoria\Apex\Console\Commands\ApexRestartCommand;
use Symphoria\Apex\Console\Commands\ApexStartCommand;
use Symphoria\Apex\Console\Commands\ApexStatusCommand;
use Symphoria\Apex\Console\Commands\ApexStopCommand;
use Symphoria\Apex\Console\Commands\ApexTailCommand;
use Symphoria\Apex\Console\Commands\ApexWorkCommand;
use Symphoria\Apex\Console\Commands\Bench\BenchRunCommand;
use Symphoria\Apex\Console\Commands\Bench\BenchScenariosCommand;
use Symphoria\Apex\Contracts\ApexStore;
use Symphoria\Apex\Contracts\QueueDepthProbe;
use Symphoria\Apex\Contracts\QueueSuspensionSource;
use Symphoria\Apex\Http\Middleware\Authorize;
use Symphoria\Apex\Http\Middleware\TrackApexActivity;
use Symphoria\Apex\Ipc\ActivityTracker;
use Symphoria\Apex\Ipc\ControlChannel;
use Symphoria\Apex\Ipc\Depth\DatabaseQueueDepthReader;
use Symphoria\Apex\Ipc\Depth\RedisQueueDepthReader;
use Symphoria\Apex\Ipc\HeartbeatStore;
use Symphoria\Apex\Ipc\MasterLock;
use Symphoria\Apex\Ipc\MetricsStore;
use Symphoria\Apex\Ipc\Stores\DatabaseStore;
use Symphoria\Apex\Ipc\Stores\RedisStore;
use Symphoria\Apex\Ipc\WakeSignal;
use Symphoria\Apex\Master\MetricsAggregator;
use Symphoria\Apex\Master\SystemLoad;
use Symphoria\Apex\Process\ProcessFactory;
use Symphoria\Apex\Queue\ApexRedisConnector;
use Symphoria\Apex\Queue\ApexWorker;
use Symphoria\Apex\Services\Bench\ScenarioRegistry;
use Symphoria\Apex\Support\ApexConfig;
use Symphoria\Apex\Support\NullSuspensionSource;

class ApexServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ApexConfig::class, fn () => ApexConfig::fromConfig());

        $this->app->singleton(ApexStore::class, function ($app) {
            $config = $app->make(ApexConfig::class);

            return $config->store() === 'redis'
                ? new RedisStore($config)
                : new DatabaseStore($config);
        });

        // Follows the queue driver, never the store: this one reads the jobs
        // themselves, so it has to speak whatever the queue is written in.
        $this->app->singleton(QueueDepthProbe::class, function ($app) {
            $config = $app->make(ApexConfig::class);

            return $config->queueDriverIsRedis()
                ? new RedisQueueDepthReader($config)
                : new DatabaseQueueDepthReader($config);
        });

        $this->app->singleton(ActivityTracker::class);
        $this->app->singleton(ControlChannel::class);
        $this->app->singleton(HeartbeatStore::class);
        $this->app->singleton(MasterLock::class);
        $this->app->singleton(MetricsStore::class);
        $this->app->singleton(WakeSignal::class);
        $this->app->singleton(ProcessFactory::class, fn () => new ProcessFactory);
        $this->app->singleton(SystemLoad::class);
        $this->app->singleton(MetricsAggregator::class);
        $this->app->singleton(ScenarioRegistry::class);
        $this->app->bind(QueueSuspensionSource::class, NullSuspensionSource::class);

        $this->app->singleton(ApexWorkCommand::class, function ($app) {
            return new ApexWorkCommand($app['queue.worker'], $app['cache.store']);
        });

        // Override Laravel's queue.worker singleton with ApexWorker. It is a
        // strict subclass: for non-ApexRedisQueue connections it falls back
        // to parent::getNextJob, so queue:work for other drivers is unaffected.
        $this->app->extend('queue.worker', function ($worker, $app) {
            return new ApexWorker(
                $app['queue'],
                $app['events'],
                $app[ExceptionHandler::class],
                fn () => $app->isDownForMaintenance(),
                $this->buildResetScope($app),
            );
        });

        $this->mergeConfigFrom(__DIR__.'/../../config/apex.php', 'apex');
    }

    private function buildResetScope($app): \Closure
    {
        return function () use ($app) {
            if (method_exists($app['log'], 'flushSharedContext')) {
                $app['log']->flushSharedContext();
            }
            if (method_exists($app['log'], 'withoutContext')) {
                $app['log']->withoutContext();
            }
            if (method_exists($app['db'], 'getConnections')) {
                foreach ($app['db']->getConnections() as $connection) {
                    $connection->resetTotalQueryDuration();
                    $connection->allowQueryDurationHandlersToRunAgain();
                }
            }
            $app->forgetScopedInstances();
            Facade::clearResolvedInstances();
            if (function_exists('memory_reset_peak_usage')) {
                memory_reset_peak_usage();
            }
        };
    }

    public function boot(): void
    {
        $this->registerLogChannel();
        $this->registerApexQueueDriver();
        $this->registerWakeSignal();
        $this->registerQueuedJobTracking();
        $this->registerAboutCommand();

        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands($this->consoleCommands());

            $this->publishes([
                __DIR__.'/../../config/apex.php' => $this->app->configPath('apex.php'),
            ], 'apex-config');
        }

        $this->registerRoutes();

        if (! $this->app->runningInConsole()) {
            $this->registerActivityMiddleware();
        }
    }

    /**
     * @return list<class-string>
     */
    private function consoleCommands(): array
    {
        return [
            ApexBenchBroadcastCommand::class,
            ApexConfigShowCommand::class,
            ApexContinueCommand::class,
            ApexPauseCommand::class,
            ApexPruneHistoryCommand::class,
            ApexReconcileCommand::class,
            ApexRestartCommand::class,
            ApexStartCommand::class,
            ApexStatusCommand::class,
            ApexStopCommand::class,
            ApexTailCommand::class,
            ApexWorkCommand::class,
            BenchRunCommand::class,
            BenchScenariosCommand::class,
        ];
    }

    /**
     * JSON control API for the dashboard. Deliberately no Inertia or Blade:
     * the package owns the data and the control actions, the host application
     * owns the screen.
     */
    private function registerRoutes(): void
    {
        if (! (bool) config('apex.dashboard.enabled', true)) {
            return;
        }

        Route::group([
            'prefix' => (string) config('apex.dashboard.path', 'apex'),
            'middleware' => array_merge(
                (array) config('apex.dashboard.middleware', ['web']),
                [Authorize::class],
            ),
            'as' => 'apex.',
        ], function (): void {
            $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');
        });
    }

    private function registerAboutCommand(): void
    {
        if (! class_exists(AboutCommand::class)) {
            return;
        }

        AboutCommand::add('Symphoria Apex', static fn (): array => [
            'Version' => Apex::version(),
            'Queue states table' => Apex::table('queue_states'),
            'Dashboard API' => config('apex.dashboard.enabled') ? 'enabled' : 'disabled',
            'BLMPOP' => config('apex.blmpop_enabled') ? 'enabled' : 'disabled',
        ]);
    }

    /**
     * Replace Laravel's 'redis' queue driver resolver with one that returns
     * ApexRedisQueue. Strict subclass — behaviour is identical unless our
     * ApexWorker calls popFromMultiple, so other consumers (e.g. Horizon,
     * stock queue:work) keep working.
     */
    private function registerApexQueueDriver(): void
    {
        if (! (bool) config('apex.blmpop_enabled', true)) {
            return;
        }

        Queue::extend('redis', function () {
            return new ApexRedisConnector($this->app->make('redis'));
        });
    }

    /**
     * Tell an idle master that something was dispatched.
     *
     * Deliberately not tied to `recent_jobs`: that switch governs what the
     * dashboard shows, and turning a reporting feature off must not stop the
     * supervisor from waking up.
     */
    private function registerWakeSignal(): void
    {
        Event::listen(JobQueued::class, function (): void {
            try {
                $this->app->make(WakeSignal::class)->mark();
            } catch (\Throwable $e) {
                // best-effort; never break dispatching
            }
        });
    }

    /**
     * Track jobs the moment they are dispatched (JobQueued) so the dashboard's
     * recent-jobs panel shows the full lifecycle: queued -> processing ->
     * completed/failed. Runs app-wide (web requests and workers that chain jobs).
     */
    private function registerQueuedJobTracking(): void
    {
        $cfg = (array) config('apex.recent_jobs', []);

        if (! ($cfg['enabled'] ?? true) || ! ($cfg['track_queued'] ?? true)) {
            return;
        }

        Event::listen(JobQueued::class, function (JobQueued $event): void {
            try {
                $payload = $event->payload();
            } catch (\Throwable $e) {
                $payload = [];
            }

            if (! is_array($payload)) {
                $payload = [];
            }

            $uuid = (string) ($payload['uuid'] ?? (is_scalar($event->id) ? (string) $event->id : ''));
            if ($uuid === '') {
                return;
            }

            $name = (string) ($payload['displayName']
                ?? (is_object($event->job) ? get_class($event->job) : (is_string($event->job) ? $event->job : 'job')));

            try {
                app(MetricsStore::class)->recordJobQueued([
                    'id' => $uuid,
                    'uuid' => $uuid,
                    'name' => $name,
                    'queue' => (string) ($event->queue ?? 'default'),
                    'connection' => (string) $event->connectionName,
                    'attempts' => 0,
                ]);
            } catch (\Throwable $e) {
                // best-effort; never break dispatching
            }
        });
    }

    private function registerActivityMiddleware(): void
    {
        if (! (bool) config('apex.activity.enabled', true)) {
            return;
        }

        Route::pushMiddlewareToGroup('web', TrackApexActivity::class);
    }

    private function registerLogChannel(): void
    {
        if (config('logging.channels.apex') !== null) {
            return;
        }

        config(['logging.channels.apex' => [
            'driver' => 'daily',
            'path' => storage_path('logs/apex.log'),
            'level' => config('apex.log_level', 'info'),
            'days' => 7,
            'replace_placeholders' => true,
        ]]);
    }
}
