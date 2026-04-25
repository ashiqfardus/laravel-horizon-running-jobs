<?php

namespace Ashiqfardus\HorizonRunningJobs;

use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\Contracts\SupervisorRepository;

/**
 * Reports the live state of every Horizon supervisor and master process
 * across the deployment, regardless of which physical host runs them.
 *
 * Horizon's SupervisorRepository::all() / MasterSupervisorRepository::all()
 * silently filter out entries whose registration has expired. We surface
 * those as "stale" so an operator can see a supervisor whose worker died
 * before Horizon's master has reaped the entry.
 */
class SupervisorInspector
{
    public function __construct(
        protected SupervisorRepository $supervisors,
        protected MasterSupervisorRepository $masters,
        protected RedisFactory $redis
    ) {
    }

    /**
     * @return array{
     *     supervisors: array<int, array<string, mixed>>,
     *     masters: array<int, array<string, mixed>>,
     *     inspected_at: int
     * }
     */
    public function inspect(): array
    {
        $now = time();

        $supervisorExpiries = $this->readExpiries('supervisors');
        $masterExpiries = $this->readExpiries('masters');

        $supervisors = $this->buildSupervisors($supervisorExpiries, $now);
        $masters = $this->buildMasters($masterExpiries, $now);

        return [
            'supervisors' => $supervisors,
            'masters' => $masters,
            'inspected_at' => $now,
        ];
    }

    /**
     * Grace window (seconds) before a supervisor is flagged stale. Absorbs
     * normal heartbeat jitter so the dashboard doesn't flap between
     * "running" and "stale" with every poll.
     */
    protected function staleGraceSeconds(): int
    {
        return (int) config('horizon-running-jobs.supervisor_stale_grace_seconds', 5);
    }

    /**
     * @return array<string, int>  member name → expiry timestamp
     */
    protected function readExpiries(string $zsetKey): array
    {
        $connection = $this->redis->connection('horizon');
        $rows = $connection->zrange($zsetKey, 0, -1, ['WITHSCORES' => true]) ?: [];

        $out = [];
        foreach ($rows as $name => $score) {
            $out[(string) $name] = (int) $score;
        }

        return $out;
    }

    /**
     * @param  array<string, int>  $expiries
     * @return array<int, array<string, mixed>>
     */
    protected function buildSupervisors(array $expiries, int $now): array
    {
        $live = collect($this->supervisors->all())
            ->keyBy('name')
            ->all();

        $grace = $this->staleGraceSeconds();
        $rows = [];
        foreach ($expiries as $name => $expiresAt) {
            $isStale = $now > ($expiresAt + $grace);
            $entry = $live[$name] ?? null;

            $rows[] = [
                'name' => $name,
                'status' => $entry->status ?? ($isStale ? 'stale' : 'unknown'),
                'master' => $entry->master ?? null,
                'pid' => isset($entry->pid) ? (int) $entry->pid : null,
                'queues' => $this->parseQueues($entry->options['queue'] ?? null),
                'process_count' => $entry !== null ? array_sum((array) $entry->processes) : 0,
                'processes' => $entry !== null ? (array) $entry->processes : [],
                'expires_at' => $expiresAt,
                'seconds_until_expiry' => max(0, $expiresAt - $now),
                'is_stale' => $isStale,
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, int>  $expiries
     * @return array<int, array<string, mixed>>
     */
    protected function buildMasters(array $expiries, int $now): array
    {
        $live = collect($this->masters->all())
            ->keyBy('name')
            ->all();

        $grace = $this->staleGraceSeconds();
        $rows = [];
        foreach ($expiries as $name => $expiresAt) {
            $isStale = $now > ($expiresAt + $grace);
            $entry = $live[$name] ?? null;

            $rows[] = [
                'name' => $name,
                'status' => $entry->status ?? ($isStale ? 'stale' : 'unknown'),
                'environment' => $entry->environment ?? null,
                'pid' => isset($entry->pid) ? (int) $entry->pid : null,
                'supervisor_count' => $entry !== null ? count((array) $entry->supervisors) : 0,
                'expires_at' => $expiresAt,
                'seconds_until_expiry' => max(0, $expiresAt - $now),
                'is_stale' => $isStale,
            ];
        }

        return $rows;
    }

    /**
     * Horizon stores queues as a comma-separated string in the supervisor
     * options hash. Normalize back to an array.
     *
     * @return array<int, string>
     */
    public function parseQueues(?string $queue): array
    {
        if ($queue === null || $queue === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $queue))));
    }
}
