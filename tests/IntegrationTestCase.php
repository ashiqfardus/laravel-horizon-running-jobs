<?php

namespace Ashiqfardus\HorizonRunningJobs\Tests;

use Throwable;

/**
 * Base for tests that hit a real Redis. Skipped automatically when Redis
 * isn't reachable (so CI without a Redis sidecar still passes).
 *
 * Each test runs against database 15 and starts with a clean FLUSHDB so
 * tests cannot leak state into each other.
 */
abstract class IntegrationTestCase extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // Isolate to a non-default database so FLUSHDB cannot affect a dev's
        // primary Redis cache.
        $app['config']->set('database.redis.default.database', 15);
        $app['config']->set('database.redis.options.prefix', '');

        // Manager + connection wiring use these.
        $app['config']->set('horizon.use', 'default');
        $app['config']->set('horizon-running-jobs.cache.enabled', false);

        // Horizon's service provider isn't booted under TestBench, so the
        // 'horizon' connection it normally registers doesn't exist. The
        // SupervisorInspector and the orphan-detection lookup both call
        // Redis::connection('horizon'), so register a 'horizon' connection
        // pointed at the same Redis with the same isolation database.
        $app['config']->set('database.redis.horizon', $app['config']->get('database.redis.default'));
    }

    protected function setUp(): void
    {
        parent::setUp();

        try {
            $this->redis()->ping();
        } catch (Throwable $e) {
            $this->markTestSkipped('Redis not reachable on 127.0.0.1:6379 — '.$e->getMessage());
        }

        $this->redis()->flushDB();
    }

    protected function tearDown(): void
    {
        try {
            $this->redis()->flushDB();
        } catch (Throwable) {
            // best-effort
        }

        parent::tearDown();
    }

    protected function redis()
    {
        return $this->app['redis']->connection('default');
    }

    /**
     * Seed a reserved-set entry directly the way Laravel's RedisQueue would.
     */
    protected function seedReservedJob(string $queue, array $payload, ?int $expiresAt = null): void
    {
        $expiresAt ??= time() + 90; // mirror Laravel's default retry_after window
        $this->redis()->zadd("queues:{$queue}:reserved", $expiresAt, json_encode($payload));
    }

    /**
     * Build a payload shaped like what RedisQueue actually writes to Redis.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function makePayload(array $overrides = []): array
    {
        return array_merge([
            'uuid' => 'test-'.uniqid(),
            'displayName' => 'App\\Jobs\\TestJob',
            'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
            'maxTries' => 1,
            'timeout' => 60,
            'data' => ['command' => 'O:8:"stdClass":0:{}'],
            'attempts' => 1,
            'tags' => [],
        ], $overrides);
    }
}
