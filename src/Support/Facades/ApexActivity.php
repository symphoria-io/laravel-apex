<?php

namespace Symphoria\Apex\Support\Facades;

use Illuminate\Support\Facades\Facade;
use Symphoria\Apex\Ipc\ActivityTracker;

/**
 * @method static void mark()
 * @method static bool isActive()
 * @method static int|null lastActivityAt()
 */
class ApexActivity extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return ActivityTracker::class;
    }
}
