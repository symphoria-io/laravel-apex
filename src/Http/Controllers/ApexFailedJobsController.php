<?php

namespace Symphoria\Apex\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

class ApexFailedJobsController
{
    public function list(Request $request)
    {
        return response()->json([
            'failed' => $this->loadAll($request->query('queue')),
        ]);
    }

    public function retry(Request $request)
    {
        $id = (string) $request->input('id');
        if ($id === '') {
            return response()->json(['error' => __('base.api.id_required')], 422);
        }

        Artisan::call('queue:retry', ['id' => [$id]]);

        return response()->json(['ok' => true, 'output' => trim(Artisan::output())]);
    }

    public function retryAll(Request $request)
    {
        $queue = $request->input('queue');
        $ids = collect($this->loadAll($queue))->pluck('id')->all();

        if (empty($ids)) {
            return response()->json(['ok' => true, 'count' => 0]);
        }

        Artisan::call('queue:retry', ['id' => $ids]);

        return response()->json(['ok' => true, 'count' => count($ids), 'output' => trim(Artisan::output())]);
    }

    public function forget(Request $request)
    {
        $id = (string) $request->input('id');
        if ($id === '') {
            return response()->json(['error' => __('base.api.id_required')], 422);
        }

        Artisan::call('queue:forget', ['id' => $id]);

        return response()->json(['ok' => true, 'output' => trim(Artisan::output())]);
    }

    public function flush()
    {
        Artisan::call('queue:flush');

        return response()->json(['ok' => true, 'output' => trim(Artisan::output())]);
    }

    public function loadAll(?string $queueFilter): array
    {
        $failer = app('queue.failer');

        if ($failer === null) {
            return [];
        }

        $rows = $failer->all();

        $out = [];
        foreach ($rows as $row) {
            $row = (array) $row;
            if ($queueFilter !== null && $queueFilter !== '' && ($row['queue'] ?? null) !== $queueFilter) {
                continue;
            }

            $payload = is_string($row['payload'] ?? null) ? json_decode($row['payload'], true) : null;
            $name = is_array($payload) ? ($payload['displayName'] ?? ($payload['job'] ?? null)) : null;
            $attempts = is_array($payload) ? ($payload['attempts'] ?? null) : null;

            $out[] = [
                'id' => (string) ($row['id'] ?? $row['uuid'] ?? ''),
                'uuid' => $row['uuid'] ?? null,
                'connection' => $row['connection'] ?? null,
                'queue' => $row['queue'] ?? null,
                'name' => $name,
                'attempts' => $attempts,
                'failed_at' => $row['failed_at'] ?? null,
                'exception_class' => $this->extractExceptionClass((string) ($row['exception'] ?? '')),
                'exception_message' => $this->extractExceptionMessage((string) ($row['exception'] ?? '')),
                'exception' => (string) ($row['exception'] ?? ''),
                'payload' => $payload,
            ];
        }

        usort($out, fn ($a, $b) => strcmp((string) $b['failed_at'], (string) $a['failed_at']));

        return $out;
    }

    private function extractExceptionClass(string $exception): ?string
    {
        if (preg_match('/^([A-Za-z0-9_\\\\]+):/m', $exception, $m)) {
            return $m[1];
        }

        return null;
    }

    private function extractExceptionMessage(string $exception): ?string
    {
        $first = strtok($exception, "\n") ?: '';
        $pos = strpos($first, ': ');
        if ($pos !== false) {
            return trim(substr($first, $pos + 2));
        }

        return $first !== '' ? $first : null;
    }
}
