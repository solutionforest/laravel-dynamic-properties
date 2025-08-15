<?php

namespace SolutionForest\LaravelDynamicProperties;

use Illuminate\Support\ServiceProvider;
use SolutionForest\LaravelDynamicProperties\Console\Commands\CacheSyncCommand;
use SolutionForest\LaravelDynamicProperties\Console\Commands\DatabaseOptimizeCommand;
use SolutionForest\LaravelDynamicProperties\Console\Commands\PropertyCreateCommand;
use SolutionForest\LaravelDynamicProperties\Console\Commands\PropertyDeleteCommand;
use SolutionForest\LaravelDynamicProperties\Console\Commands\PropertyListCommand;
use SolutionForest\LaravelDynamicProperties\Services\PropertyService;

class DynamicPropertyServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Publish configuration
        $this->publishes([
            __DIR__.'/../config/dynamic-properties.php' => config_path('dynamic-properties.php'),
        ], 'dynamic-properties-config');

        // Publish migrations
        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'dynamic-properties-migrations');

        // Load migrations if running in package context
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        // Register commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                PropertyCreateCommand::class,
                PropertyListCommand::class,
                PropertyDeleteCommand::class,
                CacheSyncCommand::class,
                DatabaseOptimizeCommand::class,
            ]);
        }
    }

    public function register(): void
    {
        // Merge configuration
        $this->mergeConfigFrom(
            __DIR__.'/../config/dynamic-properties.php',
            'dynamic-properties'
        );

        // Register services
        $this->app->singleton(PropertyService::class, function ($app) {
            return new PropertyService($app['config']['dynamic-properties']);
        });

        // Register aliases
        $this->app->alias(PropertyService::class, 'dynamic-properties');

        // Register facade
        $this->app->bind('dynamic-properties.facade', function ($app) {
            return $app[PropertyService::class];
        });
    }
}
