<?php

namespace Symphoria\Apex\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symphoria\Apex\Ipc\ActivityTracker;
use Throwable;

class TrackApexActivity
{
    public function __construct(
        private readonly ActivityTracker $activity,
    ) {}

    public function handle(Request $request, Closure $next): mixed
    {
        $response = $next($request);

        try {
            if ($request->user() !== null && ! $this->isBackgroundRequest($request)) {
                $this->activity->mark();
            }
        } catch (Throwable) {
            // Activity tracking is best-effort; never break the request.
        }

        return $response;
    }

    /**
     * Requests a browser makes on its own do not prove a human is there.
     * Counting them means an abandoned background tab keeps every
     * activity-driven queue permanently staffed — and, worse, the Apex
     * dashboard polling itself would keep Apex "active" while you watch it.
     */
    private function isBackgroundRequest(Request $request): bool
    {
        $name = (string) ($request->route()?->getName() ?? '');

        if ($name !== '') {
            foreach ((array) config('apex.activity.ignore_route_prefixes', []) as $prefix) {
                if (str_starts_with($name, (string) $prefix)) {
                    return true;
                }
            }
        }

        return false;
    }
}
