<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Symphoria\Apex\Apex;

/**
 * Always created, also when the Redis store is active. A migration that
 * depends on a config value gives you a schema that differs per environment,
 * and switching APEX_STORE would become a deploy step instead of a setting.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create(Apex::table('store'), function (Blueprint $table): void {
            $table->id();
            $table->string('key', 180);
            // Hash field. Plain keys use '' rather than null: MySQL treats
            // NULLs in a unique index as distinct, which would silently allow
            // duplicate rows for the same key.
            $table->string('member', 180)->default('');
            $table->longText('value');
            // Unix timestamps rather than datetimes: the master compares them
            // thousands of times and never renders them.
            $table->unsignedBigInteger('expires_at')->nullable()->index();
            $table->unsignedBigInteger('updated_at')->nullable();

            $table->unique(['key', 'member']);
        });

        Schema::create(Apex::table('timeline'), function (Blueprint $table): void {
            $table->id();
            $table->string('key', 180);
            $table->double('score');
            $table->longText('value');

            // Every read is "this key, ordered by score", either the newest N
            // or everything after a cursor.
            $table->index(['key', 'score']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(Apex::table('timeline'));
        Schema::dropIfExists(Apex::table('store'));
    }
};
