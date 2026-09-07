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
        Schema::create(Apex::table('queue_states'), function (Blueprint $table): void {
            $table->id();
            $table->string('queue_name', 100)->unique();
            $table->boolean('is_paused_manually')->default(false);
            // Polymorphic rather than a foreign key: the package cannot know
            // the host's user model or its table.
            $table->nullableMorphs('paused_by');
            $table->timestamp('paused_at')->nullable();
            $table->string('pause_reason', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(Apex::table('queue_states'));
    }
};
