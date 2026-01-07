<?php

namespace Ashiqfardus\HorizonRunningJobs;

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
        // Publish config
        $this->publishes([
            __DIR__ . '/../config/horizon-running-jobs.php' => config_path('horizon-running-jobs.php'),
        ], 'horizon-running-jobs-config');

        // Publish assets (Vue component and widget)
        $this->publishes([
            __DIR__ . '/../resources/js' => public_path('vendor/horizon-running-jobs'),
        ], 'horizon-running-jobs-assets');

        // Register commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                Commands\ListRunningJobsCommand::class,
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
}

