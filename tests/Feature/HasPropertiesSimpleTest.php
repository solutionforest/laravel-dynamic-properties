<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use SolutionForest\LaravelDynamicProperties\Models\EntityProperty;
use SolutionForest\LaravelDynamicProperties\Models\Property;
use SolutionForest\LaravelDynamicProperties\Traits\HasProperties;

// Create a test model that uses the HasProperties trait
class SimpleTestUser extends Model
{
    use HasProperties;

    protected $table = 'users';

    protected $fillable = ['name', 'email', 'dynamic_properties'];

    protected $casts = ['dynamic_properties' => 'array'];
}

describe('HasProperties Trait - Comprehensive Integration Tests', function () {
    beforeEach(function () {
        // Create users table for testing
        if (! Schema::hasTable('users')) {
            Schema::create('users', function ($table) {
                $table->id();
                $table->string('name');
                $table->string('email');
                $table->timestamps();
            });
        }

        // Create test properties with unique names for this test suite
        Property::firstOrCreate(['name' => 'simple_bio'], [
            'label'    => 'Biography',
            'type'     => 'text',
            'required' => false,
        ]);

        Property::firstOrCreate(['name' => 'simple_age'], [
            'label'    => 'Age',
            'type'     => 'number',
            'required' => false,
        ]);

        Property::firstOrCreate(['name' => 'simple_active'], [
            'label'    => 'Active',
            'type'     => 'boolean',
            'required' => false,
        ]);

        // Create test user
        $this->user = SimpleTestUser::create([
            'name'  => 'Test User',
            'email' => 'test@example.com',
        ]);
    });

    it('can set and get properties using trait methods', function () {
        $this->user->setDynamicProperty('simple_bio', 'My biography');
        $this->user->setDynamicProperty('simple_age', 25);
        $this->user->setDynamicProperty('simple_active', true);

        expect($this->user->getDynamicProperty('simple_bio'))->toBe('My biography');
        expect($this->user->getDynamicProperty('simple_age'))->toBe(25.0);
        expect($this->user->getDynamicProperty('simple_active'))->toBeTrue();
    });

    it('can use magic methods with prop_ prefix', function () {
        $this->user->prop_simple_bio = 'Magic biography';
        $this->user->prop_simple_age = 30;
        $this->user->prop_simple_active = true;

        expect($this->user->prop_simple_bio)->toBe('Magic biography');
        expect($this->user->prop_simple_age)->toBe(30.0);
        expect($this->user->prop_simple_active)->toBeTrue();
    });

    it('returns all properties as array', function () {
        $this->user->setProperties([
            'simple_bio'    => 'My biography',
            'simple_age'    => 25,
            'simple_active' => true,
        ]);

        $properties = $this->user->properties;

        expect($properties)->toBeArray();
        expect($properties)->toHaveKey('simple_bio');
        expect($properties)->toHaveKey('simple_age');
        expect($properties)->toHaveKey('simple_active');
        expect($properties['simple_bio'])->toBe('My biography');
        expect($properties['simple_age'])->toBe(25.0);
        expect($properties['simple_active'])->toBeTrue();
    });

    it('can filter using whereProperty scope', function () {
        // Create another user
        $user2 = SimpleTestUser::create([
            'name'  => 'User 2',
            'email' => 'user2@example.com',
        ]);

        // Set properties
        $this->user->setDynamicProperty('simple_active', true);
        $user2->setDynamicProperty('simple_active', false);

        // Test scope
        $activeUsers = SimpleTestUser::whereProperty('simple_active', true)->get();

        expect($activeUsers)->toHaveCount(1);
        expect($activeUsers->first()->id)->toBe($this->user->id);
    });

    it('can filter using whereProperties scope', function () {
        // Create another user
        $user2 = SimpleTestUser::create([
            'name'  => 'User 2',
            'email' => 'user2@example.com',
        ]);

        // Set properties
        $this->user->setProperties([
            'simple_age'    => 25,
            'simple_active' => true,
        ]);

        $user2->setProperties([
            'simple_age'    => 30,
            'simple_active' => true,
        ]);

        // Test scope with multiple properties
        $youngActiveUsers = SimpleTestUser::whereProperties([
            'simple_age'    => 25,
            'simple_active' => true,
        ])->get();

        expect($youngActiveUsers)->toHaveCount(1);
        expect($youngActiveUsers->first()->id)->toBe($this->user->id);
    });

    it('can remove properties', function () {
        $this->user->setDynamicProperty('simple_bio', 'My biography');
        expect($this->user->getDynamicProperty('simple_bio'))->toBe('My biography');

        $this->user->removeProperty('simple_bio');
        expect($this->user->getDynamicProperty('simple_bio'))->toBeNull();
    });

    it('works with JSON column when available', function () {
        // Add JSON column
        if (! Schema::hasColumn('users', 'dynamic_properties')) {
            Schema::table('users', function ($table) {
                $table->json('dynamic_properties')->nullable();
            });
        }

        expect($this->user->hasJsonPropertiesColumn())->toBeTrue();

        $this->user->setDynamicProperty('simple_bio', 'My biography');
        $this->user->setDynamicProperty('simple_age', 25);

        $this->user->refresh();

        expect($this->user->dynamic_properties)->not->toBeNull();
        expect($this->user->dynamic_properties['simple_bio'])->toBe('My biography');
        expect($this->user->dynamic_properties['simple_age'])->toBe(25); // JSON stores as integer
    });

    it('has polymorphic relationship to entity properties', function () {
        $this->user->setDynamicProperty('simple_bio', 'Test biography');

        $entityProperties = $this->user->entityProperties;

        expect($entityProperties)->toHaveCount(1);
        expect($entityProperties->first()->string_value)->toBe('Test biography');
    });

    describe('Advanced Query Scopes', function () {
        beforeEach(function () {
            // Ensure users table exists
            Schema::dropIfExists('users');
            Schema::create('users', function ($table) {
                $table->id();
                $table->string('name');
                $table->string('email');
                $table->timestamps();
            });

            // Create test properties
            Property::firstOrCreate(['name' => 'bio'], [
                'label'    => 'Biography',
                'type'     => 'text',
                'required' => false,
            ]);

            Property::firstOrCreate(['name' => 'age'], [
                'label'    => 'Age',
                'type'     => 'number',
                'required' => false,
            ]);

            Property::firstOrCreate(['name' => 'active'], [
                'label'    => 'Active',
                'type'     => 'boolean',
                'required' => false,
            ]);

            // Create date property for testing
            Property::firstOrCreate(['name' => 'birth_date'], [
                'label'    => 'Birth Date',
                'type'     => 'date',
                'required' => false,
            ]);

            // Create test users
            $this->user = SimpleTestUser::create([
                'name'  => 'Test User',
                'email' => 'test@example.com',
            ]);

            $this->user2 = SimpleTestUser::create([
                'name'  => 'User 2',
                'email' => 'user2@example.com',
            ]);

            $this->user3 = SimpleTestUser::create([
                'name'  => 'User 3',
                'email' => 'user3@example.com',
            ]);
        });

        it('can use wherePropertyText scope for partial text matching', function () {
            $this->user->setDynamicProperty('bio', 'Software developer from New York');
            $this->user2->setDynamicProperty('bio', 'Designer from Los Angeles');
            $this->user3->setDynamicProperty('bio', 'Manager from New York City');

            $results = SimpleTestUser::wherePropertyText('bio', 'New York')->get();

            expect($results)->toHaveCount(2);
            expect($results->pluck('id'))->toContain($this->user->id);
            expect($results->pluck('id'))->toContain($this->user3->id);
        });

        it('can use wherePropertyBetween scope for number ranges', function () {
            $this->user->setDynamicProperty('age', 25);
            $this->user2->setDynamicProperty('age', 35);
            $this->user3->setDynamicProperty('age', 45);

            $results = SimpleTestUser::wherePropertyBetween('age', 30, 40)->get();

            expect($results)->toHaveCount(1);
            expect($results->first()->id)->toBe($this->user2->id);
        });

        it('can use wherePropertyBetween scope for date ranges', function () {
            $this->user->setDynamicProperty('birth_date', '1990-01-01');
            $this->user2->setDynamicProperty('birth_date', '1995-06-15');
            $this->user3->setDynamicProperty('birth_date', '2000-12-31');

            $results = SimpleTestUser::wherePropertyBetween('birth_date', '1994-01-01', '1999-12-31')->get();

            expect($results)->toHaveCount(1);
            expect($results->first()->id)->toBe($this->user2->id);
        });

        it('can use wherePropertyIn scope for multiple values', function () {
            $this->user->setDynamicProperty('bio', 'Developer');
            $this->user2->setDynamicProperty('bio', 'Designer');
            $this->user3->setDynamicProperty('bio', 'Manager');

            $results = SimpleTestUser::wherePropertyIn('bio', ['Developer', 'Manager'])->get();

            expect($results)->toHaveCount(2);
            expect($results->pluck('id'))->toContain($this->user->id);
            expect($results->pluck('id'))->toContain($this->user3->id);
        });

        it('can use hasAnyProperty scope', function () {
            $this->user->setDynamicProperty('bio', 'Test bio');
            $this->user->setDynamicProperty('age', 25);

            $this->user2->setDynamicProperty('active', true);

            $this->user3->setDynamicProperty('bio', 'Another bio');

            $results = SimpleTestUser::hasAnyProperty(['bio', 'age'])->get();

            expect($results)->toHaveCount(2);
            expect($results->pluck('id'))->toContain($this->user->id);
            expect($results->pluck('id'))->toContain($this->user3->id);
        });

        it('can use hasAllProperties scope', function () {
            $this->user->setDynamicProperty('bio', 'Test bio');
            $this->user->setDynamicProperty('age', 25);
            $this->user->setDynamicProperty('active', true);

            $this->user2->setDynamicProperty('bio', 'Another bio');
            $this->user2->setDynamicProperty('age', 30);
            // Missing 'active' property

            $this->user3->setDynamicProperty('bio', 'Third bio');
            $this->user3->setDynamicProperty('active', false);
            // Missing 'age' property

            $results = SimpleTestUser::hasAllProperties(['bio', 'age', 'active'])->get();

            expect($results)->toHaveCount(1);
            expect($results->first()->id)->toBe($this->user->id);
        });

        it('can use orderByProperty scope', function () {
            $this->user->setDynamicProperty('age', 35);
            $this->user2->setDynamicProperty('age', 25);
            $this->user3->setDynamicProperty('age', 45);

            $results = SimpleTestUser::orderByProperty('age', 'asc')->get();

            expect($results->first()->id)->toBe($this->user2->id); // age 25
            expect($results->last()->id)->toBe($this->user3->id);  // age 45
        });
    });

    describe('Magic Methods Edge Cases', function () {
        beforeEach(function () {
            // Ensure users table exists
            Schema::dropIfExists('users');
            Schema::create('users', function ($table) {
                $table->id();
                $table->string('name');
                $table->string('email');
                $table->timestamps();
            });

            // Create test properties
            Property::create([
                'name'     => 'bio',
                'label'    => 'Biography',
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
                'label'    => 'Active',
                'type'     => 'boolean',
                'required' => false,
            ]);

            $this->user = SimpleTestUser::create([
                'name'  => 'Test User',
                'email' => 'test@example.com',
            ]);
        });

        it('handles magic isset correctly', function () {
            $this->user->setDynamicProperty('bio', 'Test bio');

            expect(isset($this->user->prop_bio))->toBeTrue();
            expect(isset($this->user->prop_non_existent))->toBeFalse();
        });

        it('handles magic unset correctly', function () {
            $this->user->setDynamicProperty('bio', 'Test bio');
            expect($this->user->getDynamicProperty('bio'))->toBe('Test bio');

            unset($this->user->prop_bio);
            expect($this->user->getDynamicProperty('bio'))->toBeNull();
        });

        it('throws appropriate exceptions for magic setter with invalid properties', function () {
            expect(fn () => $this->user->prop_non_existent = 'value')
                ->toThrow(\InvalidArgumentException::class);
        });

        it('falls back to parent magic methods for non-property attributes', function () {
            // Test that normal model attributes still work
            $this->user->name = 'Updated Name';
            expect($this->user->name)->toBe('Updated Name');

            expect(isset($this->user->name))->toBeTrue();
            expect(isset($this->user->non_existent_attribute))->toBeFalse();
        });
    });

    describe('Performance and Caching', function () {
        beforeEach(function () {
            // Ensure users table exists
            Schema::dropIfExists('users');
            Schema::create('users', function ($table) {
                $table->id();
                $table->string('name');
                $table->string('email');
                $table->timestamps();
            });

            // Create test properties
            Property::create([
                'name'     => 'bio',
                'label'    => 'Biography',
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
                'label'    => 'Active',
                'type'     => 'boolean',
                'required' => false,
            ]);

            $this->user = SimpleTestUser::create([
                'name'  => 'Test User',
                'email' => 'test@example.com',
            ]);
        });

        it('prefers JSON column over entity properties queries', function () {
            // Add JSON column
            if (! Schema::hasColumn('users', 'dynamic_properties')) {
                Schema::table('users', function ($table) {
                    $table->json('dynamic_properties')->nullable();
                });
            }

            // Set properties (this will create entity_properties records and sync to JSON)
            $this->user->setProperties([
                'bio'    => 'Original bio',
                'age'    => 25,
                'active' => true,
            ]);

            // Manually update JSON column with different values
            $this->user->update(['dynamic_properties' => [
                'bio'    => 'JSON bio',
                'age'    => 30,
                'active' => false,
                'extra'  => 'JSON only',
            ]]);

            $this->user->refresh();

            // Should return JSON values, not entity_properties values
            expect($this->user->properties['bio'])->toBe('JSON bio');
            expect($this->user->properties['age'])->toBe(30);
            expect($this->user->properties['active'])->toBeFalse();
            expect($this->user->properties['extra'])->toBe('JSON only');
        });

        it('can manually sync properties to JSON', function () {
            // Add JSON column
            if (! Schema::hasColumn('users', 'dynamic_properties')) {
                Schema::table('users', function ($table) {
                    $table->json('dynamic_properties')->nullable();
                });
            }

            $this->user->setDynamicProperty('bio', 'Test bio');
            $this->user->setDynamicProperty('age', 25);

            // Clear JSON column
            $this->user->update(['dynamic_properties' => null]);
            $this->user->refresh();

            // Manually sync
            $this->user->syncPropertiesToJson();
            $this->user->refresh();

            expect($this->user->dynamic_properties)->not->toBeNull();
            expect($this->user->dynamic_properties['bio'])->toBe('Test bio');
            expect($this->user->dynamic_properties['age'])->toBe(25);
        });

        it('can check for JSON properties column', function () {
            // Initially no JSON column
            expect($this->user->hasJsonPropertiesColumn())->toBeFalse();

            // Add JSON column
            if (! Schema::hasColumn('users', 'dynamic_properties')) {
                Schema::table('users', function ($table) {
                    $table->json('dynamic_properties')->nullable();
                });
            }

            // Create new instance to avoid cached schema
            $freshUser = SimpleTestUser::find($this->user->id);
            expect($freshUser->hasJsonPropertiesColumn())->toBeTrue();
        });
    });

    describe('Complex Integration Scenarios', function () {
        beforeEach(function () {
            // Ensure users table exists
            Schema::dropIfExists('users');
            Schema::create('users', function ($table) {
                $table->id();
                $table->string('name');
                $table->string('email');
                $table->timestamps();
            });

            // Create test properties
            Property::firstOrCreate(['name' => 'bio'], [
                'label'    => 'Biography',
                'type'     => 'text',
                'required' => false,
            ]);

            Property::firstOrCreate(['name' => 'age'], [
                'label'    => 'Age',
                'type'     => 'number',
                'required' => false,
            ]);

            Property::firstOrCreate(['name' => 'active'], [
                'label'    => 'Active',
                'type'     => 'boolean',
                'required' => false,
            ]);

            // Create date property for testing
            Property::firstOrCreate(['name' => 'join_date'], [
                'label'    => 'Join Date',
                'type'     => 'date',
                'required' => false,
            ]);

            $this->user = SimpleTestUser::create([
                'name'  => 'Test User',
                'email' => 'test@example.com',
            ]);

            $this->user2 = SimpleTestUser::create([
                'name'  => 'User 2',
                'email' => 'user2@example.com',
            ]);

            $this->user3 = SimpleTestUser::create([
                'name'  => 'User 3',
                'email' => 'user3@example.com',
            ]);
        });

        it('handles mixed property types in complex queries', function () {
            // Create date property
            Property::firstOrCreate(['name' => 'join_date'], [
                'label'    => 'Join Date',
                'type'     => 'date',
                'required' => false,
            ]);

            // Set mixed properties
            $this->user->setProperties([
                'bio'       => 'Senior Developer',
                'age'       => 30,
                'active'    => true,
                'join_date' => '2020-01-15',
            ]);

            $this->user2->setProperties([
                'bio'       => 'Junior Developer',
                'age'       => 25,
                'active'    => true,
                'join_date' => '2022-06-01',
            ]);

            $this->user3->setProperties([
                'bio'       => 'Senior Manager',
                'age'       => 40,
                'active'    => false,
                'join_date' => '2018-03-10',
            ]);

            // Debug: Check if properties were set correctly
            expect($this->user->getDynamicProperty('active'))->toBeTrue();
            expect($this->user->getDynamicProperty('join_date')->format('Y-m-d'))->toBe('2020-01-15');
            expect($this->user->getDynamicProperty('bio'))->toContain('Developer');

            expect($this->user2->getDynamicProperty('active'))->toBeTrue();
            expect($this->user2->getDynamicProperty('join_date')->format('Y-m-d'))->toBe('2022-06-01');
            expect($this->user2->getDynamicProperty('bio'))->toContain('Developer');

            // Test individual scopes first
            $activeUsers = SimpleTestUser::whereProperty('active', true)->get();
            expect($activeUsers)->toHaveCount(2); // user1 and user2 are active

            $recentUsers = SimpleTestUser::whereProperty('join_date', '>', '2020-01-01')->get();
            expect($recentUsers)->toHaveCount(2); // user1 and user2 joined after 2020

            $developers = SimpleTestUser::wherePropertyText('bio', 'Developer')->get();
            expect($developers)->toHaveCount(2); // user1 and user2 are developers

            // Complex query: Active users who joined after 2020 and are developers
            $results = SimpleTestUser::whereProperty('active', true)
                ->whereProperty('join_date', '>', '2020-01-01')
                ->wherePropertyText('bio', 'Developer')
                ->get();

            expect($results)->toHaveCount(2);
            expect($results->pluck('id'))->toContain($this->user->id);
            expect($results->pluck('id'))->toContain($this->user2->id);
        });

        it('maintains data consistency across property operations', function () {
            // Set initial properties
            $this->user->setProperties([
                'bio'    => 'Initial bio',
                'age'    => 25,
                'active' => true,
            ]);

            // Update some properties
            $this->user->setDynamicProperty('bio', 'Updated bio');
            $this->user->prop_age = 26;

            // Remove a property
            $this->user->removeProperty('active');

            // Verify final state
            $properties = $this->user->properties;
            expect($properties['bio'])->toBe('Updated bio');
            expect($properties['age'])->toBe(26.0);
            expect($properties)->not->toHaveKey('active');

            // Verify database consistency
            $entityProperties = EntityProperty::forEntity($this->user)->get();
            expect($entityProperties)->toHaveCount(2);
            expect($entityProperties->pluck('property_name')->toArray())->toEqual(['bio', 'age']);
        });
    });
});
