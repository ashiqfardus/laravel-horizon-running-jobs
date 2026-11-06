<?php

namespace Ashiqfardus\HorizonRunningJobs\Exceptions;

use RuntimeException;

/**
 * The live-supervisor lookup failed, so the package cannot tell which jobs
 * are orphaned. Releasing on a guess would requeue jobs that are still
 * running, so orphan release stops instead.
 */
class LiveSupervisorsUnavailable extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(
            'Could not read the live Horizon supervisors from Redis, so orphaned jobs cannot be identified. Nothing was released.'
        );
    }
}
