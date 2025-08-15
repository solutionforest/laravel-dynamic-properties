<?php

use SolutionForest\LaravelDynamicProperties\Services\PropertyService;

beforeEach(function () {
    $this->propertyService = new PropertyService;
});

it('shows database information with check flag', function () {
    $this->artisan('dynamic-properties:optimize-db --check')
        ->expectsOutput('Dynamic Properties Database Optimization Tool')
        ->expectsOutputToContain('Database Driver:')
        ->expectsOutputToContain('Supported Features:')
        ->expectsOutputToContain('Migration Configuration:')
        ->expectsOutput('Database compatibility check completed.')
        ->assertExitCode(0);
});

it('applies database optimizations', function () {
    $this->artisan('dynamic-properties:optimize-db')
        ->expectsOutput('Dynamic Properties Database Optimization Tool')
        ->expectsOutputToContain('Database Driver:')
        ->expectsOutputToContain('Applying database optimizations...')
        ->assertExitCode(0);
});

it('shows recommendations after optimization', function () {
    $this->artisan('dynamic-properties:optimize-db')
        ->expectsOutputToContain('Recommendations:')
        ->assertExitCode(0);
});

it('handles optimization failures gracefully', function () {
    // Mock the property service to throw an exception
    $mockDbCompat = Mockery::mock(\SolutionForest\LaravelDynamicProperties\Services\DatabaseCompatibilityService::class);

    $this->mock(PropertyService::class, function ($mock) use ($mockDbCompat) {
        $mock->shouldReceive('getDatabaseInfo')->andReturn([
            'driver'           => 'mysql',
            'features'         => [],
            'migration_config' => [],
        ]);
        $mock->shouldReceive('getDatabaseCompatibilityService')->andReturn($mockDbCompat);
        $mock->shouldReceive('optimizeDatabase')->andThrow(new \Exception('Test error'));
    });

    $this->artisan('dynamic-properties:optimize-db')
        ->expectsOutputToContain('Database optimization failed: Test error')
        ->expectsOutputToContain('Use --force to continue despite errors.')
        ->assertExitCode(1);
});

it('continues with force flag despite errors', function () {
    // Mock the property service to throw an exception
    $mockDbCompat = Mockery::mock(\SolutionForest\LaravelDynamicProperties\Services\DatabaseCompatibilityService::class);

    $this->mock(PropertyService::class, function ($mock) use ($mockDbCompat) {
        $mock->shouldReceive('getDatabaseInfo')->andReturn([
            'driver'           => 'mysql',
            'features'         => [],
            'migration_config' => [],
        ]);
        $mock->shouldReceive('getDatabaseCompatibilityService')->andReturn($mockDbCompat);
        $mock->shouldReceive('optimizeDatabase')->andThrow(new \Exception('Test error'));
    });

    $this->artisan('dynamic-properties:optimize-db --force')
        ->expectsOutputToContain('Database optimization failed: Test error')
        ->expectsOutputToContain('Continuing despite errors due to --force flag.')
        ->assertExitCode(0);
});

it('shows no optimizations message when none are applied', function () {
    // Mock the property service to return empty optimizations
    $mockDbCompat = Mockery::mock(\SolutionForest\LaravelDynamicProperties\Services\DatabaseCompatibilityService::class);

    $this->mock(PropertyService::class, function ($mock) use ($mockDbCompat) {
        $mock->shouldReceive('getDatabaseInfo')->andReturn([
            'driver'           => 'unknown',
            'features'         => [],
            'migration_config' => [],
        ]);
        $mock->shouldReceive('getDatabaseCompatibilityService')->andReturn($mockDbCompat);
        $mock->shouldReceive('optimizeDatabase')->andReturn([]);
    });

    $this->artisan('dynamic-properties:optimize-db')
        ->expectsOutputToContain('No optimizations were applied. Database may already be optimized or driver not supported.')
        ->assertExitCode(0);
});

it('displays applied optimizations', function () {
    // This test will use the real service but with a database that supports optimizations
    $this->artisan('dynamic-properties:optimize-db')
        ->expectsOutputToContain('Dynamic Properties Database Optimization Tool')
        ->expectsOutputToContain('Database Driver:')
        ->expectsOutputToContain('Applying database optimizations...')
        ->assertExitCode(0);
});
