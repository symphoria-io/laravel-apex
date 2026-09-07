<?php

namespace Symphoria\Apex\Process;

use RuntimeException;
use Symphoria\Apex\Master\WorkerHandle;

class ProcessFactory
{
    private string $phpBinary;

    private string $artisanPath;

    public function __construct(?string $phpBinary = null, ?string $artisanPath = null)
    {
        $this->phpBinary = $phpBinary ?? $this->detectPhpBinary();
        $this->artisanPath = $artisanPath ?? base_path('artisan');
    }

    public function spawnWorker(string $apexId, string $queueName, array $queueConfig, bool $burst = false, bool $floor = false): WorkerHandle
    {
        if ($burst) {
            $queueConfig['idle_timeout_seconds'] = (int) ($queueConfig['burst_idle_timeout_seconds'] ?? 5);
            $queueConfig['max_jobs'] = (int) ($queueConfig['burst_max_jobs'] ?? 5);
            $queueConfig['max_time_seconds'] = (int) ($queueConfig['burst_max_time_seconds'] ?? 60);
            // Burst workers always run at the configured burst nice level so
            // they never starve baseline workers when the box gets busy.
            $burstNice = (int) (config('apex.burst.nice') ?? 15);
            if ($burstNice !== 0) {
                $queueConfig['nice'] = $burstNice;
            }
        } elseif ($floor && (bool) (config('apex.master.floor_workers_stay_warm') ?? true)) {
            // A floor worker exists because the queue's minimum says so. If it
            // also exits on idle, the master respawns it on the next tick and
            // the pair oscillates forever. Freshness is still bounded by
            // max_time_seconds and by `queue:restart`.
            $queueConfig['idle_timeout_seconds'] = 0;
        } else {
            $floor = false;
        }

        $args = $this->buildWorkerArgs($apexId, $queueName, $queueConfig);

        $phpFlags = $this->buildPhpFlags();

        $cmd = array_merge([$this->phpBinary], $phpFlags, [$this->artisanPath], $args);

        // Optional Unix `nice` prefix to lower CPU priority for low-prio queues.
        $nice = (int) ($queueConfig['nice'] ?? 0);
        if (! $this->isWindows() && $nice !== 0) {
            // Clamp to a sensible range; positive = lower priority.
            $nice = max(-20, min(19, $nice));
            array_unshift($cmd, 'nice', '-n', (string) $nice);
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', $this->logPath($queueName), 'a'],
            2 => ['file', $this->logPath($queueName), 'a'],
        ];

        $pipes = [];

        $process = proc_open(
            $this->isWindows() ? $cmd : implode(' ', array_map('escapeshellarg', $cmd)),
            $descriptors,
            $pipes,
            base_path(),
            null,
            $this->isWindows() ? ['bypass_shell' => true] : null,
        );

        if (! is_resource($process)) {
            throw new RuntimeException("Failed to spawn apex worker for queue '{$queueName}'");
        }

        if (isset($pipes[0]) && is_resource($pipes[0])) {
            fclose($pipes[0]);
        }

        $status = proc_get_status($process);
        $pid = $status['pid'] ?? 0;

        if ($pid <= 0) {
            proc_close($process);
            throw new RuntimeException("Spawned process for queue '{$queueName}' returned no PID");
        }

        return new WorkerHandle(
            pid: $pid,
            queueName: $queueName,
            apexId: $apexId,
            startedAt: microtime(true),
            process: $process,
            isBurst: $burst,
            isFloor: $floor,
        );
    }

    public function isAlive(WorkerHandle $handle): bool
    {
        if (! is_resource($handle->process)) {
            return false;
        }

        $status = proc_get_status($handle->process);

        return (bool) ($status['running'] ?? false);
    }

    public function terminate(WorkerHandle $handle): void
    {
        if (! is_resource($handle->process)) {
            return;
        }

        $status = proc_get_status($handle->process);

        if ($status['running'] ?? false) {
            proc_terminate($handle->process, $this->isWindows() ? 9 : 15);
        }
    }

    /**
     * Last resort for a worker that ignored SIGTERM. `close()` blocks until
     * the process is gone, so something has to guarantee that it will be.
     */
    public function kill(WorkerHandle $handle): void
    {
        if (! is_resource($handle->process)) {
            return;
        }

        $status = proc_get_status($handle->process);

        if ($status['running']) {
            proc_terminate($handle->process, 9);
        }
    }

    public function close(WorkerHandle $handle): int
    {
        if (! is_resource($handle->process)) {
            return -1;
        }

        return proc_close($handle->process);
    }

