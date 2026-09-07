<?php

namespace Symphoria\Apex\Master;

class WorkerRegistry
{
    private array $workers = [];

    public function add(WorkerHandle $worker): void
    {
        $this->workers[$worker->pid] = $worker;
    }

    public function remove(int $pid): void
    {
        unset($this->workers[$pid]);
    }

    public function get(int $pid): ?WorkerHandle
    {
        return $this->workers[$pid] ?? null;
    }

    public function all(): array
    {
        return array_values($this->workers);
    }

    /**
     * @return array<int, string>
     */
    public function apexIds(): array
    {
        return array_values(array_map(fn (WorkerHandle $w) => $w->apexId, $this->workers));
    }

    public function forQueue(string $queueName): array
    {
        return array_values(array_filter(
            $this->workers,
            fn (WorkerHandle $w) => $w->queueName === $queueName,
        ));
    }

    public function countForQueue(string $queueName): int
    {
        return count($this->forQueue($queueName));
    }

    public function countBaselineForQueue(string $queueName): int
    {
        return count(array_filter(
            $this->workers,
            fn (WorkerHandle $w) => $w->queueName === $queueName && ! $w->isBurst,
        ));
    }

    public function countBurstForQueue(string $queueName): int
    {
        return count(array_filter(
            $this->workers,
            fn (WorkerHandle $w) => $w->queueName === $queueName && $w->isBurst,
        ));
    }

    public function countBurstTotal(): int
    {
        return count(array_filter($this->workers, fn (WorkerHandle $w) => $w->isBurst));
    }

    public function totalCount(): int
    {
        return count($this->workers);
    }
}
