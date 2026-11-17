<?php

namespace SolutionForest\LaravelDynamicProperties\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase as BaseTestCase;
use SolutionForest\LaravelDynamicProperties\DynamicPropertyServiceProvider;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected function getPackageProviders($app)
    {
        return [DynamicPropertyServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app)
    {
        // SQLite :memory: by default; CI sets DB_CONNECTION=mysql|pgsql to run
        // the driver-specific code paths against the real database.
        $driver = env('DB_CONNECTION', 'sqlite');

        $connection = match ($driver) {
            'mysql' => [
                'driver'    => 'mysql',
                'host'      => env('DB_HOST', '127.0.0.1'),
                'port'      => env('DB_PORT', '3306'),
                'database'  => env('DB_DATABASE', 'testing'),
                'username'  => env('DB_USERNAME', 'root'),
                'password'  => env('DB_PASSWORD', ''),
                'charset'   => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix'    => '',
            ],
            'pgsql' => [
                'driver'   => 'pgsql',
                'host'     => env('DB_HOST', '127.0.0.1'),
                'port'     => env('DB_PORT', '5432'),
                'database' => env('DB_DATABASE', 'testing'),
                'username' => env('DB_USERNAME', 'postgres'),
                'password' => env('DB_PASSWORD', ''),
                'charset'  => 'utf8',
                'prefix'   => '',
                'schema'   => 'public',
            ],
            default => [
                'driver'   => 'sqlite',
                'database' => ':memory:',
                'prefix'   => '',
            ],
        };

        $app['config']->set('database.default', 'testbench');
        $app['config']->set('database.connections.testbench', $connection);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Run the package migrations
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
