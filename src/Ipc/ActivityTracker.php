<?php

namespace Symphoria\Apex\Ipc;

use Symphoria\Apex\Contracts\ApexStore;
use Symphoria\Apex\Support\ApexConfig;

class ActivityTracker
{
    public function __construct(
        private readonly ApexConfig $config,
        private readonly ApexStore $store,
    ) {}

    public function mark(): void
    {
        $activity = $this->config->activity();

        if (! ($activity['enabled'] ?? true)) {
            return;
        }

        $this->store->put(
            $activity['key'],
            (string) time(),
            (int) ($activity['ttl_seconds'] ?? 300),
        );
    }

    public function isActive(): bool
    {
        $activity = $this->config->activity();

        if (! ($activity['enabled'] ?? true)) {
            return false;
        }

        return $this->store->exists($activity['key']);
    }

    public function lastActivityAt(): ?int
    {
        $value = $this->store->get($this->config->activity()['key']);

        return $value === null ? null : (int) $value;
    }
}
