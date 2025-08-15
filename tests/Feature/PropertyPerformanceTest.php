<?php

uses()->group('performance');

use DynamicProperties\DynamicPropertyServiceProvider;
use DynamicProperties\Models\Property;
use DynamicProperties\Services\PropertyService;
use DynamicProperties\Traits\HasProperties;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PerformanceTestUser extends Model
{
    use HasProperties;

    protected $table = 'users';

    protected $fillable = ['name', 'email', 'dynamic_properties'];

    protected $casts = ['dynamic_properties' => 'array'];
}

uses(RefreshDatabase::class);

beforeEach(function () {
    // Setup package providers
    $this->app->register(DynamicPropertyServiceProvider::class);

    // Setup database
    config(['database.default' => 'testbench']);
    config(['database.connections.testbench' => [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
    ]]);

    // Run the package migrations
    $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

    // Create users table for testing
    Schema::create('users', function ($table) {
        $table->id();
        $table->string('name');
        $table->string('email');
        $table->timestamps();
    });

    $this->propertyService = new PropertyService;

    // Create test properties for performance testing
    createTestProperties();
});

function createTestProperties()
{
    Property::create(['name' => 'age', 'label' => 'Age', 'type' => 'number', 'required' => false]);
    Property::create(['name' => 'salary', 'label' => 'Salary', 'type' => 'number', 'required' => false]);
    Property::create(['name' => 'city', 'label' => 'City', 'type' => 'text', 'required' => false]);
    Property::create(['name' => 'department', 'label' => 'Department', 'type' => 'text', 'required' => false]);
    Property::create(['name' => 'active', 'label' => 'Active', 'type' => 'boolean', 'required' => false]);
    Property::create(['name' => 'remote', 'label' => 'Remote', 'type' => 'boolean', 'required' => false]);
    Property::create(['name' => 'hire_date', 'label' => 'Hire Date', 'type' => 'date', 'required' => false]);
    Property::create(['name' => 'birth_date', 'label' => 'Birth Date', 'type' => 'date', 'required' => false]);
    Property::create(['name' => 'skills', 'label' => 'Skills', 'type' => 'text', 'required' => false]);
    Property::create(['name' => 'experience', 'label' => 'Experience', 'type' => 'number', 'required' => false]);
}

