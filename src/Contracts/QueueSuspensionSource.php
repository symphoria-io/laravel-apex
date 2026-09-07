<?php

declare(strict_types=1);

namespace Symphoria\Apex\Contracts;

/**
 * Lets the host application decide that a queue must not run right now, for
 * reasons Apex knows nothing about — a disabled feature, a tenant over quota,
 * a maintenance window.
 *
 * Bind your own implementation in a ServiceProvider:
 *
 *     $this->app->bind(QueueSuspensionSource::class, MySuspensionSource::class);
 *
 * Suspension differs from a manual pause: a pause is an operator action stored
 * by Apex itself, a suspension is derived state owned by the application. The
 * master reconciles suspensions on boot and clears any that no longer apply.
 */
interface QueueSuspensionSource
{
    /**
     * Every queue that must be stopped right now.
     *
     * Returns queue names, not group names — use Apex::queuesForGroup() to map
     * a group onto its queues.
     *
     * Deliberately returns all suspended queues in one call rather than
     * answering per queue: the master evaluates this on every reconcile and a
     * per-queue call would turn into N round trips.
     *
     * @return list<string>
     */
    public function suspendedQueues(): array;
}
