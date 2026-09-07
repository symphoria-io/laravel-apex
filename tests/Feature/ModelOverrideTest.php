<?php

declare(strict_types=1);

use Symphoria\Apex\Apex;
use Symphoria\Apex\Exceptions\InvalidConfiguration;
use Symphoria\Apex\Models\QueueState;
use Symphoria\Apex\Tests\Fixtures\CustomQueueState;

/**
 * Without this test the promise "you can override our models" is unproven.
 * Every Symphoria package is expected to have it.
 */
it('uses the model from config', function (): void {
    config()->set('apex.models.queue_state', CustomQueueState::class);

    expect(Apex::queueStateModel())->toBe(CustomQueueState::class);
});

it('persists through the overridden model', function (): void {
    config()->set('apex.models.queue_state', CustomQueueState::class);

    $state = Apex::queueStateModel()::query()->create([
        'queue_name' => 'reports',
        'is_paused_manually' => true,
    ]);

    expect($state)->toBeInstanceOf(CustomQueueState::class)
        ->and($state->shout())->toBe('REPORTS')
        ->and(CustomQueueState::query()->count())->toBe(1);
});

it('accepts the base model itself', function (): void {
    expect(Apex::queueStateModel())->toBe(QueueState::class);
});

it('fails with a usable message when the model does not extend', function (): void {
    config()->set('apex.models.queue_state', stdClass::class);

    Apex::queueStateModel();
})->throws(InvalidConfiguration::class, 'must extend');

it('reads both table names from config', function (): void {
    config()->set('apex.table_names.queue_states', 'legacy_states');

    expect(Apex::table('queue_states'))->toBe('legacy_states')
        ->and(QueueState::query()->getQuery()->from)->toBe('legacy_states')
        ->and(Apex::table('queue_metrics_history'))->toBe('apex_queue_metrics_history');
});

it('fails clearly on a missing table name', function (): void {
    config()->set('apex.table_names.queue_states', null);

    Apex::table('queue_states');
})->throws(InvalidConfiguration::class);

it('records who paused a queue without knowing the user model', function (): void {
    $actor = CustomQueueState::query()->create(['queue_name' => 'actor-stand-in']);

    $state = QueueState::query()->create([
        'queue_name' => 'invoices',
        'is_paused_manually' => true,
        'paused_by_type' => $actor->getMorphClass(),
        'paused_by_id' => $actor->getKey(),
    ]);

    expect($state->pausedBy)->not->toBeNull()
        ->and($state->pausedBy->getKey())->toBe($actor->getKey());
});
