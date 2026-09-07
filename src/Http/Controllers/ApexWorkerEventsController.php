<?php

namespace Symphoria\Apex\Http\Controllers;

use Illuminate\Http\Request;
use Symphoria\Apex\Ipc\MetricsStore;
use Symphoria\Apex\Support\ApexConfig;

class ApexWorkerEventsController
{
    public function list(Request $request, MetricsStore $metrics)
    {
        $max = (int) (ApexConfig::fromConfig()->workerEvents()['max_entries'] ?? 1000);

        return response()->json([
            'events' => $metrics->workerEvents(
                $request->query('queue') ?: null,
                $request->query('type') ?: null,
                $max
            ),
        ]);
    }
}
