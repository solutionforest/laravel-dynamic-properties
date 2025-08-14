<?php

namespace DynamicProperties\Tests;

use Orchestra\Testbench\TestCase as BaseTestCase;
use DynamicProperties\DynamicPropertyServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected function getPackageProviders($app)
    {
        return [DynamicPropertyServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app)
    {
        // Setup default database to use sqlite :memory:
        $app['config']->set('database.default', 'testbench');
        $app['config']->set('database.connections.testbench', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        
        // Run the package migrations
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }
}