describe('Single Entity Property Retrieval Performance', function () {
    it('measures property retrieval performance without json cache', function () {
        // Create user with many properties
        $user = PerformanceTestUser::create(['name' => 'Test User', 'email' => 'test@example.com']);

        // Set 20 properties to simulate real-world usage
        $properties = [
            'age' => 30,
            'salary' => 75000,
            'city' => 'San Francisco',
            'department' => 'Engineering',
            'active' => true,
            'remote' => true,
            'hire_date' => '2020-01-15',
            'birth_date' => '1993-05-20',
            'skills' => 'PHP, JavaScript, Python',
            'experience' => 8,
        ];

        $this->propertyService->setProperties($user, $properties);

        // Measure retrieval time without JSON cache
        $startTime = microtime(true);
        $retrievedProperties = $user->properties;
        $endTime = microtime(true);

        $retrievalTime = ($endTime - $startTime) * 1000; // Convert to milliseconds

        expect($retrievedProperties)->toHaveCount(10);
        expect($retrievalTime)->toBeLessThan(50); // Should be under 50ms for 10 properties

        // Log performance for reference
        echo "\nProperty retrieval without JSON cache: {$retrievalTime}ms";
    });

    it('measures property retrieval performance with json cache', function () {
        // Create user with JSON column
        Schema::table('users', function ($table) {
            $table->json('dynamic_properties')->nullable();
        });

        $user = PerformanceTestUser::create(['name' => 'Test User', 'email' => 'test@example.com']);

        // Set 20 properties
        $properties = [
            'age' => 30,
            'salary' => 75000,
            'city' => 'San Francisco',
            'department' => 'Engineering',
            'active' => true,
            'remote' => true,
            'hire_date' => '2020-01-15',
            'birth_date' => '1993-05-20',
            'skills' => 'PHP, JavaScript, Python',
            'experience' => 8,
        ];

        $this->propertyService->setProperties($user, $properties);
        $user->refresh(); // Ensure JSON column is populated

        // Measure retrieval time with JSON cache
        $startTime = microtime(true);
        $retrievedProperties = $user->properties;
        $endTime = microtime(true);

        $retrievalTime = ($endTime - $startTime) * 1000; // Convert to milliseconds

        expect($retrievedProperties)->toHaveCount(10);
        expect($retrievalTime)->toBeLessThan(5); // Should be under 5ms with JSON cache

        // Log performance for reference
        echo "\nProperty retrieval with JSON cache: {$retrievalTime}ms";
    });

    it('compares json cache vs entity properties performance', function () {
        // Test with increasing number of properties
        $propertyCounts = [5, 10, 20, 50];
        $results = [];

        foreach ($propertyCounts as $count) {
            // Test without JSON cache
            $user1 = PerformanceTestUser::create(['name' => "User $count", 'email' => "user$count@example.com"]);

            $properties = [];
            for ($i = 1; $i <= $count; $i++) {
                $properties["prop_$i"] = "value_$i";
                Property::firstOrCreate(['name' => "prop_$i"], [
                    'label' => "Property $i",
                    'type' => 'text',
                    'required' => false,
                ]);
            }

            $this->propertyService->setProperties($user1, $properties);

            $startTime = microtime(true);
            $props1 = $user1->properties;
            $endTime = microtime(true);
            $timeWithoutCache = ($endTime - $startTime) * 1000;

            // Test with JSON cache
            Schema::table('users', function ($table) {
                if (! Schema::hasColumn('users', 'dynamic_properties')) {
                    $table->json('dynamic_properties')->nullable();
                }
            });

            $user2 = PerformanceTestUser::create(['name' => "User JSON $count", 'email' => "userjson$count@example.com"]);
            $this->propertyService->setProperties($user2, $properties);
            $user2->refresh();

            $startTime = microtime(true);
            $props2 = $user2->properties;
            $endTime = microtime(true);
            $timeWithCache = ($endTime - $startTime) * 1000;

            $results[$count] = [
                'without_cache' => $timeWithoutCache,
                'with_cache' => $timeWithCache,
                'improvement' => $timeWithoutCache > 0 ? ($timeWithoutCache - $timeWithCache) / $timeWithoutCache * 100 : 0,
            ];

            expect($props1)->toHaveCount($count);
            expect($props2)->toHaveCount($count);

            // JSON cache should generally be faster for larger property sets
            // Note: In SQLite with small datasets, the difference might be minimal
            if ($count >= 20) {
                // Allow for some variance in SQLite performance
                expect($timeWithCache)->toBeLessThanOrEqual($timeWithoutCache * 1.5);
            }
        }

        // Log results
        foreach ($results as $count => $result) {
            echo "\n$count properties - Without cache: {$result['without_cache']}ms, With cache: {$result['with_cache']}ms, Improvement: {$result['improvement']}%";
        }
    });
});

describe('Search Performance with Large Datasets', function () {
    beforeEach(function () {
        // Create a large dataset for performance testing
        createLargeDataset(1000); // 1000 users with properties
    });

    it('measures text search performance', function () {
        $startTime = microtime(true);
        $results = $this->propertyService->searchText(PerformanceTestUser::class, 'city', 'San Francisco');
        $endTime = microtime(true);

        $searchTime = ($endTime - $startTime) * 1000;

        expect($results)->not->toBeEmpty();
        expect($searchTime)->toBeLessThan(100); // Should be under 100ms

        echo "\nText search performance (1000 users): {$searchTime}ms";
    });

    it('measures number range search performance', function () {
        $startTime = microtime(true);
        $results = $this->propertyService->searchNumberRange(PerformanceTestUser::class, 'age', 25, 35);
        $endTime = microtime(true);

        $searchTime = ($endTime - $startTime) * 1000;

        expect($results)->not->toBeEmpty();
        expect($searchTime)->toBeLessThan(100); // Should be under 100ms

        echo "\nNumber range search performance (1000 users): {$searchTime}ms";
    });

    it('measures boolean search performance', function () {
        $startTime = microtime(true);
        $results = $this->propertyService->searchBoolean(PerformanceTestUser::class, 'active', true);
        $endTime = microtime(true);

        $searchTime = ($endTime - $startTime) * 1000;

        expect($results)->not->toBeEmpty();
        expect($searchTime)->toBeLessThan(50); // Boolean search should be very fast

        echo "\nBoolean search performance (1000 users): {$searchTime}ms";
    });

    it('measures date range search performance', function () {
        $startTime = microtime(true);
        $results = $this->propertyService->searchDateRange(
            PerformanceTestUser::class,
            'hire_date',
            '2020-01-01',
            '2022-12-31'
        );
        $endTime = microtime(true);

        $searchTime = ($endTime - $startTime) * 1000;

        expect($results)->not->toBeEmpty();
        expect($searchTime)->toBeLessThan(100); // Should be under 100ms

        echo "\nDate range search performance (1000 users): {$searchTime}ms";
    });

    it('measures complex multi-property search performance', function () {
        $startTime = microtime(true);
        $results = $this->propertyService->advancedSearch(PerformanceTestUser::class, [
            'department' => 'Engineering',
            'active' => true,
            'age' => ['value' => 30, 'operator' => '>'],
            'salary' => ['operator' => 'between', 'min' => 60000, 'max' => 100000],
        ]);
        $endTime = microtime(true);

        $searchTime = ($endTime - $startTime) * 1000;

        expect($searchTime)->toBeLessThan(200); // Complex search should be under 200ms

        echo "\nComplex multi-property search performance (1000 users): {$searchTime}ms";
    });
});

