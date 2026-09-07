<?php

namespace Symphoria\Apex\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ApexTestBroadcast implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int $sequence,
        public readonly string $batchId,
        public readonly float $dispatchedAt,
    ) {}

    public function broadcastQueue(): string
    {
        return 'broadcasts';
    }

    public function broadcastOn(): array
    {
        return [
            new Channel('apex-test'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'ApexTest';
    }

    public function broadcastWith(): array
    {
        return [
            'sequence' => $this->sequence,
            'batch_id' => $this->batchId,
            'dispatched_at' => $this->dispatchedAt,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
