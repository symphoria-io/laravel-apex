<?php

declare(strict_types=1);

namespace Symphoria\Apex;

use Closure;
use Composer\InstalledVersions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Gate;
use Symphoria\Apex\Exceptions\InvalidConfiguration;
use Symphoria\Apex\Models\QueueMetricsHistory;
use Symphoria\Apex\Models\QueueState;

/**
 * Single resolver for everything that comes from config, validated at the
 * point of use. Package code never references a model class directly; it
 * always goes through Apex::queueStateModel()::query().
 */
final class Apex
{
    public const PACKAGE = 'symphoria/laravel-apex';

    /**
     * Authorization callback for the dashboard API.
     *
     * Same shape as Horizon::auth(). The default denies outside the local
     * environment, so installing the package never exposes queue control on
     * production by accident.
     */
    private static ?Closure $authUsing = null;

    public static function auth(Closure $callback): void
    {
        self::$authUsing = $callback;
    }

    public static function check(Request $request): bool
    {
        if (self::$authUsing !== null) {
            return (bool) call_user_func(self::$authUsing, $request);
        }

        if (Gate::has('viewApex')) {
            return Gate::forUser($request->user())->allows('viewApex');
        }

        return app()->environment('local');
    }

    /** @internal Test seam; do not call from application code. */
    public static function flushAuth(): void
    {
        self::$authUsing = null;
    }

    /**
     * @return class-string<QueueState>
     */
    public static function queueStateModel(): string
    {
        return self::model('queue_state', QueueState::class);
    }

    /**
     * @return class-string<QueueMetricsHistory>
     */
    public static function queueMetricsHistoryModel(): string
    {
        return self::model('queue_metrics_history', QueueMetricsHistory::class);
    }

    public static function table(string $key): string
    {
        $table = Config::get("apex.table_names.{$key}");

        if (! is_string($table) || $table === '') {
            throw InvalidConfiguration::missingTableName($key);
        }

        return $table;
    }

    public static function version(): string
    {
        if (! InstalledVersions::isInstalled(self::PACKAGE)) {
            return 'dev';
        }

        return InstalledVersions::getPrettyVersion(self::PACKAGE) ?? 'dev';
    }

    /**
     * @template T of \Illuminate\Database\Eloquent\Model
     *
     * @param  class-string<T>  $base
     * @return class-string<T>
     */
    private static function model(string $key, string $base): string
    {
        $class = Config::get("apex.models.{$key}");

        if (! is_string($class) || ! is_a($class, $base, true)) {
            throw InvalidConfiguration::modelMustExtend($class, $base);
        }

        return $class;
    }
}
