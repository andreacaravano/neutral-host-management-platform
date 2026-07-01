<?php

namespace NHMP;

use Redis;
use Throwable;

final class RedisCache
{
    private static Redis|null|false $client = false;

    public static function client(): ?Redis
    {
        if (!extension_loaded('redis')) {
            return null;
        }
        if (self::$client !== false) {
            return self::$client;
        }
        try {
            $redis = new Redis();
            $redis->connect(Config::redisHost(), Config::redisPort(), 0.8);
            $redis->auth(Config::redisAuth());
            self::$client = $redis;
            return $redis;
        } catch (Throwable) {
            self::$client = null;
            return null;
        }
    }

    public static function get(string $key): ?array
    {
        $r = self::client();
        if (!$r) {
            return null;
        }
        $raw = $r->get($key);
        if (!$raw) {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    public static function put(string $key, array $payload): void
    {
        $r = self::client();
        if (!$r) {
            return;
        }
        $r->setex($key, Config::redisTtl(), json_encode($payload));
    }

    public static function publish(string $channel, array $payload): void
    {
        $r = self::client();
        if (!$r) {
            return;
        }
        $r->publish($channel, json_encode($payload));
    }
}
