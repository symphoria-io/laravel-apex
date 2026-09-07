<?php

namespace Symphoria\Apex\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symphoria\Apex\Ipc\ControlChannel;
use Symphoria\Apex\Ipc\MetricsStore;
use Symphoria\Apex\Models\QueueState;
use Symphoria\Apex\Support\ApexConfig;

class ApexMetricsController
{
    public function snapshot(MetricsStore $metrics): JsonResponse
    {
        $snapshot = $metrics->readSnapshot();

        return response()->json([
            'snapshot' => $snapshot,
            'available' => $snapshot !== null,
        ])->withHeaders([
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]);
    }

    public function pause(Request $request, ControlChannel $control): JsonResponse
    {
        $queue = (string) $request->input('queue');
        $config = ApexConfig::fromConfig();

        if (! $config->hasQueue($queue)) {
            return response()->json(['error' => "Unknown queue '{$queue}'"], 422);
        }

        if ($control->isQueueSuspended($queue)) {
            return response()->json([
                'error' => "Queue '{$queue}' is suspended by the application.",
            ], 422);
        }

        $reason = $request->input('reason');
        $reason = is_string($reason) ? trim($reason) : null;

        QueueState::updateOrCreate(
            ['queue_name' => $queue],
            [
                'is_paused_manually' => true,
                'paused_by_admin_id' => $request->user()?->getAuthIdentifier(),
                'paused_at' => now(),
                'pause_reason' => $reason !== '' ? $reason : null,
            ],
        );

        $control->pauseQueue($queue);

        return response()->json(['ok' => true, 'queue' => $queue, 'state' => 'paused']);
    }

    public function resume(Request $request, ControlChannel $control): JsonResponse
    {
        $queue = (string) $request->input('queue');
        $config = ApexConfig::fromConfig();

        if (! $config->hasQueue($queue)) {
            return response()->json(['error' => "Unknown queue '{$queue}'"], 422);
        }

        if ($control->isQueueSuspended($queue)) {
            return response()->json([
                'error' => "Queue '{$queue}' is suspended by the application.",
            ], 422);
        }

        QueueState::where('queue_name', $queue)->update([
            'is_paused_manually' => false,
            'paused_by_admin_id' => null,
            'paused_at' => null,
            'pause_reason' => null,
        ]);

        $control->continueQueue($queue);

        return response()->json(['ok' => true, 'queue' => $queue, 'state' => 'running']);
    }
}
