<?php

namespace App\Support;

use Illuminate\Support\Facades\Redis;
use Throwable;

class RedisAvailability
{
    private array $availableByConnection = [];

    public function selectedCacheStore(): string
    {
        return $this->isAvailable($this->cacheRedisConnection()) ? 'redis' : 'database';
    }

    public function selectedQueueConnection(): string
    {
        return $this->isAvailable($this->queueRedisConnection()) ? 'redis' : 'database';
    }

    public function isAvailable(?string $connection = null): bool
    {
        $connection ??= 'default';

        if (array_key_exists($connection, $this->availableByConnection)) {
            return $this->availableByConnection[$connection];
        }

        try {
            Redis::connection($connection)->command('ping');

            $this->availableByConnection[$connection] = true;
        } catch (Throwable) {
            $this->availableByConnection[$connection] = false;
        }

        return $this->availableByConnection[$connection];
    }

    private function cacheRedisConnection(): string
    {
        return config('cache.stores.redis.connection', 'cache');
    }

    private function queueRedisConnection(): string
    {
        return config('queue.connections.redis.connection', 'default');
    }
}
