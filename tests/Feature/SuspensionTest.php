<?php

declare(strict_types=1);

use Symphoria\Apex\Contracts\QueueSuspensionSource;
use Symphoria\Apex\Support\ApexConfig;
use Symphoria\Apex\Support\NullSuspensionSource;
use Symphoria\Apex\Tests\Fixtures\StaticSuspensionSource;

beforeEach(function (): void {
    StaticSuspensionSource::$suspended = [];
});

it('suspends nothing by default', function (): void {
    expect(app(QueueSuspensionSource::class))->toBeInstanceOf(NullSuspensionSource::class)
        ->and(app(QueueSuspensionSource::class)->suspendedQueues())->toBe([]);
});

/**
 * This is the whole point of the extraction: the application decides which
 * queues must stop, and Apex never learns why.
 */
it('follows a rebound suspension source', function (): void {
    app()->bind(QueueSuspensionSource::class, StaticSuspensionSource::class);
    StaticSuspensionSource::$suspended = ['reports'];

    expect(app(QueueSuspensionSource::class)->suspendedQueues())->toBe(['reports']);
});

it('maps a group onto its queues so a host can suspend them as a unit', function (): void {
    config()->set('apex.queues', [
        'invoices' => ['profile' => 'throughput', 'group' => 'billing'],
        'reminders' => ['profile' => 'throughput', 'group' => 'billing'],
        'imports' => ['profile' => 'throughput'],
    ]);

    $config = ApexConfig::fromConfig();

    expect($config->queuesForGroup('billing'))->toBe(['invoices', 'reminders'])
        ->and($config->queuesForGroup('unknown'))->toBe([]);
});

it('exposes the group on the merged queue config', function (): void {
    config()->set('apex.queues', [
        'invoices' => ['profile' => 'throughput', 'group' => 'billing'],
        'imports' => ['profile' => 'throughput'],
    ]);

    $config = ApexConfig::fromConfig();

    expect($config->queue('invoices')['group'])->toBe('billing')
        ->and($config->queue('imports')['group'])->toBeNull();
});
