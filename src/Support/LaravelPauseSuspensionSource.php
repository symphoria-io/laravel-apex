<?php

declare(strict_types=1);

namespace Symphoria\Apex\Support;

use Illuminate\Support\Facades\Queue;
use Symphoria\Apex\Contracts\QueueSuspensionSource;

/**
 * Treats Laravel's own paused queues as suspended, so `queue:pause` and
 * `Queue::pauseAll()` scale Apex workers down to zero instead of leaving idle
 * workers alive.
 *
 * Not the default binding, and deliberately so: whether an operator pausing a
 * queue should also scale its workers to zero is a policy question, not a
 * technical one. Bind it explicitly when that is what you want:
 *
 *     $this->app->bind(QueueSuspensionSource::class, LaravelPauseSuspensionSource::class);
 */
final class LaravelPauseSuspensionSource implements QueueSuspensionSource
{
    public function __construct(
        private readonly ApexConfig $config,
    ) {}

    public function suspendedQueues(): array
    {
        $manager = Queue::getFacadeRoot();

        if (! is_object($manager) || ! method_exists($manager, 'getPausedQueues')) {
            return [];
        }

        $groups = $this->config->allQueues();

        // Laravel pauses the queues workers actually consume; Apex scales
        // groups, which may read several of them. One call covering every
        // queue in every group, then map the answer back onto groups.
        $names = [];
        foreach ($groups as $group => $settings) {
            foreach ($settings['queues'] ?? [$group] as $queue) {
                $names[] = (string) $queue;
            }
        }

        $names = array_values(array_unique($names));

        if ($names === []) {
            return [];
        }

        $paused = array_flip(array_map(strval(...), (array) $manager->getPausedQueues(
            (string) config('queue.default'),
            $names,
        )));

        $suspended = [];

        foreach ($groups as $group => $settings) {
            $queues = $settings['queues'] ?? [$group];

            // All of them, not any: a group with one queue still running has
            // work to do, and scaling it to zero would strand that work.
            $allPaused = $queues !== [];

            foreach ($queues as $queue) {
                if (! isset($paused[(string) $queue])) {
                    $allPaused = false;

                    break;
                }
            }

            if ($allPaused) {
                $suspended[] = (string) $group;
            }
        }

        return $suspended;
    }
}
