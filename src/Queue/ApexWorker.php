<?php

namespace Symphoria\Apex\Queue;

use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
use Throwable;

/**
 * Worker that blocks on all its queues at once through
 * ApexRedisQueue::popFromMultiple, instead of polling each in turn.
 *
 * Falls back to Laravel's own pop whenever that is not available: a connection
 * that is not an ApexRedisQueue, a Redis older than 7, or a client without
 * blmpop(). The fallback matters more than the fast path, because a worker
 * that thinks it is blocking when it is not will spin.
 *
 * The master passes the queue's `sleep_seconds` as the BLMPOP timeout. That
 * value no longer governs pickup latency, which is driven by the push; it sets
 * how often the Looping event fires, and with it the heartbeat, the shutdown
 * check and the idle timeout.
 */
class ApexWorker extends Worker
{
    private bool $blmpopActive = false;

    private ?float $popTimeout = null;

    /** @var (callable(list<string>): list<string>)|null */
    private $queueFilter = null;

    /**
     * Narrow the queues this worker reads, evaluated on every pop.
     *
     * A worker is started with a fixed `--queue` list, but an operator can
     * stop one of those queues while it runs. Without this the worker keeps
     * draining a queue Apex has been told to leave alone.
     *
     * @param  (callable(list<string>): list<string>)|null  $filter
     */
    public function filterQueuesUsing(?callable $filter): void
    {
        $this->queueFilter = $filter;
    }

    public function daemon($connectionName, $queue, WorkerOptions $options)
    {
        $this->popTimeout = max(0.05, (float) $options->sleep);

        return parent::daemon($connectionName, $queue, $options);
    }

    protected function getNextJob($connection, $queue)
    {
        $queues = $this->activeQueues($queue);

        // Everything this worker reads is stopped. Idling here rather than
        // quitting keeps the process warm for when the queue is resumed; the
        // master retires it if it is no longer wanted.
        if ($queues === []) {
            $this->blmpopActive = false;

            return null;
        }

        // Re-checked every pop rather than once at boot: the queue downgrades
        // itself the first time BLMPOP turns out to be unusable, and a cached
        // answer would leave this worker skipping its sleep forever after.
        if (! $connection instanceof ApexRedisQueue || ! $connection->supportsBlmpop()) {
            $this->blmpopActive = false;

            return parent::getNextJob($connection, implode(',', $queues));
        }

        $this->blmpopActive = true;

        // Laravel's own `queue:pause` is a separate mechanism from the Apex
        // control channel and still applies here. The framework asks this as
        // one batched question, not one per queue.
        $paused = $this->getPausedQueues($connection->getConnectionName(), $queues);

        if ($paused !== []) {
            $this->raisePausedQueueEvents($connection->getConnectionName(), $paused);

            $queues = array_values(array_diff($queues, $paused));

            if ($queues === []) {
                $this->blmpopActive = false;

                return null;
            }
        }

        $this->raiseBeforeJobPopEvent($connection->getConnectionName(), implode(',', $queues));

        try {
            $result = $connection->popFromMultiple($queues, $this->popTimeout ?? 1.0);
        } catch (Throwable $e) {
            $this->exceptions->report($e);
            $this->stopWorkerIfLostConnection($e);
            $this->sleep(1);

            return null;
        }

        if ($result === null) {
            // Nothing was waiting and Redis never blocked, so this pop cost no
            // time at all. Hand the idle wait back to Laravel or we spin.
            if (! $connection->lastCallBlocked()) {
                $this->blmpopActive = false;
            }

            return null;
        }

        [, $job] = $result;
        $this->raiseAfterJobPopEvent($connection->getConnectionName(), $job);

        return $job;
    }

    /**
     * @return list<string>
     */
    private function activeQueues(string $queue): array
    {
        $queues = array_values(array_filter(array_map('trim', explode(',', $queue))));

        if ($this->queueFilter === null) {
            return $queues;
        }

        return array_values(array_intersect($queues, ($this->queueFilter)($queues)));
    }

    /**
     * When BLMPOP is active it already provides the idle wait, so we skip
     * Laravel's separate sleep between empty polls.
     */
    public function sleep($seconds)
    {
        if ($this->blmpopActive) {
            return;
        }

        parent::sleep($seconds);
    }
}
