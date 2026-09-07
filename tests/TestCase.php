<?php

declare(strict_types=1);

namespace Symphoria\Apex\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use Symphoria\Apex\Apex;
use Symphoria\Apex\Providers\ApexServiceProvider;

/**
 * The first file you write in a package: every other test hangs off it.
 *
 * RefreshDatabase is safe here because Testbench uses a throwaway in-memory
 * SQLite database, unlike the shared WPS development database.
 */
abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Apex::flushAuth();
    }

    /**
     * @param  Application  $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            ApexServiceProvider::class,
        ];
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        // The control API runs through the `web` group, which needs an
        // encrypter for the session cookie.
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
    }

    /**
     * Laravel's own jobs table, spelled out rather than pulled from whichever
     * stub the installed version ships, so the schema Apex reads is fixed by
     * this package's tests rather than by the framework release under test.
     */
    protected function createJobsTable(string $table = 'jobs'): void
    {
        if (Schema::hasTable($table)) {
            return;
        }

        Schema::create($table, function (Blueprint $blueprint): void {
            $blueprint->id();
            $blueprint->string('queue')->index();
            $blueprint->longText('payload');
            $blueprint->unsignedTinyInteger('attempts');
            $blueprint->unsignedInteger('reserved_at')->nullable();
            $blueprint->unsignedInteger('available_at');
            $blueprint->unsignedInteger('created_at');
        });
    }
}
