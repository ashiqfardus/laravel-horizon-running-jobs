<?php

namespace Ashiqfardus\HorizonRunningJobs;

use Illuminate\Contracts\Redis\Factory as RedisFactory;

/**
 * Reports per-queue depth: pending list size, reserved set size, and delayed
 * set size. Reads from the same Redis connection the rest of the package
 * uses, resolved via RunningJobsManager.
 */
class QueueDepthInspector
{
    public function __construct(
        protected RedisFactory $redis,
        protected RunningJobsManager $manager
    ) {
    }

    /**
     * @param  array<int, string>|null  $queues
     * @return array{
     *     queues: array<int, array{queue: string, pending: int, reserved: int, delayed: int, total: int}>,
     *     totals: array{pending: int, reserved: int, delayed: int, total: int},
     *     inspected_at: int
     * }
     */
    public function inspect(?array $queues = null): array
    {
        $queues = $queues ?? $this->manager->getDefaultQueues();

        $connection = $this->redis->connection(
            $this->manager->getRedisConnectionName()
        );

        $rows = [];
        $totals = ['pending' => 0, 'reserved' => 0, 'delayed' => 0, 'total' => 0];

        foreach ($queues as $queue) {
            $pending = (int) $connection->llen("queues:{$queue}");
            $reserved = (int) $connection->zcard("queues:{$queue}:reserved");
            $delayed = (int) $connection->zcard("queues:{$queue}:delayed");
            $total = $pending + $reserved + $delayed;

            $rows[] = [
                'queue' => $queue,
                'pending' => $pending,
                'reserved' => $reserved,
                'delayed' => $delayed,
                'total' => $total,
            ];

            $totals['pending'] += $pending;
            $totals['reserved'] += $reserved;
            $totals['delayed'] += $delayed;
            $totals['total'] += $total;
        }

        return [
            'queues' => $rows,
            'totals' => $totals,
            'inspected_at' => time(),
        ];
    }
}
