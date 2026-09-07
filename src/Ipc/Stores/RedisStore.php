<?php

declare(strict_types=1);

namespace Symphoria\Apex\Ipc\Stores;

use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Redis;
use Symphoria\Apex\Contracts\ApexStore;
use Symphoria\Apex\Support\ApexConfig;

/**
 * Default store. Bulk reads go through a pipeline, which is what keeps an
 * idle master cheap.
 */
final class RedisStore implements ApexStore
{
    public function __construct(
        private readonly ApexConfig $config,
    ) {}

    public function get(string $key): ?string
    {
        $value = $this->redis()->get($key);

        return is_string($value) ? $value : null;
    }

    public function put(string $key, string $value, ?int $ttlSeconds = null): void
    {
        if ($ttlSeconds === null || $ttlSeconds <= 0) {
            $this->redis()->set($key, $value);

            return;
        }

        $this->redis()->setex($key, $ttlSeconds, $value);
    }

    public function forget(string $key): void
    {
        $this->redis()->del($key);
    }

    /**
     * `SET key value EX ttl NX`, which is one atomic command on the server.
     *
     * Routed through command() rather than the connection's own set(): the two
     * clients want the modifiers in different shapes, and the wrapper's
     * signature is the one thing here that is not portable.
     */
    public function add(string $key, string $value, ?int $ttlSeconds = null): bool
    {
        if ($ttlSeconds === null || $ttlSeconds <= 0) {
            return (bool) $this->redis()->setnx($key, $value);
        }

        $arguments = $this->redis()->client() instanceof \Redis
            ? [$key, $value, ['NX', 'EX' => $ttlSeconds]]
            : [$key, $value, 'EX', $ttlSeconds, 'NX'];

        return (bool) $this->redis()->command('set', $arguments);
    }

    public function exists(string $key): bool
    {
        return (bool) $this->redis()->exists($key);
    }

    public function many(array $keys): array
    {
        $keys = array_values(array_unique($keys));

        if ($keys === []) {
            return [];
        }

        $results = array_values((array) $this->redis()->pipeline(function ($pipe) use ($keys) {
            foreach ($keys as $key) {
                $pipe->get($key);
            }
        }));

        $out = [];
        foreach ($keys as $index => $key) {
            $raw = $results[$index] ?? null;
            $out[$key] = is_string($raw) && $raw !== '' ? $raw : null;
        }

        return $out;
    }

    public function existsMany(array $keys): array
    {
        $keys = array_values(array_unique($keys));

        if ($keys === []) {
            return [];
        }

        $results = array_values((array) $this->redis()->pipeline(function ($pipe) use ($keys) {
            foreach ($keys as $key) {
                $pipe->exists($key);
            }
        }));

        $out = [];
        foreach ($keys as $index => $key) {
            $out[$key] = (bool) ($results[$index] ?? false);
        }

        return $out;
    }

    public function keys(string $prefix): array
    {
        $clientPrefix = $this->clientPrefix();
        $strip = strlen($clientPrefix);

        $out = [];

        foreach ((array) $this->redis()->keys($prefix.'*') as $rawKey) {
            $rawKey = (string) $rawKey;

            // keys() returns prefixed keys but get() re-prefixes, so strip it.
            $out[] = $strip > 0 && str_starts_with($rawKey, $clientPrefix)
                ? substr($rawKey, $strip)
                : $rawKey;
        }

        return $out;
    }

    private function clientPrefix(): string
    {
        return (string) config(
            'database.redis.options.prefix',
            config('database.redis.'.$this->config->connection().'.prefix', ''),
        );
    }

    public function increment(string $key, int $by = 1, ?int $ttlSeconds = null): int
    {
        $redis = $this->redis();
        $value = (int) $redis->incrby($key, $by);

        if ($ttlSeconds !== null && $ttlSeconds > 0) {
            $redis->expire($key, $ttlSeconds);
        }

        return $value;
    }

    public function hashPut(string $key, string $field, string $value): void
    {
        $this->redis()->hset($key, $field, $value);
    }

    public function hashGet(string $key, string $field): ?string
    {
        $value = $this->redis()->hget($key, $field);

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function hashForget(string $key, array $fields): void
    {
        if ($fields === []) {
            return;
        }

        $this->redis()->hdel($key, ...$fields);
    }

    public function hashAll(string $key): array
    {
        $all = $this->redis()->hgetall($key);

        return is_array($all) ? array_map(strval(...), $all) : [];
    }

    public function timelineAdd(string $key, float $score, string $value): void
    {
        $this->redis()->zadd($key, $score, $value);
    }

    public function timelineNewest(string $key, int $limit): array
    {
        $limit = max(1, $limit);

        return array_values(array_map(strval(...), (array) $this->redis()->zrevrange($key, 0, $limit - 1)));
    }

    public function timelineSince(string $key, float $exclusiveMin, int $limit): array
    {
        $raw = $this->redis()->zrangebyscore(
            $key,
            '('.$exclusiveMin,
            '+inf',
            ['limit' => [0, max(1, $limit)]],
        );

        return array_values(array_map(strval(...), (array) $raw));
    }

    public function timelineTrim(string $key, ?float $minScore, ?int $maxEntries): void
    {
        $redis = $this->redis();

        if ($minScore !== null) {
            $redis->zremrangebyscore($key, '-inf', '('.$minScore);
        }

        if ($maxEntries === null) {
            return;
        }

        $maxEntries = max(0, $maxEntries);
        $count = (int) $redis->zcard($key);

        if ($count > $maxEntries) {
            $redis->zremrangebyrank($key, 0, $count - $maxEntries - 1);
        }
    }

    public function sweep(): int
    {
        // Redis drops expired keys by itself.
        return 0;
    }

    private function redis(): Connection
    {
        return Redis::connection($this->config->connection());
    }
}
