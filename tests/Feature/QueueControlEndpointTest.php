<?php

declare(strict_types=1);

use Illuminate\Auth\GenericUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Symphoria\Apex\Apex;
use Symphoria\Apex\Models\QueueState;
use Symphoria\Apex\Tests\Fixtures\ActorUser;

/**
 * Covers the pause/resume control endpoints against the real schema.
 *
 * These endpoints were the one part of the package with no HTTP-level test,
 * and both of them wrote a `paused_by_admin_id` column that the migration
 * never created: pause lost the actor to mass-assignment protection, resume
 * failed outright because a query-builder update goes straight to SQL.
 */
beforeEach(function (): void {
    Apex::auth(fn (): bool => true);

    config()->set('apex.queues.invoices', ['connection' => 'primary']);

    Schema::create('apex_test_actors', function (Blueprint $table): void {
        $table->id();
        $table->string('name')->nullable();
    });
});

it('records who paused a queue', function (): void {
    $actor = ActorUser::query()->create(['name' => 'Operator']);

    $response = $this->actingAs($actor)->postJson(route('apex.api.queues.pause'), [
        'queue' => 'invoices',
        'reason' => 'Vendor outage',
    ]);

    $response->assertOk()->assertJson(['ok' => true, 'state' => 'paused']);

    $state = QueueState::query()->where('queue_name', 'invoices')->firstOrFail();

    expect($state->is_paused_manually)->toBeTrue()
        ->and($state->pause_reason)->toBe('Vendor outage')
        ->and($state->paused_by_type)->toBe($actor->getMorphClass())
        ->and((int) $state->paused_by_id)->toBe((int) $actor->getKey())
        ->and($state->pausedBy)->not->toBeNull();
});

it('resumes a paused queue and clears the actor', function (): void {
    $actor = ActorUser::query()->create(['name' => 'Operator']);

    $this->actingAs($actor)->postJson(route('apex.api.queues.pause'), [
        'queue' => 'invoices',
        'reason' => 'Vendor outage',
    ])->assertOk();

    $response = $this->actingAs($actor)->postJson(route('apex.api.queues.resume'), [
        'queue' => 'invoices',
    ]);

    $response->assertOk()->assertJson(['ok' => true, 'state' => 'running']);

    $state = QueueState::query()->where('queue_name', 'invoices')->firstOrFail();

    expect($state->is_paused_manually)->toBeFalse()
        ->and($state->paused_by_type)->toBeNull()
        ->and($state->paused_by_id)->toBeNull()
        ->and($state->paused_at)->toBeNull()
        ->and($state->pause_reason)->toBeNull();
});

it('pauses for an authenticatable that is not an eloquent model', function (): void {
    $actor = new GenericUser(['id' => 99, 'name' => 'Token client']);

    $this->actingAs($actor)->postJson(route('apex.api.queues.pause'), [
        'queue' => 'invoices',
    ])->assertOk();

    $state = QueueState::query()->where('queue_name', 'invoices')->firstOrFail();

    expect($state->is_paused_manually)->toBeTrue()
        ->and($state->paused_by_type)->toBeNull()
        ->and($state->paused_by_id)->toBeNull();
});

it('rejects an unknown queue', function (): void {
    $this->postJson(route('apex.api.queues.pause'), ['queue' => 'nope'])
        ->assertStatus(422);
});
