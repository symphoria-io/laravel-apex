<?php

use Symphoria\Apex\Models\QueueMetricsHistory;
use Symphoria\Apex\Models\QueueState;

return [

    /*
    | Override a model by pointing this at your own class that extends the
    | package model. Apex::queueStateModel() validates that at the point of use.
    */
    'models' => [
        'queue_state' => QueueState::class,
        'queue_metrics_history' => QueueMetricsHistory::class,
    ],

    /*
    | Table names come from here, including inside the migrations, so an
    | existing installation can keep its own names without a data migration.
    */
    'table_names' => [
        'queue_states' => 'apex_queue_states',
        'queue_metrics_history' => 'apex_queue_metrics_history',
        'store' => 'apex_store',
        'timeline' => 'apex_timeline',
    ],

    /*
    | Where Apex keeps its own coordination state: heartbeats, control flags,
    | activity. Not the queue backend — that stays Laravel's.
    |
    | null derives it from your queue driver: redis if the queue is on redis,
    | database otherwise. That way an application without Redis needs no
    | configuration at all, and one with Redis does not pay for a second store.
    */
    'store' => env('APEX_STORE'),

    'stores' => [
        'redis' => [
            'tick_interval_ms' => 250,
            'idle_tick_interval_ms' => 1000,
            'metrics_snapshot_interval_seconds' => 1,
            'heartbeat_min_write_interval_ms' => 1000,
        ],
        'database' => [
            // Each tick is a query rather than a pipelined read, so it runs
            // slower by default. Pickup latency is unaffected: a warm worker
            // takes the job itself, the master only decides on scaling.
            'tick_interval_ms' => 1000,
            'idle_tick_interval_ms' => 5000,
            'metrics_snapshot_interval_seconds' => 5,
            'heartbeat_min_write_interval_ms' => 5000,
        ],
    ],

    // null = the application's default database connection.
    'database_connection' => env('APEX_DB_CONNECTION'),

    'connection' => env('APEX_REDIS_CONNECTION', 'default'),

    'log_level' => env('APEX_LOG_LEVEL', env('LOG_LEVEL', 'info')),

    'debug_scaling' => filter_var(env('APEX_DEBUG_SCALING', false), FILTER_VALIDATE_BOOLEAN),

    // Use BLMPOP (Redis 7+) for instant multi-queue pickup. When the active
    // Redis server is < 7 or the client is not phpredis, ApexWorker falls
    // back to per-queue polling automatically. Set to false to force the
    // fallback path even on Redis 7+ (e.g. for debugging).
    'blmpop_enabled' => filter_var(env('APEX_BLMPOP_ENABLED', true), FILTER_VALIDATE_BOOLEAN),

    'master' => [
        // null on any of these means: take the default for the active store.
        'tick_interval_ms' => env('APEX_TICK_INTERVAL_MS'),
        'idle_tick_interval_ms' => env('APEX_IDLE_TICK_INTERVAL_MS'),
        'metrics_snapshot_interval_seconds' => env('APEX_METRICS_SNAPSHOT_INTERVAL_SECONDS'),
        'heartbeat_min_write_interval_ms' => env('APEX_HEARTBEAT_MIN_WRITE_INTERVAL_MS'),

        'max_concurrent_boots' => (int) env('APEX_MAX_CONCURRENT_BOOTS', 4),
        'boot_spawn_interval_ms' => (int) env('APEX_BOOT_SPAWN_INTERVAL_MS', 100),
        'graceful_shutdown_seconds' => (int) env('APEX_GRACEFUL_SHUTDOWN_SECONDS', 10),
        'worker_heartbeat_ttl_seconds' => (int) env('APEX_WORKER_HEARTBEAT_TTL_SECONDS', 30),
        'worker_heartbeat_grace_seconds' => (int) env('APEX_WORKER_HEARTBEAT_GRACE_SECONDS', 15),

        // How long a master's claim on this installation stays valid. It is
        // renewed while the loop turns, so this is really "how long after a
        // kill -9 before a replacement may start". Two masters scaling the
        // same queues cannot see each other's workers and will happily spawn
        // past every configured maximum.
        'lock_ttl_seconds' => (int) env('APEX_MASTER_LOCK_TTL_SECONDS', 30),

        // Idle back-off. When a master has no workers running, no backlog on
        // any queue and no recent user activity, there is nothing for it to
        // react to; polling four times a second in that state is pure waste
        // (it dominated Redis traffic across all installations). The moment
        // any of those three conditions changes, the normal cadence resumes
        // on the very next tick.
        'idle_snapshot_interval_seconds' => (int) env('APEX_IDLE_SNAPSHOT_INTERVAL_SECONDS', 5),

        // Hysteresis for scaling down. Scale up is immediate; scale down waits
        // until the surplus has persisted, so a transient dip in flex coverage
        // (a recycling flex worker) cannot trigger a kill/spawn oscillation.
        'scale_down_debounce_seconds' => (int) env('APEX_SCALE_DOWN_DEBOUNCE_SECONDS', 20),

        // A worker costs a full framework boot. Never terminate one that was
        // just spawned; pause/over-max are exempt from this guard.
        'shrink_min_lifetime_seconds' => (int) env('APEX_SHRINK_MIN_LIFETIME_SECONDS', 45),

        // Workers that make up a queue's configured minimum are spawned with
        // `--idle-timeout=0` so they stay warm instead of exiting and being
        // immediately respawned. Freshness still comes from max_time_seconds.
        'floor_workers_stay_warm' => filter_var(env('APEX_FLOOR_WORKERS_STAY_WARM', true), FILTER_VALIDATE_BOOLEAN),

        // Rotate a worker log once it exceeds this size (0 disables).
        'worker_log_max_bytes' => (int) env('APEX_WORKER_LOG_MAX_BYTES', 52428800),

        // OPcache preload for the long-running master daemon. When enabled,
        // ApexStartCommand re-execs itself with `-d opcache.preload=...` so
        // hot Laravel/framework files are parsed once into shared memory and
        // never again for the lifetime of the master. Workers do NOT preload
        // (they're short-lived); they rely on opcache.file_cache instead.
        'preload' => [
            'enabled' => filter_var(env('APEX_MASTER_PRELOAD_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
            // Override only if you ship a custom preload script. Default = bundled.
            'script' => env('APEX_MASTER_PRELOAD_SCRIPT'),
            'memory_mb' => (int) env('APEX_MASTER_PRELOAD_MEMORY_MB', 256),
            'max_files' => (int) env('APEX_MASTER_PRELOAD_MAX_FILES', 30000),
            // Required by PHP when starting OPcache preload as root. Set to
            // the user that owns the application files (often www-data).
            'preload_user' => env('APEX_MASTER_PRELOAD_USER', 'www-data'),
        ],
    ],

    'dashboard' => [
        'enabled' => filter_var(env('APEX_DASHBOARD_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        'path' => env('APEX_DASHBOARD_PATH', 'apex'),
        'poll_interval_ms' => (int) env('APEX_DASHBOARD_POLL_MS', 2000),

        // Access is denied outside `local` unless you call Apex::auth() or
        // define a `viewApex` gate. These endpoints can pause queues and flush
        // failed jobs, so the default must be closed.
        'middleware' => ['web'],
    ],

    'activity' => [
        'enabled' => filter_var(env('APEX_ACTIVITY_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        'key' => env('APEX_ACTIVITY_KEY', 'apex:activity:global'),
        'ttl_seconds' => (int) env('APEX_ACTIVITY_TTL_SECONDS', 300),

        // Route names (matched by prefix) whose requests must NOT count as
        // user activity. These are requests the browser makes by itself, so
        // treating them as "a human is working" keeps activity-driven queues
        // staffed around an abandoned tab. The `apex.` prefix matters most:
        // without it the Apex dashboard's own polling keeps Apex awake, i.e.
        // the monitoring screen manufactures the load it displays.
        'ignore_route_prefixes' => [
            'apex.',
            'keep-alive',
            'csrf-refresh',
            'sanctum.csrf-cookie',
        ],
    ],

    'store_keys' => [
        'control_shutdown' => 'apex:control:shutdown',
        'control_pause_prefix' => 'apex:control:pause:',
        'control_suspended_prefix' => 'apex:control:suspended:',
        'master_lock' => 'apex:master:lock',
        'wake' => 'apex:wake',
        'workers_prefix' => 'apex:workers:',
        'metrics_queue_prefix' => 'apex:metrics:queue:',
        'metrics_master' => 'apex:metrics:master',
        'metrics_snapshot' => 'apex:metrics:snapshot',
        'recent_jobs' => 'apex:metrics:recent_jobs',
        'recent_job_starts' => 'apex:metrics:recent_job_starts',
        'inflight_jobs' => 'apex:metrics:inflight_jobs',
        'worker_events' => 'apex:metrics:worker_events',
        'events_channel' => 'apex:events',
    ],

    'recent_jobs' => [
        'enabled' => filter_var(env('APEX_RECENT_JOBS_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        // Track jobs the moment they are dispatched (JobQueued) so the dashboard
        // shows the full lifecycle: queued -> processing -> completed/failed.
        'track_queued' => filter_var(env('APEX_RECENT_JOBS_TRACK_QUEUED', true), FILTER_VALIDATE_BOOLEAN),
        'retention_minutes' => (int) env('APEX_RECENT_JOBS_RETENTION_MINUTES', 60),
        'max_entries' => (int) env('APEX_RECENT_JOBS_MAX_ENTRIES', 999),
        'capture_payload' => filter_var(env('APEX_RECENT_JOBS_CAPTURE_PAYLOAD', true), FILTER_VALIDATE_BOOLEAN),
        'payload_max_bytes' => (int) env('APEX_RECENT_JOBS_PAYLOAD_MAX_BYTES', 16384),
    ],

    'worker_events' => [
        'enabled' => filter_var(env('APEX_WORKER_EVENTS_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        'retention_minutes' => (int) env('APEX_WORKER_EVENTS_RETENTION_MINUTES', 60),
        'max_entries' => (int) env('APEX_WORKER_EVENTS_MAX_ENTRIES', 999),
    ],

    'metrics_history' => [
        'enabled' => filter_var(env('APEX_METRICS_HISTORY_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        // How many days of per-minute aggregates to keep. The
        // `apex:prune-history` scheduled command uses this value.
        'retention_days' => (int) env('APEX_METRICS_HISTORY_RETENTION_DAYS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Worker process options
    |--------------------------------------------------------------------------
    |
    | OPcache for CLI is OFF by default in PHP. Each Apex worker boots a fresh
    | Laravel app, so without OPcache every framework file is re-parsed on each
    | spawn — costing ~30-50MB and 200-500ms per worker. Apex injects -d flags
    | into the spawned PHP process to enable OPcache regardless of the host
    | php.ini. Safe to leave on; if global opcache.enable_cli is already set,
    | the flags are redundant overrides (no conflict).
    |
    */
    'worker' => [
        'opcache' => [
            'enabled' => filter_var(env('APEX_WORKER_OPCACHE_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
            'memory_consumption' => (int) env('APEX_WORKER_OPCACHE_MEMORY_MB', 128),
            'max_accelerated_files' => (int) env('APEX_WORKER_OPCACHE_MAX_FILES', 20000),
            // Persist compiled bytecode to disk so worker N+1 reads parsed
            // bytecode from this directory instead of re-parsing every PHP
            // file. Set to null/empty string to disable. Set as a string to
            // override; default uses storage_path() resolved at runtime.
            'file_cache_dir' => env('APEX_WORKER_OPCACHE_FILE_CACHE'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Burst spawning
    |--------------------------------------------------------------------------
    |
    | When a queue with burst configuration is at or above its `max_processes`
    | and depth still exceeds `burst_threshold_depth`, the master may spawn
    | extra short-lived workers (above max), capped per-queue by
    | `burst_max_extra` and globally by `global_max_extra`. CPU usage is only
    | sampled when at least one queue actually wants to burst, so the helper
    | is free when nothing is going on.
    |
    */
    'burst' => [
        'enabled' => filter_var(env('APEX_BURST_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        'cpu_max_percent' => (int) env('APEX_BURST_CPU_MAX_PERCENT', 70),
        'cpu_sample_cache_ms' => (int) env('APEX_BURST_CPU_SAMPLE_CACHE_MS', 2000),
        'global_max_extra' => (int) env('APEX_BURST_GLOBAL_MAX_EXTRA', 6),
        // Hard cap: never spawn more than this many burst workers in a single
        // master tick (gives the OS time to register CPU usage before the next
        // decision).
        'max_per_tick' => (int) env('APEX_BURST_MAX_PER_TICK', 1),
        // Minimum time between consecutive burst spawns for the same queue.
        'spawn_interval_ms' => (int) env('APEX_BURST_SPAWN_INTERVAL_MS', 8000),
        // Default debounce window for the FIRST burst spawn per queue: depth
        // must stay above the queue's burst_threshold_depth for at least this
        // many milliseconds before bursting kicks in. Per-queue override via
        // `burst_threshold_sustained_ms`.
        'threshold_sustained_ms' => (int) env('APEX_BURST_THRESHOLD_SUSTAINED_MS', 8000),
        // Burst workers are short-lived helpers; always run them at low CPU
        // priority so they yield to baseline workers if the box gets busy.
        // Set to 0 to disable, otherwise overrides any per-queue `nice` value
        // for burst workers only.
        'nice' => (int) env('APEX_BURST_NICE', 15),
    ],

    'profiles' => [

        'latency-critical' => [
            'strategy' => 'hybrid',
            'target_wait_seconds' => 2,
            'target_depth' => 1,
            'jobs_per_worker' => 1,
            'idle_timeout_seconds' => 60,
            'max_jobs' => 500,
            'max_time_seconds' => 3600,
            'memory_mb' => 256,
            'tries' => 1,
            'timeout_seconds' => 900,
            'backoff_seconds' => 0,
        ],

        'throughput' => [
            'strategy' => 'depth',
            'target_depth' => 50,
            'jobs_per_worker' => 20,
            'idle_timeout_seconds' => 60,
            'max_jobs' => 10000,
            'max_time_seconds' => 3600,
            'memory_mb' => 256,
            'tries' => 1,
            'timeout_seconds' => 600,
            'backoff_seconds' => 0,
        ],

        'background' => [
            'strategy' => 'depth',
            'target_depth' => 200,
            'jobs_per_worker' => 50,
            'idle_timeout_seconds' => 30,
            'max_jobs' => 1000,
            'max_time_seconds' => 3600,
            'memory_mb' => 128,
            'tries' => 2,
            'timeout_seconds' => 300,
            'backoff_seconds' => 5,
        ],

        'heavy' => [
            'strategy' => 'hybrid',
            'target_wait_seconds' => 60,
            'target_depth' => 5,
            'jobs_per_worker' => 1,
            'idle_timeout_seconds' => 30,
            'max_jobs' => 20,
            'max_time_seconds' => 7200,
            'memory_mb' => 512,
            'tries' => 3,
            'timeout_seconds' => 1800,
            'backoff_seconds' => 30,
        ],

    ],

    'queues' => [

        'default' => [
            'profile' => 'throughput',
            'min_processes' => 0,
            'min_processes_when_active' => 1,
            'max_processes' => 4,
            'use_activity' => false,
            // 'group' => 'reporting',  // suspend related queues as a unit
        ],

    ],

    'environments' => [
        // 'production' => ['queues' => ['default' => ['max_processes' => 12]]],
    ],

];
