<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Symphoria\Apex\Http\Controllers\ApexFailedJobsController;
use Symphoria\Apex\Http\Controllers\ApexRecentJobsController;
use Symphoria\Apex\Http\Controllers\ApexWorkerEventsController;
use Symphoria\Apex\Http\Controllers\Api\ApexHistoryController;
use Symphoria\Apex\Http\Controllers\Api\ApexMetricsController;

// JSON only. The prefix, middleware and `apex.` name prefix are applied by
// ApexServiceProvider::registerRoutes(); the screen itself belongs to the
// host application, which consumes these endpoints.

Route::get('api/snapshot', [ApexMetricsController::class, 'snapshot'])->name('api.snapshot');
Route::post('api/queues/pause', [ApexMetricsController::class, 'pause'])->name('api.queues.pause');
Route::post('api/queues/resume', [ApexMetricsController::class, 'resume'])->name('api.queues.resume');

Route::get('failed/api/list', [ApexFailedJobsController::class, 'list'])->name('failed.api.list');
Route::post('failed/api/retry', [ApexFailedJobsController::class, 'retry'])->name('failed.api.retry');
Route::post('failed/api/retry-all', [ApexFailedJobsController::class, 'retryAll'])->name('failed.api.retry_all');
Route::post('failed/api/forget', [ApexFailedJobsController::class, 'forget'])->name('failed.api.forget');
Route::post('failed/api/flush', [ApexFailedJobsController::class, 'flush'])->name('failed.api.flush');

Route::get('recent/api/list', [ApexRecentJobsController::class, 'list'])->name('recent.api.list');
Route::get('workers/api/list', [ApexWorkerEventsController::class, 'list'])->name('workers.api.list');
Route::get('history/api/index', [ApexHistoryController::class, 'index'])->name('history.api.index');
