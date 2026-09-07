<?php

declare(strict_types=1);

namespace Symphoria\Apex\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symphoria\Apex\Apex;

/**
 * Guards the Apex control API.
 *
 * Same shape as Horizon's Authorize middleware, and for the same reason: these
 * endpoints can pause queues and flush failed jobs, so installing the package
 * must never expose them by accident. The default denies outside `local` —
 * open it with Apex::auth() or a `viewApex` gate.
 */
class Authorize
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(Apex::check($request), 403);

        return $next($request);
    }
}
