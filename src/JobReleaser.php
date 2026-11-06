<?php

namespace Ashiqfardus\HorizonRunningJobs;

use Ashiqfardus\HorizonRunningJobs\Exceptions\LiveSupervisorsUnavailable;
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
    /**
     * Move one payload from the reserved set back to the front of its
     * pending list, but only if it was still reserved. Pushes the same
     * notification Laravel's own queue scripts push, so a worker blocked
     * on `block_for` wakes up.
     *
     * KEYS[1] reserved set, KEYS[2] pending list, KEYS[3] notify list
     * ARGV[1] the reserved payload
     */
    public const RELEASE_SCRIPT = <<<'LUA'
if redis.call('zrem', KEYS[1], ARGV[1]) == 1 then
    redis.call('lpush', KEYS[2], ARGV[1])
    redis.call('rpush', KEYS[3], 1)
    return 1
end
return 0
LUA;

    public function __construct(
        protected RedisFactory $redis,
        protected RunningJobsManager $manager
    ) {
    }

    /**
     * Locate reserved jobs to release.
     *
     *   - job_id:            that one job, whatever its state (reason "manual")
     *   - orphaned / zombie: every job in that state that is also past its
     *                        timeout; both may be given together
     *
     * Classification is the same as on the dashboard (RunningJobsManager::
     * parseJobData). Each item says whether releasing it is `safe`: false
     * means a worker may still be running it.
     *
     * @param  array{job_id?: string, orphaned?: bool, zombie?: bool, queues?: array<int, string>}  $criteria
     * @return array<int, array{job_id: string, queue: string, reason: string, payload: string, safe: bool}>
     *
     * @throws LiveSupervisorsUnavailable when orphans are requested and the
     *         live-supervisor lookup fails
     */
    public function findReleasable(array $criteria): array
    {
        $queues = $criteria['queues'] ?? $this->manager->getDefaultQueues();
        $connection = $this->redis->connection($this->manager->getRedisConnectionName());
        $jobId = $criteria['job_id'] ?? null;

        // Fresh read: a memoized answer may be minutes old in a long-lived process.
        $live = $this->manager->refreshLiveSupervisorNames();

        // Unknown is not "nobody is alive": guessing would requeue running jobs.
        if ($live === null && $jobId === null && ! empty($criteria['orphaned'])) {
            throw new LiveSupervisorsUnavailable;
        }

        $now = time();
        $found = [];
        foreach ($queues as $queue) {
            $entries = $connection->zrange("queues:{$queue}:reserved", 0, -1, ['WITHSCORES' => true]) ?: [];
            $workers = $this->manager->workersFor($this->uuidsOf(array_keys($entries)), $connection);

            foreach ($entries as $payload => $score) {
                $payload = (string) $payload;
                $decoded = json_decode($payload, true);
                if (! is_array($decoded)) {
                    continue;
                }

                $uuid = $decoded['uuid'] ?? $decoded['id'] ?? null;
                if (! is_string($uuid) || ($jobId !== null && $uuid !== $jobId)) {
                    continue;
                }

                try {
                    $row = $this->manager->parseJobData($payload, (float) $score, $queue, '', $now, true, $workers[$uuid] ?? null);
                } catch (\Throwable) {
                    $row = null; // malformed: releasable by explicit job_id only
                }

                $reason = $this->matchReason($row, $criteria);
                if ($reason === null) {
                    continue;
                }

                $found[] = [
                    'job_id' => $uuid,
                    'queue' => $queue,
                    'reason' => $reason,
                    'payload' => $payload,
                    'safe' => ($row['is_releasable'] ?? false) === true,
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
            $queue = "queues:{$item['queue']}";

            // One atomic script: if the job left the reserved set between
            // find and release (it finished, Laravel re-queued it, someone
            // else released it), nothing is pushed, so it cannot run twice.
            $removed = (int) $connection->eval(
                self::RELEASE_SCRIPT, 3, "{$queue}:reserved", $queue, "{$queue}:notify", $item['payload']
            );

            if ($removed === 1) {
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
     * @param  array<string, mixed>|null  $row  the job as parseJobData() classifies it
     * @param  array<string, mixed>  $criteria
     */
    protected function matchReason(?array $row, array $criteria): ?string
    {
        if (isset($criteria['job_id'])) {
            return 'manual';
        }

        if ($row === null || ($row['is_releasable'] ?? false) !== true) {
            return null;
        }

        if (! empty($criteria['orphaned']) && ($row['is_orphaned'] ?? false) === true) {
            return 'orphaned';
        }

        if (! empty($criteria['zombie']) && ($row['status'] ?? null) === 'zombie') {
            return 'zombie';
        }

        return null;
    }

    /**
     * @param  array<int, string>  $payloads
     * @return array<int, string>
     */
    protected function uuidsOf(array $payloads): array
    {
        $uuids = [];
        foreach ($payloads as $payload) {
            $uuid = json_decode((string) $payload, true)['uuid'] ?? null;
            if (is_string($uuid) && $uuid !== '') {
                $uuids[] = $uuid;
            }
        }

        return array_values(array_unique($uuids));
    }
}
