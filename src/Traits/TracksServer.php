<?php

namespace Ashiqfardus\HorizonRunningJobs\Traits;

/**
 * Add this trait to your Job classes to enable server identification.
 *
 * This trait implements the hybrid approach for tracking which server
 * is processing each job in a distributed Horizon setup.
 *
 * Usage:
 *
 * class YourJob implements ShouldQueue
 * {
 *     use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
 *     use \Ashiqfardus\HorizonRunningJobs\Traits\TracksServer;
 *
 *     public function __construct()
 *     {
 *         $this->initializeServerTracking();
 *     }
 * }
 */
trait TracksServer
{
    /**
     * The identifier of the server that created/processes this job.
     */
    public string $supervisor_id;

    /**
     * Initialize server tracking.
     * Call this in your job's constructor.
     */
    public function initializeServerTracking(): void
    {
        $this->supervisor_id = $this->getServerIdentifier();
    }

    /**
     * Get the server identifier from config or auto-detect from Horizon.
     */
    protected function getServerIdentifier(): string
    {
        // If explicitly configured in package config, use that
        $configured = config('horizon-running-jobs.server_identifier');
        if (!empty($configured)) {
            return $configured;
        }

        // Auto-detect from Horizon config
        $hostname = gethostname();

        // Check if hostname is used as supervisor key in horizon.defaults
        $defaults = config('horizon.defaults', []);
        if (array_key_exists($hostname, $defaults)) {
            return $hostname;
        }

        // Check horizon.environments.{current_env}
        $currentEnv = app()->environment();
        $envConfig = config("horizon.environments.{$currentEnv}", []);
        if (array_key_exists($hostname, $envConfig)) {
            return $hostname;
        }

        // Fallback to hostname
        return $hostname;
    }

    /**
     * Get the tags that should be assigned to the job.
     *
     * Override this method if you need additional tags.
     *
     * @return array<int, string>
     */
    public function tags(): array
    {
        return [
            'server:' . $this->getServerIdentifier(),
            'environment:' . app()->environment(),
            'type:' . class_basename($this),
        ];
    }
}

