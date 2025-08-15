<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use SolutionForest\LaravelDynamicProperties\Models\Property;
use SolutionForest\LaravelDynamicProperties\Services\PropertyService;
use SolutionForest\LaravelDynamicProperties\Traits\HasProperties;

class OperatorTestUser extends Model
{
    use HasProperties;

    protected $table = 'operator_test_users';

    protected $fillable = ['name', 'email'];

    public $timestamps = false;
}

beforeEach(function () {
    // Create the table
    Schema::create('operator_test_users', function ($table) {
        $table->id();
        $table->string('name');
        $table->string('email');
        $table->json('dynamic_properties')->nullable();
    });

    // Create level property
    Property::create([
        'name'       => 'level',
        'label'      => 'Customer Level',
        'type'       => 'number',
        'validation' => ['required' => false],
    ]);

    $this->propertyService = app(PropertyService::class);
});

afterEach(function () {
    Schema::dropIfExists('operator_test_users');
});

it('correctly handles numeric comparison operators', function () {
    // Create test users with different levels
    $users = [];
    $levels = [1, 2, 3, 4, 5, 6, 7];

    foreach ($levels as $level) {
        $user = OperatorTestUser::create([
            'name'  => "User Level {$level}",
            'email' => "user{$level}@test.com",
        ]);

        $this->propertyService->setDynamicProperty($user, 'level', $level);
        $users[$level] = $user;
    }

    // Test: level > 5 should return users with level 6, 7
    $results = OperatorTestUser::whereProperty('level', '>', 5)->get();
    expect($results)->toHaveCount(2);
    expect($results->pluck('name')->toArray())->toContain('User Level 6', 'User Level 7');

    // Test: level > 4 should return users with level 5, 6, 7
    $results = OperatorTestUser::whereProperty('level', '>', 4)->get();
    expect($results)->toHaveCount(3);
    expect($results->pluck('name')->toArray())->toContain('User Level 5', 'User Level 6', 'User Level 7');

    // Test: level > 3 should return users with level 4, 5, 6, 7
    $results = OperatorTestUser::whereProperty('level', '>', 3)->get();
    expect($results)->toHaveCount(4);
    expect($results->pluck('name')->toArray())->toContain('User Level 4', 'User Level 5', 'User Level 6', 'User Level 7');

    // Test: level >= 3 should return users with level 3, 4, 5, 6, 7
    $results = OperatorTestUser::whereProperty('level', '>=', 3)->get();
    expect($results)->toHaveCount(5);
    expect($results->pluck('name')->toArray())->toContain('User Level 3', 'User Level 4', 'User Level 5', 'User Level 6', 'User Level 7');

    // Test: level < 4 should return users with level 1, 2, 3
    $results = OperatorTestUser::whereProperty('level', '<', 4)->get();
    expect($results)->toHaveCount(3);
    expect($results->pluck('name')->toArray())->toContain('User Level 1', 'User Level 2', 'User Level 3');

    // Test: level <= 4 should return users with level 1, 2, 3, 4
    $results = OperatorTestUser::whereProperty('level', '<=', 4)->get();
    expect($results)->toHaveCount(4);
    expect($results->pluck('name')->toArray())->toContain('User Level 1', 'User Level 2', 'User Level 3', 'User Level 4');

    // Test: level = 5 should return only user with level 5
    $results = OperatorTestUser::whereProperty('level', '=', 5)->get();
    expect($results)->toHaveCount(1);
    expect($results->first()->name)->toBe('User Level 5');

    // Test: default operator (=) should work the same
    $results = OperatorTestUser::whereProperty('level', 5)->get();
    expect($results)->toHaveCount(1);
    expect($results->first()->name)->toBe('User Level 5');
});

it('correctly handles count operations with operators', function () {
    // Create test users with different levels
    $levels = [1, 2, 3, 4, 5, 6, 7];

    foreach ($levels as $level) {
        $user = OperatorTestUser::create([
            'name'  => "User Level {$level}",
            'email' => "user{$level}@test.com",
        ]);

        $this->propertyService->setDynamicProperty($user, 'level', $level);
    }

    // Verify the exact issue reported by the customer
    $count5 = OperatorTestUser::whereProperty('level', '>', 5)->count();
    $count4 = OperatorTestUser::whereProperty('level', '>', 4)->count();
    $count3 = OperatorTestUser::whereProperty('level', '>', 3)->count();

    expect($count5)->toBe(2); // level 6, 7
    expect($count4)->toBe(3); // level 5, 6, 7
    expect($count3)->toBe(4); // level 4, 5, 6, 7

    // Verify the logical relationship: count3 should include count4 and count5
    expect($count3)->toBeGreaterThan($count4);
    expect($count4)->toBeGreaterThan($count5);
    expect($count3)->toBe($count4 + 1); // count4 + the level 4 user
    expect($count4)->toBe($count5 + 1); // count5 + the level 5 user
});

