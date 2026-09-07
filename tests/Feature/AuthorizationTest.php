<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Symphoria\Apex\Apex;

/**
 * These endpoints can pause queues and flush failed jobs, so the default must
 * be closed. Same guarantee Horizon gives.
 */
it('denies the control API outside local', function (): void {
    app()->detectEnvironment(fn (): string => 'production');

    $this->getJson('apex/api/snapshot')->assertForbidden();
});

it('allows it in local', function (): void {
    app()->detectEnvironment(fn (): string => 'local');

    $this->getJson('apex/api/snapshot')->assertOk();
});

it('honours Apex::auth over the environment default', function (): void {
    app()->detectEnvironment(fn (): string => 'production');
    Apex::auth(fn (): bool => true);

    $this->getJson('apex/api/snapshot')->assertOk();
});

it('honours a viewApex gate', function (): void {
    app()->detectEnvironment(fn (): string => 'production');
    Gate::define('viewApex', fn ($user = null): bool => true);

    $this->getJson('apex/api/snapshot')->assertOk();
});

it('registers the control routes under the configured prefix', function (): void {
    expect(Route::has('apex.api.snapshot'))->toBeTrue()
        ->and(Route::has('apex.api.queues.pause'))->toBeTrue()
        ->and(Route::getRoutes()->getByName('apex.api.snapshot')?->uri())->toBe('apex/api/snapshot');
});

it('ships no screen, only JSON', function (): void {
    expect(class_exists('Symphoria\Apex\Http\Controllers\ApexDashboardController'))->toBeFalse();
});
