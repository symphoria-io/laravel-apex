<?php

declare(strict_types=1);

namespace Symphoria\Apex\Tests\Fixtures;

/**
 * A job that does nothing, only exists to be dispatched. Anonymous classes
 * cannot be serialized, so this cannot be inlined into a test.
 */
class NoopJob
{
    public function handle(): void {}
}