it('handles string vs numeric level comparison edge cases', function () {
    // Create test users with levels stored as strings vs numbers
    $userNumeric = OperatorTestUser::create([
        'name'  => 'User Numeric Level',
        'email' => 'numeric@test.com',
    ]);

    $userString = OperatorTestUser::create([
        'name'  => 'User String Level',
        'email' => 'string@test.com',
    ]);

    // Set level as number
    $this->propertyService->setDynamicProperty($userNumeric, 'level', 5);

    // Set level as string
    $this->propertyService->setDynamicProperty($userString, 'level', '5');

    // Both should be found with numeric comparison
    $results = OperatorTestUser::whereProperty('level', '>', 4)->get();
    expect($results)->toHaveCount(2);

    // Check what's actually stored in the database
    $numericProperty = \SolutionForest\LaravelDynamicProperties\Models\EntityProperty::where('entity_id', $userNumeric->id)
        ->where('property_name', 'level')
        ->first();

    $stringProperty = \SolutionForest\LaravelDynamicProperties\Models\EntityProperty::where('entity_id', $userString->id)
        ->where('property_name', 'level')
        ->first();

    // Both should be stored in number_value column
    expect($numericProperty->number_value)->toBe(5.0);
    expect($stringProperty->number_value)->toBe(5.0);
    expect($numericProperty->string_value)->toBeNull();
    expect($stringProperty->string_value)->toBeNull();
});

it('handles mixed data integrity scenarios', function () {
    // Test simple property updates to avoid constraint violations
    $user1 = OperatorTestUser::create(['name' => 'User 1', 'email' => 'user1@test.com']);
    $user2 = OperatorTestUser::create(['name' => 'User 2', 'email' => 'user2@test.com']);
    $user3 = OperatorTestUser::create(['name' => 'User 3', 'email' => 'user3@test.com']);

    // Set properties with different values
    $this->propertyService->setDynamicProperty($user1, 'level', 3);
    $this->propertyService->setDynamicProperty($user2, 'level', 4);
    $this->propertyService->setDynamicProperty($user3, 'level', 5);

    // Test that all comparisons work correctly
    $results = OperatorTestUser::whereProperty('level', '>', 3)->get();
    expect($results)->toHaveCount(2); // users 2 and 3

    $results = OperatorTestUser::whereProperty('level', '>', 4)->get();
    expect($results)->toHaveCount(1); // user 3 only

    $results = OperatorTestUser::whereProperty('level', '>', 5)->get();
    expect($results)->toHaveCount(0); // no users
});

it('verifies property definition affects comparison behavior', function () {
    // Create a text property instead of number
    Property::create([
        'name'       => 'text_level',
        'label'      => 'Text Level',
        'type'       => 'text', // This should cause string comparison
        'validation' => ['required' => false],
    ]);

    $user1 = OperatorTestUser::create(['name' => 'User 1', 'email' => 'user1@test.com']);
    $user2 = OperatorTestUser::create(['name' => 'User 2', 'email' => 'user2@test.com']);

    // Set text levels
    $this->propertyService->setDynamicProperty($user1, 'text_level', '10');
    $this->propertyService->setDynamicProperty($user2, 'text_level', '5');

    // String comparison: '5' > '4' but '10' < '4' (lexicographic comparison)
    $results = OperatorTestUser::whereProperty('text_level', '>', '4')->get();
    expect($results)->toHaveCount(1); // Only '5' > '4', because '10' < '4' lexicographically
    expect($results->first()->id)->toBe($user2->id);

    // But '10' > '1' and '5' > '1' in string comparison
    $results = OperatorTestUser::whereProperty('text_level', '>', '1')->get();
    expect($results)->toHaveCount(2); // Both '5' > '1' and '10' > '1'

    // Check storage
    $property1 = \SolutionForest\LaravelDynamicProperties\Models\EntityProperty::where('entity_id', $user1->id)
        ->where('property_name', 'text_level')
        ->first();
    $property2 = \SolutionForest\LaravelDynamicProperties\Models\EntityProperty::where('entity_id', $user2->id)
        ->where('property_name', 'text_level')
        ->first();

    expect($property1->string_value)->toBe('10');
    expect($property1->number_value)->toBeNull();
    expect($property2->string_value)->toBe('5');
    expect($property2->number_value)->toBeNull();
});
