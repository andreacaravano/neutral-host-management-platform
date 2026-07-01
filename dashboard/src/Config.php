<?php

namespace NHMP;

final class Config
{
    public static function get(string $key, mixed $default = null): mixed
    {
        $map = [
            'app.env' => getenv('APP_ENV') ?: 'prod',
            'redis.host' => getenv('REDIS_HOST') ?: 'redis-master.redis.svc.cluster.local',
            'redis.port' => (int)(getenv('REDIS_PORT') ?: 6379),
            'redis.password' => getenv('REDIS_PASSWORD') ?: 'polimi',
            'redis.db' => (int)(getenv('REDIS_DB') ?: 0),
            'redis.prefix' => getenv('REDIS_PREFIX') ?: '',
        ];
        return $map[$key] ?? $default;
    }

    public static function env(string $key, ?string $default = null): ?string
    {
        $value = getenv($key);
        return $value === false ? $default : $value;
    }

    public static function dbDsn(): string
    {
        return self::env('PG_DSN', 'pgsql:host=postgres-postgresql.postgres.svc.cluster.local;port=5432;dbname=polimi');
    }

    public static function dbUser(): string
    {
        return self::env('PG_USER', 'polimi');
    }

    public static function dbPassword(): string
    {
        return self::env('PG_PASSWORD', 'polimi');
    }

    public static function redisHost(): string
    {
        return self::env('REDIS_HOST', 'redis-master.redis.svc.cluster.local');
    }

    public static function redisPort(): int
    {
        return (int)self::env('REDIS_PORT', '6379');
    }

    public static function redisAuth(): ?string
    {
        return self::env('REDIS_AUTH', 'polimi');
    }

    public static function redisTtl(): int
    {
        return (int)self::env('REDIS_TTL', '5');
    }

    public static function demoMode(): bool
    {
        return 0;
        return self::env('APP_DEMO_MODE', '1') === '1';
    }
}