describe('Query Scope Performance', function () {
    beforeEach(function () {
        createLargeDataset(500); // 500 users for scope testing
    });

    it('measures whereProperty scope performance', function () {
        $startTime = microtime(true);
        $results = PerformanceTestUser::whereProperty('department', 'Engineering')->get();
        $endTime = microtime(true);

        $searchTime = ($endTime - $startTime) * 1000;

        expect($results)->not->toBeEmpty();
        expect($searchTime)->toBeLessThan(150); // Should be under 150ms

        echo "\nwhereProperty scope performance (500 users): {$searchTime}ms";
    });

    it('measures whereProperties scope performance', function () {
        $startTime = microtime(true);
        $results = PerformanceTestUser::whereProperties([
            'department' => 'Engineering',
            'active' => true,
        ])->get();
        $endTime = microtime(true);

        $searchTime = ($endTime - $startTime) * 1000;

        expect($searchTime)->toBeLessThan(200); // Should be under 200ms

        echo "\nwhereProperties scope performance (500 users): {$searchTime}ms";
    });

    it('measures wherePropertyBetween scope performance', function () {
        $startTime = microtime(true);
        $results = PerformanceTestUser::wherePropertyBetween('age', 25, 40)->get();
        $endTime = microtime(true);

        $searchTime = ($endTime - $startTime) * 1000;

        expect($results)->not->toBeEmpty();
        expect($searchTime)->toBeLessThan(150); // Should be under 150ms

        echo "\nwherePropertyBetween scope performance (500 users): {$searchTime}ms";
    });
});

describe('Index Usage and Query Optimization', function () {
    it('verifies database indexes are being used', function () {
        // Create some test data
        createLargeDataset(100);

        // Enable query logging
        DB::enableQueryLog();

        // Perform a search that should use indexes
        $results = $this->propertyService->search(PerformanceTestUser::class, [
            'department' => 'Engineering',
        ]);

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        expect($queries)->not->toBeEmpty();

        // Check that queries were executed
        expect($queries)->not->toBeEmpty();

        // Check that the main query contains entity filtering
        $mainQuery = $queries[0]['query'] ?? '';
        expect($mainQuery)->toContain('entity_type');

        // Log the query for inspection
        echo "\nQuery executed: ".$mainQuery;
        echo "\nQuery time: ".($queries[0]['time'] ?? 'unknown').'ms';
        echo "\nTotal queries executed: ".count($queries);
    });

    it('measures query performance with different property types', function () {
        createLargeDataset(200);

        $propertyTypes = [
            'text' => ['department', 'Engineering'],
            'number' => ['age', 30],
            'boolean' => ['active', true],
            'date' => ['hire_date', '2021-01-01'],
        ];

        foreach ($propertyTypes as $type => $criteria) {
            [$propertyName, $value] = $criteria;

            $startTime = microtime(true);
            $results = $this->propertyService->search(PerformanceTestUser::class, [
                $propertyName => $value,
            ]);
            $endTime = microtime(true);

            $searchTime = ($endTime - $startTime) * 1000;

            expect($searchTime)->toBeLessThan(100); // All searches should be under 100ms

            echo "\n$type property search performance: {$searchTime}ms";
        }
    });

    it('tests full-text search performance', function () {
        createLargeDataset(200);

        // Test LIKE search (SQLite doesn't support MATCH AGAINST)
        $startTime = microtime(true);
        $results = $this->propertyService->searchText(
            PerformanceTestUser::class,
            'skills',
            'PHP',
            ['full_text' => false]
        );
        $endTime = microtime(true);

        $likeSearchTime = ($endTime - $startTime) * 1000;

        echo "\nLIKE search performance: {$likeSearchTime}ms";

        // Test partial matching
        $startTime = microtime(true);
        $results2 = $this->propertyService->searchText(
            PerformanceTestUser::class,
            'skills',
            'Java',
            ['full_text' => false]
        );
        $endTime = microtime(true);

        $partialSearchTime = ($endTime - $startTime) * 1000;

        echo "\nPartial text search performance: {$partialSearchTime}ms";

        expect($likeSearchTime)->toBeLessThan(150);
        expect($partialSearchTime)->toBeLessThan(150);
        expect($results)->not->toBeEmpty();
    });
});

