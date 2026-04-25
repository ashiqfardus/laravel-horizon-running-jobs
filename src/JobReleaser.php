<?php

namespace Ashiqfardus\HorizonRunningJobs;

use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Support\Facades\Log;

/**
 * Moves reserved jobs back to the pending list.
 *
 * Two-phase API:
 *   - findReleasable($criteria) — locate matching reserved entries (read-only)
 *   - release($items)            — atomically ZREM from reserved + LPUSH to pending
 *
 * Splitting find from release lets callers preview (--dry-run) without
 * mutating Redis.
 */
class JobReleaser
{
    public function __construct(
        protected RedisFactory $redis,
        protected RunningJobsManager $manager
    ) {
    }

    /**
     * @param  array{job_id?: string, orphaned?: bool, zombie?: bool, queues?: array<int, string>}  $criteria
     * @return array<int, array{job_id: string, queue: string, reason: string, payload: string}>
     */
    public function findReleasable(array $criteria): array
    {
        $queues = $criteria['queues'] ?? $this->manager->getDefaultQueues();
        $connection = $this->redis->connection($this->manager->getRedisConnectionName());

        $live = $criteria['orphaned'] ?? false
            ? ($this->manager->getLiveSupervisorNames() ?? [])
            : null;

        $found = [];
        foreach ($queues as $queue) {
            $entries = $connection->zrange("queues:{$queue}:reserved", 0, -1, ['WITHSCORES' => true]) ?: [];

            foreach ($entries as $payload => $expiresAt) {
                $payload = (string) $payload;
                $decoded = json_decode($payload, true);
                if (! is_array($decoded)) {
                    continue;
                }

                $jobId = $decoded['uuid'] ?? $decoded['id'] ?? null;
                if ($jobId === null) {
                    continue;
                }

                $reason = $this->matchReason($decoded, (int) $expiresAt, $criteria, $live);
                if ($reason === null) {
                    continue;
                }

                $found[] = [
                    'job_id' => $jobId,
                    'queue' => $queue,
                    'reason' => $reason,
                    'payload' => $payload,
                ];
            }
        }

        return $found;
    }

    /**
     * @param  array<int, array{job_id: string, queue: string, reason: string, payload: string}>  $items
     */
    public function release(array $items): int
    {
        $connection = $this->redis->connection($this->manager->getRedisConnectionName());

        $count = 0;
        foreach ($items as $item) {
            $reservedKey = "queues:{$item['queue']}:reserved";
            $pendingKey = "queues:{$item['queue']}";

            // Atomic: ZREM + LPUSH in one Redis transaction. If ZREM removed
            // 0 entries (someone else released this job between find and
            // release), we discard our LPUSH so the same payload doesn't end
            // up duplicated on the pending list.
            $result = $connection->transaction(function ($tx) use ($reservedKey, $pendingKey, $item) {
                $tx->zrem($reservedKey, $item['payload']);
                $tx->lpush($pendingKey, $item['payload']);
            });

            $removed = $result[0] ?? 0;
            if ($removed >= 1) {
                $count++;
                Log::info('HorizonRunningJobs: released job', [
                    'job_id' => $item['job_id'],
                    'queue' => $item['queue'],
                    'reason' => $item['reason'],
                ]);
            }
        }

        return $count;
    }

    /**
     * @param  array<string, mixed>  $decoded
     * @param  array<string, mixed>  $criteria
     * @param  array<int, string>|null  $liveSupervisors
     */
    protected function matchReason(array $decoded, int $expiresAt, array $criteria, ?array $liveSupervisors): ?string
    {
        $jobId = $decoded['uuid'] ?? $decoded['id'] ?? null;

        if (isset($criteria['job_id'])) {
            return $jobId === $criteria['job_id'] ? 'manual' : null;
        }

        if (! empty($criteria['orphaned'])) {
            $serverTag = $this->serverTagFromPayload($decoded);
            if ($serverTag !== null && $this->manager->isJobOrphaned($serverTag, $liveSupervisors)) {
                return 'orphaned';
            }
            return null;
        }

        if (! empty($criteria['zombie'])) {
            return time() > $expiresAt ? 'zombie' : null;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $decoded
     */
    protected function serverTagFromPayload(array $decoded): ?string
    {
        foreach (($decoded['tags'] ?? []) as $tag) {
            if (is_string($tag) && str_starts_with($tag, 'server:')) {
                return substr($tag, 7);
            }
        }
        return null;
    }
}
