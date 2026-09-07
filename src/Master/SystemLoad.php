<?php

namespace Symphoria\Apex\Master;

/**
 * Lightweight system load reader. Caches results so repeated calls per tick
 * stay cheap. Only invoked by the master when at least one queue is actively
 * trying to burst beyond its configured `max_processes`.
 */
class SystemLoad
{
    private static ?int $cores = null;

    private float $cachedAt = 0.0;

    private ?array $cachedSample = null;

    /** Last /proc/stat snapshot used for delta-based CPU% calculation. */
    private ?array $lastCpuStat = null;

    public function sample(int $cacheMs = 2000): array
    {
        if (
            $this->cachedSample !== null
            && ((microtime(true) - $this->cachedAt) * 1000) < $cacheMs
        ) {
            return $this->cachedSample;
        }

        $cores = self::cores();
        $load = function_exists('sys_getloadavg') ? sys_getloadavg() : null;
        if (! is_array($load) || $load === false) {
            $load = null;
        }

        $percent = $this->readCpuPercent();
        if ($percent === null && self::isWindows()) {
            $percent = $this->readCpuPercentWindows();
        }
        if ($percent === null) {
            // Fallback to load average / cores when /proc/stat is unavailable.
            if (is_array($load) && isset($load[0]) && $cores > 0) {
                $percent = min(100.0, max(0.0, ((float) $load[0] / $cores) * 100.0));
            } else {
                $percent = 0.0;
            }
        }

        $this->cachedSample = [
            'percent' => round($percent, 1),
            'load_1m' => isset($load[0]) ? round((float) $load[0], 2) : 0.0,
            'load_5m' => isset($load[1]) ? round((float) $load[1], 2) : 0.0,
            'load_15m' => isset($load[2]) ? round((float) $load[2], 2) : 0.0,
            'cores' => $cores,
            'sampled_at' => microtime(true),
        ];
        $this->cachedAt = microtime(true);

        return $this->cachedSample;
    }

    /**
     * Read aggregate CPU usage from /proc/stat and compute % busy since the
     * previous call. First call seeds the baseline and returns null so the
     * caller falls back to load-avg until a real delta is available.
     */
    private function readCpuPercent(): ?float
    {
        if (! is_readable('/proc/stat')) {
            return null;
        }
        $line = @file('/proc/stat', FILE_IGNORE_NEW_LINES)[0] ?? null;
        if (! is_string($line) || ! str_starts_with($line, 'cpu ')) {
            return null;
        }

        // Fields: user nice system idle iowait irq softirq steal guest guest_nice
        $parts = preg_split('/\s+/', trim($line));
        array_shift($parts); // drop "cpu"
        $nums = array_map('intval', $parts);
        if (count($nums) < 4) {
            return null;
        }

        $idle = ($nums[3] ?? 0) + ($nums[4] ?? 0); // idle + iowait
        $nonIdle = ($nums[0] ?? 0) + ($nums[1] ?? 0) + ($nums[2] ?? 0)
            + ($nums[5] ?? 0) + ($nums[6] ?? 0) + ($nums[7] ?? 0);
        $total = $idle + $nonIdle;

        $prev = $this->lastCpuStat;
        $this->lastCpuStat = ['idle' => $idle, 'total' => $total];

        if ($prev === null) {
            return null;
        }

        $totalDelta = $total - $prev['total'];
        $idleDelta = $idle - $prev['idle'];
        if ($totalDelta <= 0) {
            return null;
        }

        $percent = (1.0 - ($idleDelta / $totalDelta)) * 100.0;

        return max(0.0, min(100.0, $percent));
    }

    public static function cores(): int
    {
        if (self::$cores !== null) {
            return self::$cores;
        }

        $n = 0;

        if (is_readable('/proc/cpuinfo')) {
            $contents = @file_get_contents('/proc/cpuinfo');
            if (is_string($contents)) {
                $n = (int) substr_count($contents, "\nprocessor");
                if ($n === 0 && str_starts_with($contents, 'processor')) {
                    $n = 1;
                }
            }
        }

        if ($n < 1 && self::isWindows()) {
            $env = getenv('NUMBER_OF_PROCESSORS');
            if (is_string($env) && (int) $env > 0) {
                $n = (int) $env;
            }
        }

        if ($n < 1 && function_exists('shell_exec')) {
            $out = @shell_exec('nproc 2>/dev/null');
            if (is_string($out)) {
                $n = (int) trim($out);
            }
        }

        return self::$cores = max(1, $n);
    }

    private static function isWindows(): bool
    {
        return defined('PHP_OS_FAMILY') ? PHP_OS_FAMILY === 'Windows' : stripos(PHP_OS, 'WIN') === 0;
    }

    /**
     * Windows CPU sampling. Uses `wmic` (fast, ~50-200ms) when available,
     * falls back to PowerShell (~300-1000ms cold). Result is cached upstream
     * via `sample()` so the cost is paid at most once per cache window.
     */
    private function readCpuPercentWindows(): ?float
    {
        if (! function_exists('shell_exec')) {
            return null;
        }

        $out = @shell_exec('wmic cpu get loadpercentage /value 2>NUL');
        if (is_string($out) && preg_match('/LoadPercentage=(\d+)/', $out, $m)) {
            return max(0.0, min(100.0, (float) $m[1]));
        }

        $ps = 'powershell -NoProfile -NonInteractive -Command "(Get-CimInstance Win32_Processor | Measure-Object -Property LoadPercentage -Average).Average"';
        $out = @shell_exec($ps.' 2>NUL');
        if (is_string($out) && trim($out) !== '' && is_numeric(trim($out))) {
            return max(0.0, min(100.0, (float) trim($out)));
        }

        return null;
    }
}
