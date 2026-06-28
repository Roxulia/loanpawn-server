<?php

namespace Tests\Feature;

use App\Support\TenantScopedCacheKeys;
use App\Support\RedisAvailability;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class TenantScopedCacheKeysTest extends TestCase
{
    public function test_configures_redis_as_default_cache_store_when_redis_is_available(): void
    {
        $this->app->forgetInstance(RedisAvailability::class);

        $connection = Mockery::mock();
        $connection->shouldReceive('command')
            ->once()
            ->with('ping')
            ->andReturn('PONG');

        Redis::shouldReceive('connection')
            ->once()
            ->with('cache')
            ->andReturn($connection);

        app(TenantScopedCacheKeys::class)->configureDefaultCacheStore();

        $this->assertSame('redis', config('cache.default'));
    }

    public function test_configures_database_as_default_cache_store_when_redis_is_unavailable(): void
    {
        $this->app->forgetInstance(RedisAvailability::class);

        Redis::shouldReceive('connection')
            ->once()
            ->with('cache')
            ->andThrow(new RuntimeException('Redis is unavailable.'));

        app(TenantScopedCacheKeys::class)->configureDefaultCacheStore();

        $this->assertSame('database', config('cache.default'));
    }

    public function test_configures_redis_as_default_queue_connection_when_redis_is_available(): void
    {
        $this->app->forgetInstance(RedisAvailability::class);

        $connection = Mockery::mock();
        $connection->shouldReceive('command')
            ->once()
            ->with('ping')
            ->andReturn('PONG');

        Redis::shouldReceive('connection')
            ->once()
            ->with('default')
            ->andReturn($connection);

        Queue::setDefaultDriver(app(RedisAvailability::class)->selectedQueueConnection());

        $this->assertSame('redis', config('queue.default'));
    }

    public function test_configures_database_as_default_queue_connection_when_redis_is_unavailable(): void
    {
        $this->app->forgetInstance(RedisAvailability::class);

        Redis::shouldReceive('connection')
            ->once()
            ->with('default')
            ->andThrow(new RuntimeException('Redis is unavailable.'));

        Queue::setDefaultDriver(app(RedisAvailability::class)->selectedQueueConnection());

        $this->assertSame('database', config('queue.default'));
    }
}
