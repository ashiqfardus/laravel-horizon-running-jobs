<?php

namespace Ashiqfardus\HorizonRunningJobs\Facades;

use Illuminate\Support\Facades\Facade;
use Ashiqfardus\HorizonRunningJobs\RunningJobsManager;

/**
 * @method static array getRunningJobs(?string $hostname = null, bool $showAll = false, ?array $queues = null)
 * @method static array getStats(?array $queues = null)
 * @method static string extractServerIdentifier(array $jobDetails)
 * @method static string formatDuration(int $seconds)
 * @method static void clearCache()
 *
 * @see \Ashiqfardus\HorizonRunningJobs\RunningJobsManager
 */
class RunningJobs extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return RunningJobsManager::class;
    }
}

