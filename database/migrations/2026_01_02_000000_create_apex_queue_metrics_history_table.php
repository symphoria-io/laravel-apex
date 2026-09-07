<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Symphoria\Apex\Apex;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(Apex::table('queue_metrics_history'), function (Blueprint $table): void {
            $table->id();
            $table->string('queue_name', 100);
            $table->timestamp('bucket_at');
            $table->unsignedInteger('processed_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->unsignedInteger('avg_runtime_ms')->default(0);
            $table->unsignedInteger('max_runtime_ms')->default(0);
            $table->unsignedInteger('avg_depth')->default(0);
            $table->unsignedInteger('max_depth')->default(0);
            $table->unsignedInteger('avg_oldest_wait_sec')->default(0);
            $table->unsignedInteger('max_oldest_wait_sec')->default(0);
            $table->unsignedInteger('avg_workers')->default(0);
            $table->unsignedInteger('max_workers')->default(0);
            $table->unsignedInteger('sample_count')->default(0);

            $table->unique(['queue_name', 'bucket_at'], 'apex_uniq_queue_bucket');
            $table->index('bucket_at', 'apex_idx_bucket_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(Apex::table('queue_metrics_history'));
    }
};