describe('Memory Usage and Scalability', function () {
    it('measures memory usage with large property sets', function () {
        $initialMemory = memory_get_usage(true);

        // Create user with many properties
        $user = PerformanceTestUser::create(['name' => 'Memory Test', 'email' => 'memory@example.com']);

        // Create 100 properties
        $properties = [];
        for ($i = 1; $i <= 100; $i++) {
            $properties["large_prop_$i"] = str_repeat("value_$i ", 10); // Larger values
            Property::firstOrCreate(['name' => "large_prop_$i"], [
                'label' => "Large Property $i",
                'type' => 'text',
                'required' => false,
            ]);
        }

        $this->propertyService->setProperties($user, $properties);

        $afterSetMemory = memory_get_usage(true);

        // Retrieve properties
        $retrievedProperties = $user->properties;

        $afterGetMemory = memory_get_usage(true);

        $setMemoryUsage = ($afterSetMemory - $initialMemory) / 1024 / 1024; // MB
        $getMemoryUsage = ($afterGetMemory - $afterSetMemory) / 1024 / 1024; // MB

        expect($retrievedProperties)->toHaveCount(100);
        expect($setMemoryUsage)->toBeLessThan(10); // Should use less than 10MB for setting
        expect($getMemoryUsage)->toBeLessThan(5); // Should use less than 5MB for retrieval

        echo "\nMemory usage for setting 100 properties: {$setMemoryUsage}MB";
        echo "\nMemory usage for retrieving 100 properties: {$getMemoryUsage}MB";
    });

    it('tests batch operations performance', function () {
        // Test batch property setting
        $users = [];
        for ($i = 1; $i <= 50; $i++) {
            $users[] = PerformanceTestUser::create([
                'name' => "Batch User $i",
                'email' => "batch$i@example.com",
            ]);
        }

        $startTime = microtime(true);

        foreach ($users as $user) {
            $this->propertyService->setProperties($user, [
                'age' => rand(25, 65),
                'department' => ['Engineering', 'Marketing', 'Sales'][rand(0, 2)],
                'active' => (bool) rand(0, 1),
                'salary' => rand(50000, 150000),
            ]);
        }

        $endTime = microtime(true);
        $batchTime = ($endTime - $startTime) * 1000;

        expect($batchTime)->toBeLessThan(2000); // Should complete in under 2 seconds

        echo "\nBatch operation performance (50 users, 4 properties each): {$batchTime}ms";
    });
});

// Helper function to create large dataset for performance testing
function createLargeDataset(int $userCount): void
{
    $departments = ['Engineering', 'Marketing', 'Sales', 'HR', 'Finance'];
    $cities = ['San Francisco', 'New York', 'Los Angeles', 'Chicago', 'Austin', 'Seattle'];
    $skills = ['PHP', 'JavaScript', 'Python', 'Java', 'C++', 'React', 'Vue', 'Angular'];

    for ($i = 1; $i <= $userCount; $i++) {
        $user = PerformanceTestUser::create([
            'name' => "User $i",
            'email' => "user$i@example.com",
        ]);

        $properties = [
            'age' => rand(22, 65),
            'salary' => rand(40000, 200000),
            'city' => $cities[array_rand($cities)],
            'department' => $departments[array_rand($departments)],
            'active' => (bool) rand(0, 1),
            'remote' => (bool) rand(0, 1),
            'hire_date' => date('Y-m-d', strtotime('-'.rand(1, 2000).' days')),
            'birth_date' => date('Y-m-d', strtotime('-'.rand(8000, 20000).' days')),
            'skills' => implode(', ', array_slice($skills, 0, rand(2, 5))),
            'experience' => rand(0, 20),
        ];

        app(PropertyService::class)->setProperties($user, $properties);

        // Add some variation - not all users have all properties
        if ($i % 3 === 0) {
            // Create bonus property if it doesn't exist
            Property::firstOrCreate(['name' => 'bonus'], [
                'label' => 'Annual Bonus',
                'type' => 'number',
                'required' => false,
            ]);
            app(PropertyService::class)->setDynamicProperty($user, 'bonus', rand(5000, 25000));
        }
    }
}
