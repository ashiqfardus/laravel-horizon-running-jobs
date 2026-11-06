<?php

namespace Ashiqfardus\HorizonRunningJobs;

use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\Contracts\SupervisorRepository;

/**
 * Reports the live state of every Horizon supervisor and master process
 * across the deployment, regardless of which physical host runs them.
 *
 * Horizon scores each member of the `supervisors` / `masters` ZSETs with the
 * time of its last heartbeat, and treats it as alive while that heartbeat is
 * recent enough. SupervisorRepository::all() / MasterSupervisorRepository::all()
 * silently filter out the rest. We surface those as "stale" so an operator
 * can see a supervisor that stopped heartbeating before Horizon's master has
 * reaped the entry.
 */
class SupervisorInspector
{
    /** Horizon's alive window for supervisors, see RedisSupervisorRepository::names(). */
    public const SUPERVISOR_ALIVE_SECONDS = 29;

    /** Horizon's alive window for masters, see RedisMasterSupervisorRepository::names(). */
    public const MASTER_ALIVE_SECONDS = 14;

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

        $supervisors = $this->buildSupervisors($this->readHeartbeats('supervisors'), $now);
        $masters = $this->buildMasters($this->readHeartbeats('masters'), $now);

        return [
            'supervisors' => $supervisors,
            'masters' => $masters,
            'inspected_at' => $now,
        ];
    }

    /**
     * Extra margin (seconds) on top of Horizon's own alive window before an
     * entry is flagged stale.
     */
    protected function staleGraceSeconds(): int
    {
        return max(0, (int) config('horizon-running-jobs.supervisor_stale_grace_seconds', 5));
    }

    /**
     * Heartbeat-derived fields shared by supervisor and master rows.
     *
     * `expires_at` is the moment Horizon itself stops treating the entry as
     * alive (last heartbeat + Horizon's window); `is_stale` adds the grace.
     *
     * @return array{last_heartbeat_at: int, seconds_since_heartbeat: int, expires_at: int, seconds_until_expiry: int, is_stale: bool}
     */
    protected function heartbeatFields(int $lastHeartbeatAt, int $aliveSeconds, int $now): array
    {
        $expiresAt = $lastHeartbeatAt + $aliveSeconds;

        return [
            'last_heartbeat_at' => $lastHeartbeatAt,
            'seconds_since_heartbeat' => max(0, $now - $lastHeartbeatAt),
            'expires_at' => $expiresAt,
            'seconds_until_expiry' => max(0, $expiresAt - $now),
            'is_stale' => $now > $expiresAt + $this->staleGraceSeconds(),
        ];
    }

    /**
     * @return array<string, int>  member name → last heartbeat timestamp
     */
    protected function readHeartbeats(string $zsetKey): array
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
     * @param  array<string, int>  $heartbeats
     * @return array<int, array<string, mixed>>
     */
    protected function buildSupervisors(array $heartbeats, int $now): array
    {
        $live = collect($this->supervisors->all())
            ->keyBy('name')
            ->all();

        $rows = [];
        foreach ($heartbeats as $name => $lastHeartbeatAt) {
            $heartbeat = $this->heartbeatFields($lastHeartbeatAt, self::SUPERVISOR_ALIVE_SECONDS, $now);
            $entry = $live[$name] ?? null;

            $rows[] = [
                'name' => $name,
                'status' => $entry->status ?? ($heartbeat['is_stale'] ? 'stale' : 'unknown'),
                'master' => $entry->master ?? null,
                'pid' => isset($entry->pid) ? (int) $entry->pid : null,
                'queues' => $this->parseQueues($entry->options['queue'] ?? null),
                'process_count' => $entry !== null ? array_sum((array) $entry->processes) : 0,
                'processes' => $entry !== null ? (array) $entry->processes : [],
            ] + $heartbeat;
        }

        return $rows;
    }

    /**
     * @param  array<string, int>  $heartbeats
     * @return array<int, array<string, mixed>>
     */
    protected function buildMasters(array $heartbeats, int $now): array
    {
        $live = collect($this->masters->all())
            ->keyBy('name')
            ->all();

        $rows = [];
        foreach ($heartbeats as $name => $lastHeartbeatAt) {
            $heartbeat = $this->heartbeatFields($lastHeartbeatAt, self::MASTER_ALIVE_SECONDS, $now);
            $entry = $live[$name] ?? null;

            $rows[] = [
                'name' => $name,
                'status' => $entry->status ?? ($heartbeat['is_stale'] ? 'stale' : 'unknown'),
                'environment' => $entry->environment ?? null,
                'pid' => isset($entry->pid) ? (int) $entry->pid : null,
                'supervisor_count' => $entry !== null ? count((array) $entry->supervisors) : 0,
            ] + $heartbeat;
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
