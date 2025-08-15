<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use SolutionForest\LaravelDynamicProperties\DynamicPropertyServiceProvider;
use SolutionForest\LaravelDynamicProperties\Models\Property;
use SolutionForest\LaravelDynamicProperties\Services\PropertyService;
use SolutionForest\LaravelDynamicProperties\Traits\HasProperties;

class SearchTestUser extends Model
{
    use HasProperties;

    protected $table = 'users';

    protected $fillable = ['name', 'email'];
}

uses(RefreshDatabase::class);

beforeEach(function () {
    // Setup package providers
    $this->app->register(DynamicPropertyServiceProvider::class);

    // Setup database
    config(['database.default' => 'testbench']);
    config(['database.connections.testbench' => [
        'driver'   => 'sqlite',
        'database' => ':memory:',
        'prefix'   => '',
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

    // Create test properties
    Property::create([
        'name'     => 'age',
        'label'    => 'Age',
        'type'     => 'number',
        'required' => false,
    ]);

    Property::create([
        'name'     => 'city',
        'label'    => 'City',
        'type'     => 'text',
        'required' => false,
    ]);

    Property::create([
        'name'     => 'active',
        'label'    => 'Active',
        'type'     => 'boolean',
        'required' => false,
    ]);

    Property::create([
        'name'     => 'birth_date',
        'label'    => 'Birth Date',
        'type'     => 'date',
        'required' => false,
    ]);
});

it('can search by single property', function () {
    // Create test user
    $user = SearchTestUser::create(['name' => 'John Doe', 'email' => 'john@example.com']);

    // Set properties
    $this->propertyService->setDynamicProperty($user, 'age', 25);
    $this->propertyService->setDynamicProperty($user, 'city', 'New York');
    $this->propertyService->setDynamicProperty($user, 'active', true);

    // Test search by age
    $results = $this->propertyService->search(SearchTestUser::class, ['age' => 25]);
    expect($results)->toContain($user->id);

    // Test search by city
    $results = $this->propertyService->search(SearchTestUser::class, ['city' => 'New York']);
    expect($results)->toContain($user->id);

    // Test search by boolean
    $results = $this->propertyService->search(SearchTestUser::class, ['active' => true]);
    expect($results)->toContain($user->id);
});

it('can search by multiple properties', function () {
    // Create test users
    $user1 = SearchTestUser::create(['name' => 'John Doe', 'email' => 'john@example.com']);
    $user2 = SearchTestUser::create(['name' => 'Jane Smith', 'email' => 'jane@example.com']);

    // Set properties for user1
    $this->propertyService->setDynamicProperty($user1, 'age', 25);
    $this->propertyService->setDynamicProperty($user1, 'city', 'New York');
    $this->propertyService->setDynamicProperty($user1, 'active', true);

    // Set properties for user2
    $this->propertyService->setDynamicProperty($user2, 'age', 30);
    $this->propertyService->setDynamicProperty($user2, 'city', 'New York');
    $this->propertyService->setDynamicProperty($user2, 'active', false);

    // Search for users in New York who are active
    $results = $this->propertyService->search(SearchTestUser::class, [
        'city'   => 'New York',
        'active' => true,
    ]);

    expect($results)->toContain($user1->id);
    expect($results)->not->toContain($user2->id);
});

it('can use query scopes for single property', function () {
    // Create test user
    $user = SearchTestUser::create(['name' => 'John Doe', 'email' => 'john@example.com']);

    // Set properties
    $this->propertyService->setDynamicProperty($user, 'age', 25);
    $this->propertyService->setDynamicProperty($user, 'city', 'New York');

    // Test whereProperty scope
    $results = SearchTestUser::whereProperty('age', 25)->get();
    expect($results)->toHaveCount(1);
    expect($results->first()->id)->toBe($user->id);

    // Test with operator
    $results = SearchTestUser::whereProperty('age', 20, '>')->get();
    expect($results)->toHaveCount(1);

    // Test text search with LIKE
    $results = SearchTestUser::whereProperty('city', '%York%', 'LIKE')->get();
    expect($results)->toHaveCount(1);
});

it('can use query scopes for multiple properties', function () {
    // Create test users
    $user1 = SearchTestUser::create(['name' => 'John Doe', 'email' => 'john@example.com']);
    $user2 = SearchTestUser::create(['name' => 'Jane Smith', 'email' => 'jane@example.com']);

    // Set properties
    $this->propertyService->setProperties($user1, [
        'age'    => 25,
        'city'   => 'New York',
        'active' => true,
    ]);

    $this->propertyService->setProperties($user2, [
        'age'    => 30,
        'city'   => 'Los Angeles',
        'active' => true,
    ]);

    // Test whereProperties scope
    $results = SearchTestUser::whereProperties([
        'city'   => 'New York',
        'active' => true,
    ])->get();

    expect($results)->toHaveCount(1);
    expect($results->first()->id)->toBe($user1->id);
});

it('can search with advanced criteria', function () {
    // Create test users
    $user1 = SearchTestUser::create(['name' => 'John Doe', 'email' => 'john@example.com']);
    $user2 = SearchTestUser::create(['name' => 'Jane Smith', 'email' => 'jane@example.com']);
    $user3 = SearchTestUser::create(['name' => 'Bob Wilson', 'email' => 'bob@example.com']);

    // Set properties
    $this->propertyService->setDynamicProperty($user1, 'age', 25);
    $this->propertyService->setDynamicProperty($user2, 'age', 35);
    $this->propertyService->setDynamicProperty($user3, 'age', 45);

    // Test advanced search with operator
    $results = $this->propertyService->search(SearchTestUser::class, [
        'age' => ['value' => 30, 'operator' => '>'],
    ]);

    expect($results)->not->toContain($user1->id);
    expect($results)->toContain($user2->id);
    expect($results)->toContain($user3->id);
});

it('can search number ranges', function () {
    // Create test users
    $user1 = SearchTestUser::create(['name' => 'John Doe', 'email' => 'john@example.com']);
    $user2 = SearchTestUser::create(['name' => 'Jane Smith', 'email' => 'jane@example.com']);
    $user3 = SearchTestUser::create(['name' => 'Bob Wilson', 'email' => 'bob@example.com']);

    // Set ages
    $this->propertyService->setDynamicProperty($user1, 'age', 25);
    $this->propertyService->setDynamicProperty($user2, 'age', 35);
    $this->propertyService->setDynamicProperty($user3, 'age', 45);

    // Search for users aged 30-40
    $results = $this->propertyService->searchNumberRange(SearchTestUser::class, 'age', 30, 40);

    expect($results)->not->toContain($user1->id);
    expect($results)->toContain($user2->id);
    expect($results)->not->toContain($user3->id);
});

it('can search text with like', function () {
    // Create test users
    $user1 = SearchTestUser::create(['name' => 'John Doe', 'email' => 'john@example.com']);
    $user2 = SearchTestUser::create(['name' => 'Jane Smith', 'email' => 'jane@example.com']);

    // Set cities
    $this->propertyService->setDynamicProperty($user1, 'city', 'New York');
    $this->propertyService->setDynamicProperty($user2, 'city', 'Los Angeles');

    // Search for cities containing "York"
    $results = $this->propertyService->searchText(SearchTestUser::class, 'city', 'York');

    expect($results)->toContain($user1->id);
    expect($results)->not->toContain($user2->id);
});

describe('Advanced Search Features', function () {
    beforeEach(function () {
        $this->propertyService = new PropertyService;

        // Create additional properties for comprehensive testing
        Property::create([
            'name'     => 'salary',
            'label'    => 'Salary',
            'type'     => 'number',
            'required' => false,
        ]);

        Property::create([
            'name'     => 'department',
            'label'    => 'Department',
            'type'     => 'select',
            'required' => false,
            'options'  => ['engineering', 'marketing', 'sales', 'hr'],
        ]);

        Property::create([
            'name'     => 'remote',
            'label'    => 'Remote Worker',
            'type'     => 'boolean',
            'required' => false,
        ]);
    });

    it('can search with BETWEEN operator for numbers', function () {
        $user1 = SearchTestUser::create(['name' => 'John', 'email' => 'john@example.com']);
        $user2 = SearchTestUser::create(['name' => 'Jane', 'email' => 'jane@example.com']);
        $user3 = SearchTestUser::create(['name' => 'Bob', 'email' => 'bob@example.com']);

        $this->propertyService->setDynamicProperty($user1, 'salary', 50000);
        $this->propertyService->setDynamicProperty($user2, 'salary', 75000);
        $this->propertyService->setDynamicProperty($user3, 'salary', 100000);

        $results = $this->propertyService->advancedSearch(SearchTestUser::class, [
            'salary' => [
                'operator' => 'between',
                'min'      => 60000,
                'max'      => 90000,
            ],
        ]);

        expect($results)->not->toContain($user1->id);
        expect($results)->toContain($user2->id);
        expect($results)->not->toContain($user3->id);
    });

    it('can search with IN operator for multiple values', function () {
        $user1 = SearchTestUser::create(['name' => 'John', 'email' => 'john@example.com']);
        $user2 = SearchTestUser::create(['name' => 'Jane', 'email' => 'jane@example.com']);
        $user3 = SearchTestUser::create(['name' => 'Bob', 'email' => 'bob@example.com']);

        $this->propertyService->setDynamicProperty($user1, 'department', 'engineering');
        $this->propertyService->setDynamicProperty($user2, 'department', 'marketing');
        $this->propertyService->setDynamicProperty($user3, 'department', 'sales');

        $results = $this->propertyService->advancedSearch(SearchTestUser::class, [
            'department' => [
                'operator' => 'in',
                'value'    => ['engineering', 'sales'],
            ],
        ]);

        expect($results)->toContain($user1->id);
        expect($results)->not->toContain($user2->id);
        expect($results)->toContain($user3->id);
    });

    it('can search with LIKE operator for partial text matching', function () {
        $user1 = SearchTestUser::create(['name' => 'John', 'email' => 'john@example.com']);
        $user2 = SearchTestUser::create(['name' => 'Jane', 'email' => 'jane@example.com']);
        $user3 = SearchTestUser::create(['name' => 'Bob', 'email' => 'bob@example.com']);

        $this->propertyService->setDynamicProperty($user1, 'city', 'New York City');
        $this->propertyService->setDynamicProperty($user2, 'city', 'Los Angeles');
        $this->propertyService->setDynamicProperty($user3, 'city', 'New Orleans');

        $results = $this->propertyService->advancedSearch(SearchTestUser::class, [
            'city' => [
                'operator' => 'like',
                'value'    => 'New',
                'options'  => ['case_sensitive' => false],
            ],
        ]);

        expect($results)->toContain($user1->id);
        expect($results)->not->toContain($user2->id);
        expect($results)->toContain($user3->id);
    });

    it('can search with NULL and NOT NULL operators', function () {
        $user1 = SearchTestUser::create(['name' => 'John', 'email' => 'john@example.com']);
        $user2 = SearchTestUser::create(['name' => 'Jane', 'email' => 'jane@example.com']);
        $user3 = SearchTestUser::create(['name' => 'Bob', 'email' => 'bob@example.com']);

        $this->propertyService->setDynamicProperty($user1, 'remote', true);
        $this->propertyService->setDynamicProperty($user2, 'remote', false);
        // user3 has no remote property set, but has another property so it exists in the table
        $this->propertyService->setDynamicProperty($user3, 'age', 30);

        $resultsWithRemote = $this->propertyService->advancedSearch(SearchTestUser::class, [
            'remote' => ['operator' => 'not null'],
        ]);

        expect($resultsWithRemote)->toContain($user1->id);
        expect($resultsWithRemote)->toContain($user2->id);
        expect($resultsWithRemote)->not->toContain($user3->id);

        $resultsWithoutRemote = $this->propertyService->advancedSearch(SearchTestUser::class, [
            'remote' => ['operator' => 'null'],
        ]);

        expect($resultsWithoutRemote)->not->toContain($user1->id);
        expect($resultsWithoutRemote)->not->toContain($user2->id);
        expect($resultsWithoutRemote)->toContain($user3->id);
    });

    it('can combine multiple advanced search criteria', function () {
        $user1 = SearchTestUser::create(['name' => 'John', 'email' => 'john@example.com']);
        $user2 = SearchTestUser::create(['name' => 'Jane', 'email' => 'jane@example.com']);
        $user3 = SearchTestUser::create(['name' => 'Bob', 'email' => 'bob@example.com']);
        $user4 = SearchTestUser::create(['name' => 'Alice', 'email' => 'alice@example.com']);

        // Set up complex data
        $this->propertyService->setProperties($user1, [
            'department' => 'engineering',
            'salary'     => 80000,
            'remote'     => true,
            'city'       => 'San Francisco',
        ]);

        $this->propertyService->setProperties($user2, [
            'department' => 'engineering',
            'salary'     => 70000,
            'remote'     => false,
            'city'       => 'New York',
        ]);

        $this->propertyService->setProperties($user3, [
            'department' => 'marketing',
            'salary'     => 85000,
            'remote'     => true,
            'city'       => 'San Francisco',
        ]);

        $this->propertyService->setProperties($user4, [
            'department' => 'engineering',
            'salary'     => 90000,
            'remote'     => true,
            'city'       => 'Austin',
        ]);

        // Search for: Engineering department, salary > 75000, remote workers, in San Francisco or Austin
        $results = $this->propertyService->advancedSearch(SearchTestUser::class, [
            'department' => 'engineering',
            'salary'     => ['value' => 75000, 'operator' => '>'],
            'remote'     => true,
            'city'       => ['operator' => 'in', 'value' => ['San Francisco', 'Austin']],
        ]);

        expect($results)->toContain($user1->id); // Engineering, 80k, remote, SF
        expect($results)->not->toContain($user2->id); // Engineering, 70k (too low), not remote, NY
        expect($results)->not->toContain($user3->id); // Marketing (wrong dept), 85k, remote, SF
        expect($results)->toContain($user4->id); // Engineering, 90k, remote, Austin
    });
});

describe('Date Range Search', function () {
    beforeEach(function () {
        $this->propertyService = new PropertyService;

        Property::create([
            'name'     => 'hire_date',
            'label'    => 'Hire Date',
            'type'     => 'date',
            'required' => false,
        ]);
    });

    it('can search date ranges', function () {
        $user1 = SearchTestUser::create(['name' => 'John', 'email' => 'john@example.com']);
        $user2 = SearchTestUser::create(['name' => 'Jane', 'email' => 'jane@example.com']);
        $user3 = SearchTestUser::create(['name' => 'Bob', 'email' => 'bob@example.com']);

        $this->propertyService->setDynamicProperty($user1, 'hire_date', '2020-01-15');
        $this->propertyService->setDynamicProperty($user2, 'hire_date', '2021-06-01');
        $this->propertyService->setDynamicProperty($user3, 'hire_date', '2022-12-10');

        $results = $this->propertyService->searchDateRange(
            SearchTestUser::class,
            'hire_date',
            '2021-01-01',
            '2021-12-31'
        );

        expect($results)->not->toContain($user1->id);
        expect($results)->toContain($user2->id);
        expect($results)->not->toContain($user3->id);
    });
});

describe('Performance and Edge Cases', function () {
    it('handles empty search criteria gracefully', function () {
        $results = $this->propertyService->search(SearchTestUser::class, []);
        expect($results)->toBeEmpty();
    });

    it('handles search for non-existent properties gracefully', function () {
        $user = SearchTestUser::create(['name' => 'John', 'email' => 'john@example.com']);

        $results = $this->propertyService->search(SearchTestUser::class, [
            'non_existent_property' => 'some_value',
        ]);

        expect($results)->toBeEmpty();
    });

    it('handles large result sets efficiently', function () {
        // Create many users with properties
        $users = [];
        for ($i = 1; $i <= 50; $i++) {
            $user = SearchTestUser::create([
                'name'  => "User $i",
                'email' => "user$i@example.com",
            ]);
            $this->propertyService->setDynamicProperty($user, 'age', 20 + ($i % 30));
            $users[] = $user;
        }

        // Search should handle large datasets
        $results = $this->propertyService->search(SearchTestUser::class, [
            'age' => ['value' => 25, 'operator' => '>'],
        ]);

        expect($results)->not->toBeEmpty();
        expect($results->count())->toBeGreaterThan(0);
    });

    it('maintains search accuracy with complex data types', function () {
        $user1 = SearchTestUser::create(['name' => 'John', 'email' => 'john@example.com']);
        $user2 = SearchTestUser::create(['name' => 'Jane', 'email' => 'jane@example.com']);

        // Test with decimal numbers
        $this->propertyService->setDynamicProperty($user1, 'age', 25.5);
        $this->propertyService->setDynamicProperty($user2, 'age', 25.7);

        $results = $this->propertyService->search(SearchTestUser::class, [
            'age' => ['value' => 25.6, 'operator' => '>'],
        ]);

        expect($results)->not->toContain($user1->id);
        expect($results)->toContain($user2->id);
    });
});
