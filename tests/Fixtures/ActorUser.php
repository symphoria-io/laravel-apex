<?php

declare(strict_types=1);

namespace Symphoria\Apex\Tests\Fixtures;

use Illuminate\Foundation\Auth\User;

/**
 * Stands in for the host application's user model, which the package cannot
 * know. Its table is created by the test that needs it.
 */
class ActorUser extends User
{
    protected $table = 'apex_test_actors';

    protected $guarded = [];

    public $timestamps = false;
}
