<?php

namespace Symphoria\Apex\Http\Controllers;

use Illuminate\Http\Request;
use Symphoria\Apex\Ipc\MetricsStore;
use Symphoria\Apex\Support\ApexConfig;

class ApexRecentJobsController
{
    public function list(Request $request, MetricsStore $metrics)
    {
        $max = (int) (ApexConfig::fromConfig()->recentJobs()['max_entries'] ?? 1000);

        return response()->json([
            'jobs' => $metrics->recentJobsMerged(
                $request->query('queue') ?: null,
                $request->query('status') ?: null,
                $max
            ),
        ]);
    }
}
