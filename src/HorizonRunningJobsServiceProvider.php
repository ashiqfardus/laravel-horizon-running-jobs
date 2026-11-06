<?php

namespace Ashiqfardus\HorizonRunningJobs;

use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Illuminate\Support\ServiceProvider;

class HorizonRunningJobsServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/horizon-running-jobs.php',
            'horizon-running-jobs'
        );

        $this->app->singleton(RunningJobsManager::class, function ($app) {
            return new RunningJobsManager(
                config('horizon-running-jobs')
            );
        });

        $this->app->alias(RunningJobsManager::class, 'running-jobs');
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->trackWorkers();

        // Publish config
        $this->publishes([
            __DIR__ . '/../config/horizon-running-jobs.php' => config_path('horizon-running-jobs.php'),
        ], 'horizon-running-jobs-config');

        // Publish assets (Vue component and widget) — DEPRECATED, kept for v1 users.
        $this->publishes([
            __DIR__ . '/../resources/js' => public_path('vendor/horizon-running-jobs'),
        ], 'horizon-running-jobs-assets');

        // Load + publish Blade views (the new UI). Anonymous Blade components
        // in resources/views/components/ become <x-horizon-running-jobs::...>.
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'horizon-running-jobs');

        $this->publishes([
            __DIR__ . '/../resources/views' => resource_path('views/vendor/horizon-running-jobs'),
        ], 'horizon-running-jobs-views');

        // Publish the scoped CSS so users can serve it from their public dir.
        $this->publishes([
            __DIR__ . '/../resources/css' => public_path('vendor/horizon-running-jobs'),
        ], 'horizon-running-jobs-css');

        // Register commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                Commands\ListRunningJobsCommand::class,
                Commands\ListSupervisorsCommand::class,
                Commands\ListQueueDepthsCommand::class,
                Commands\DiagnoseCommand::class,
                Commands\ReleaseCommand::class,
            ]);
        }

        // Register routes (optional)
        $this->registerRoutes();
    }

    /**
     * Register the package routes.
     */
    protected function registerRoutes(): void
    {
        if (config('horizon-running-jobs.routes.enabled', true)) {
            $this->loadRoutesFrom(__DIR__ . '/routes.php');
        }
    }

    /**
     * Inside Horizon workers, record which supervisor runs each job, so the
     * package does not have to rely on tags written at dispatch time.
     */
    protected function trackWorkers(): void
    {
        $events = $this->app['events'];
        $manager = fn () => $this->app->make(RunningJobsManager::class);

        $events->listen(JobProcessing::class, fn ($event) => $manager()->recordWorker($event->job));

        $finished = [JobProcessed::class, JobFailed::class];
        if (class_exists(JobReleasedAfterException::class)) {
            $finished[] = JobReleasedAfterException::class;
        }

        $events->listen($finished, fn ($event) => $manager()->forgetWorker($event->job));
    }
}
