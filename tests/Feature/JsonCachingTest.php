<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use SolutionForest\LaravelDynamicProperties\DynamicPropertyServiceProvider;
use SolutionForest\LaravelDynamicProperties\Models\EntityProperty;
use SolutionForest\LaravelDynamicProperties\Models\Property;
use SolutionForest\LaravelDynamicProperties\Services\PropertyService;

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

    // Create test properties
    Property::create([
        'name'     => 'phone',
        'label'    => 'Phone Number',
        'type'     => 'text',
        'required' => false,
    ]);

    Property::create([
        'name'     => 'age',
        'label'    => 'Age',
        'type'     => 'number',
        'required' => false,
    ]);

    Property::create([
        'name'     => 'active',
        'label'    => 'Active Status',
        'type'     => 'boolean',
        'required' => false,
    ]);
});

function createTestUser(array $attributes = [])
{
    // Create a simple test user model
    return new class($attributes) extends \Illuminate\Database\Eloquent\Model
    {
        use \SolutionForest\LaravelDynamicProperties\Traits\HasProperties;

        protected $table = 'users';

        protected $fillable = ['name', 'email', 'dynamic_properties'];

        protected $casts = ['dynamic_properties' => 'array'];

        public function __construct(array $attributes = [])
        {
            parent::__construct($attributes + [
                'name'  => 'Test User',
                'email' => 'test@example.com',
            ]);

            // Save to database
            $this->save();
        }
    };
}

it('can sync properties to json column', function () {
    // Create a test user model that uses HasProperties
    $user = createTestUser();

    // Add dynamic_properties column to users table
    Schema::table('users', function ($table) {
        $table->json('dynamic_properties')->nullable();
    });

    $propertyService = app(PropertyService::class);

    // Set some properties
    $propertyService->setDynamicProperty($user, 'phone', '+1234567890');
    $propertyService->setDynamicProperty($user, 'age', 25);
    $propertyService->setDynamicProperty($user, 'active', true);

    // Refresh the user to get updated data
    $user->refresh();

    // Check that JSON column was updated
    expect($user->dynamic_properties)->not->toBeNull();
    expect($user->dynamic_properties['phone'])->toBe('+1234567890');
    expect($user->dynamic_properties['age'])->toEqual(25);
    expect($user->dynamic_properties['active'])->toBeTrue();
});

it('prefers json column over entity properties table', function () {
    // Create a test user model that uses HasProperties
    $user = createTestUser();

    // Add dynamic_properties column to users table
    Schema::table('users', function ($table) {
        $table->json('dynamic_properties')->nullable();
    });

    $propertyService = app(PropertyService::class);

    // Set properties normally (this will create entity_properties records and sync to JSON)
    $propertyService->setDynamicProperty($user, 'phone', '+1234567890');
    $propertyService->setDynamicProperty($user, 'age', 25);

    // Manually update the JSON column with different values
    $user->update(['dynamic_properties' => [
        'phone' => '+9876543210',
        'age'   => 30,
        'extra' => 'json_only_value',
    ]]);

    // Refresh the user
    $user->refresh();

    // The properties accessor should return JSON column values, not entity_properties values
    $properties = $user->properties;
    expect($properties['phone'])->toBe('+9876543210');
    expect($properties['age'])->toEqual(30);
    expect($properties['extra'])->toBe('json_only_value');
});

it('falls back to entity properties when json column missing', function () {
    // Create a test user model that uses HasProperties (without JSON column)
    $user = createTestUser();

    $propertyService = app(PropertyService::class);

    // Set some properties
    $propertyService->setDynamicProperty($user, 'phone', '+1234567890');
    $propertyService->setDynamicProperty($user, 'age', 25);

    // Properties should be retrieved from entity_properties table
    $properties = $user->properties;
    expect($properties['phone'])->toBe('+1234567890');
    expect($properties['age'])->toEqual(25);

    // Verify the data is actually in entity_properties table
    $entityProperties = EntityProperty::where('entity_id', $user->id)
        ->where('entity_type', get_class($user))
        ->get();

    expect($entityProperties)->toHaveCount(2);
});

it('can sync all entities of a type', function () {
    // Create multiple test users
    $user1 = createTestUser(['name' => 'User 1']);
    $user2 = createTestUser(['name' => 'User 2']);

    $propertyService = app(PropertyService::class);

    // Set properties for both users (without JSON column initially)
    $propertyService->setDynamicProperty($user1, 'phone', '+1111111111');
    $propertyService->setDynamicProperty($user1, 'age', 25);

    $propertyService->setDynamicProperty($user2, 'phone', '+2222222222');
    $propertyService->setDynamicProperty($user2, 'age', 30);

    // Now add the JSON column
    Schema::table('users', function ($table) {
        $table->json('dynamic_properties')->nullable();
    });

    // Sync all users
    $synced = $propertyService->syncAllJsonColumns(get_class($user1));
    expect($synced)->toBe(2);

    // Refresh users and check JSON columns
    $user1->refresh();
    $user2->refresh();

    expect($user1->dynamic_properties)->not->toBeNull();
    expect($user2->dynamic_properties)->not->toBeNull();

    expect($user1->dynamic_properties['phone'])->toBe('+1111111111');
    expect($user1->dynamic_properties['age'])->toEqual(25);

    expect($user2->dynamic_properties['phone'])->toBe('+2222222222');
    expect($user2->dynamic_properties['age'])->toEqual(30);
});

it('handles json column gracefully when not present', function () {
    // Create a test user model without JSON column
    $user = createTestUser();

    $propertyService = app(PropertyService::class);

    // This should not throw an error even though JSON column doesn't exist
    $propertyService->syncJsonColumn($user);

    // Setting properties should still work
    $propertyService->setDynamicProperty($user, 'phone', '+1234567890');

    expect($user->getDynamicProperty('phone'))->toBe('+1234567890');
});

it('trait methods work with json caching', function () {
    // Create a test user model that uses HasProperties
    $user = createTestUser();

    // Add dynamic_properties column to users table
    Schema::table('users', function ($table) {
        $table->json('dynamic_properties')->nullable();
    });

    // Test hasJsonPropertiesColumn method
    expect($user->hasJsonPropertiesColumn())->toBeTrue();

    // Set properties using trait methods
    $user->setDynamicProperty('phone', '+1234567890');
    $user->prop_age = 25;

    // Test syncPropertiesToJson method
    $user->syncPropertiesToJson();

    $user->refresh();

    // Verify JSON column was updated
    expect($user->dynamic_properties)->not->toBeNull();
    expect($user->dynamic_properties['phone'])->toBe('+1234567890');
    expect($user->dynamic_properties['age'])->toEqual(25);
});
