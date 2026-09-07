<?php

namespace Symphoria\Apex\Master;

use Symphoria\Apex\Support\ApexConfig;

class BootThrottle
{
    private array $bootingPids = [];

    private float $lastSpawnAt = 0.0;

    public function __construct(
        private readonly int $maxConcurrent,
        private readonly int $spawnIntervalMs,
        private readonly ?Clock $clock = null,
    ) {}

    public static function fromConfig(ApexConfig $config): self
    {
        $master = $config->master();

        return new self(
            (int) ($master['max_concurrent_boots'] ?? 2),
            (int) ($master['boot_spawn_interval_ms'] ?? 150),
        );
    }

    public function canSpawn(): bool
    {
        if (count($this->bootingPids) >= $this->maxConcurrent) {
            return false;
        }

        $elapsedMs = ($this->now() - $this->lastSpawnAt) * 1000;

        return $elapsedMs >= $this->spawnIntervalMs;
    }

    public function noteSpawn(int $pid): void
    {
        $this->bootingPids[$pid] = $this->now();
        $this->lastSpawnAt = $this->now();
    }

    public function noteBooted(int $pid): void
    {
        unset($this->bootingPids[$pid]);
    }

    public function noteDied(int $pid): void
    {
        unset($this->bootingPids[$pid]);
    }

    public function bootingCount(): int
    {
        return count($this->bootingPids);
    }

    public function expireStuckBoots(int $timeoutSeconds): array
    {
        $cutoff = $this->now() - $timeoutSeconds;
        $expired = [];

        foreach ($this->bootingPids as $pid => $startedAt) {
            if ($startedAt < $cutoff) {
                $expired[] = $pid;
                unset($this->bootingPids[$pid]);
            }
        }

        return $expired;
    }

    private function now(): float
    {
        return $this->clock?->now() ?? microtime(true);
    }
}