    private function buildWorkerArgs(string $apexId, string $queueName, array $queueConfig): array
    {
        $queues = $queueConfig['queues'] ?? [$queueName];

        $args = [
            'apex:work',
            '--no-ansi',
            '--name='.$queueName,
            '--apex-id='.$apexId,
            '--queue='.implode(',', $queues),
            '--idle-timeout='.(int) ($queueConfig['idle_timeout_seconds'] ?? 60),
            '--memory='.(int) ($queueConfig['memory_mb'] ?? 256),
            '--timeout='.(int) ($queueConfig['timeout_seconds'] ?? 600),
            '--tries='.(int) ($queueConfig['tries'] ?? 1),
            '--max-jobs='.(int) ($queueConfig['max_jobs'] ?? 100),
            '--max-time='.(int) ($queueConfig['max_time_seconds'] ?? 3600),
            '--backoff='.(int) ($queueConfig['backoff_seconds'] ?? 0),
        ];

        if (array_key_exists('sleep_seconds', $queueConfig)) {
            $args[] = '--sleep='.(float) $queueConfig['sleep_seconds'];
        }

        return $args;
    }

    private function logPath(string $queueName): string
    {
        $dir = storage_path('logs/apex');

        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $path = $dir.DIRECTORY_SEPARATOR.'worker-'.$queueName.'.log';

        // Workers append forever; without this a busy queue produces hundreds
        // of megabytes of log. One stat() per spawn is cheap enough.
        $maxBytes = (int) (config('apex.master.worker_log_max_bytes') ?? 52_428_800);
        if ($maxBytes > 0 && @filesize($path) > $maxBytes) {
            @rename($path, $path.'.1');
        }

        return $path;
    }

    private function detectPhpBinary(): string
    {
        return defined('PHP_BINARY') && PHP_BINARY !== '' ? PHP_BINARY : 'php';
    }

    /**
     * Build `-d ...` PHP CLI flags injected into every spawned worker. Used to
     * force OPcache on (PHP CLI defaults to opcache.enable_cli=0) so workers
     * don't re-compile every framework file on every boot. -d flags override
     * php.ini per-process; if opcache is already enabled globally the flags
     * are harmless duplicates. Disable by setting APEX_WORKER_OPCACHE_ENABLED=false.
     *
     * @return string[]
     */
    private function buildPhpFlags(): array
    {
        $cfg = (array) config('apex.worker.opcache', []);
        if (! ($cfg['enabled'] ?? true)) {
            return [];
        }

        $memory = max(16, (int) ($cfg['memory_consumption'] ?? 128));
        $maxFiles = max(1000, (int) ($cfg['max_accelerated_files'] ?? 20000));

        // validate_timestamps=0 means opcache NEVER re-reads source files —
        // ideal for production (max perf) but a nasty dev footgun: edits to
        // PHP files won't take effect even after restarting workers because
        // the file_cache on disk also persists stale bytecode. Default to
        // validating when APP_DEBUG is on; production can keep the perf win.
        $validate = $cfg['validate_timestamps'] ?? (bool) config('app.debug', false);

        $flags = [];

        if (! app()->isLocal()) {
            $flags = [
                '-d', 'opcache.enable_cli=1',
                '-d', 'opcache.memory_consumption='.$memory,
                '-d', 'opcache.max_accelerated_files='.$maxFiles,
                '-d', 'opcache.validate_timestamps='.($validate ? '1' : '0'),
            ];
        }

        if ($validate) {
            // revalidate_freq=0 = check mtime on every request (cheap stat
            // call). Combined with validate_timestamps=1 this guarantees a
            // worker spawn picks up the latest source after edits.
            $flags[] = '-d';
            $flags[] = 'opcache.revalidate_freq=0';
        }

        // file_cache: persist compiled bytecode to disk between spawns. Worker
        // N+1 reads cached bytecode from this directory instead of re-parsing
        // hundreds of PHP files — major spawn-time win because each worker
        // boots a fresh Laravel app from scratch. Without this, every spawn
        // pays full parse cost in its private OPcache SHM (which is wiped on
        // process exit since CLI opcache isn't shared between processes).
        $fileCacheDir = $cfg['file_cache_dir'] ?? null;
        if (! $fileCacheDir) {
            $fileCacheDir = storage_path('framework/cache/opcache-workers');
        }
        if (is_string($fileCacheDir) && $fileCacheDir !== '') {
            if (! is_dir($fileCacheDir)) {
                @mkdir($fileCacheDir, 0775, true);
            }
            if (is_dir($fileCacheDir) && is_writable($fileCacheDir)) {
                $flags[] = '-d';
                $flags[] = 'opcache.file_cache='.$fileCacheDir;
            }
        }

        return $flags;
    }

    private function isWindows(): bool
    {
        return DIRECTORY_SEPARATOR === '\\';
    }
}
