<?php

namespace Symphoria\Apex\Master;

/**
 * Real memory usage for a process, read from /proc.
 *
 * `memory_get_usage(true)` sees only PHP's own allocator, so it misses opcache,
 * extensions and OS overhead — misleading for a long-running worker.
 *
 * Prefers PSS over RSS: PSS divides shared pages (libphp, opcache, glibc)
 * across the processes mapping them, so summing it across workers gives the
 * physical RAM actually used, where summing RSS counts every shared page once
 * per process. Falls back to VmRSS where smaps_rollup is not readable, which
 * some container runtimes restrict, and to 0.0 when neither is.
 */
class ProcessMemoryReader
{
    public function residentMb(int $pid): float
    {
        if ($pid <= 0) {
            return 0.0;
        }

        $pss = $this->readSmapsPssKb($pid);
        if ($pss > 0) {
            return round($pss / 1024, 1);
        }

        $rss = $this->readStatusRssKb($pid);
        if ($rss > 0) {
            return round($rss / 1024, 1);
        }

        return 0.0;
    }

    private function readSmapsPssKb(int $pid): int
    {
        $path = "/proc/{$pid}/smaps_rollup";
        if (! @is_readable($path)) {
            return 0;
        }
        $contents = @file_get_contents($path);
        if ($contents === false) {
            return 0;
        }
        if (! preg_match('/^Pss:\s+(\d+)\s+kB/m', $contents, $m)) {
            return 0;
        }

        return (int) $m[1];
    }

    private function readStatusRssKb(int $pid): int
    {
        $path = "/proc/{$pid}/status";
        if (! @is_readable($path)) {
            return 0;
        }
        $contents = @file_get_contents($path);
        if ($contents === false) {
            return 0;
        }
        if (! preg_match('/^VmRSS:\s+(\d+)\s+kB/m', $contents, $m)) {
            return 0;
        }

        return (int) $m[1];
    }
}
