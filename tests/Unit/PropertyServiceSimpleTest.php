<?php

use DynamicProperties\Models\Property;
use DynamicProperties\Models\EntityProperty;
use DynamicProperties\Services\PropertyService;
use Illuminate\Support\Facades\Schema;

describe('PropertyService - Comprehensive Tests', function () {
    beforeEach(function () {
        // Create users table for testing
        if (!Schema::hasTable('users')) {
            Schema::create('users', function ($table) {
                $table->id();
                $table->string('name');
                $table->string('email');
                $table->timestamps();
            });
        }

        // Create test properties
        Property::create([
            'name' => 'bio',
            'label' => 'Biography',
            'type' => 'text',
            'required' => false
        ]);

        Property::create([
            'name' => 'age',
            'label' => 'Age',
            'type' => 'number',
            'required' => false
        ]);

        Property::create([
            'name' => 'active',
            'label' => 'Active',
            'type' => 'boolean',
            'required' => false
        ]);

        // Create test user
        $this->user = new class extends \Illuminate\Database\Eloquent\Model {
            use \DynamicProperties\Traits\HasProperties;
            protected $table = 'users';
            protected $fillable = ['name', 'email', 'dynamic_properties'];
            protected $casts = ['dynamic_properties' => 'array'];
        };
        $this->user->fill(['name' => 'Test User', 'email' => 'test@example.com'])->save();
    });

    it('can set and get a text property', function () {
        $propertyService = app(PropertyService::class);
        
        $propertyService->setProperty($this->user, 'bio', 'This is my biography');
        
        $value = $propertyService->getProperty($this->user, 'bio');
        expect($value)->toBe('This is my biography');
    });

    it('can set and get a number property', function () {
        $propertyService = app(PropertyService::class);
        
        $propertyService->setProperty($this->user, 'age', 25);
        
        $value = $propertyService->getProperty($this->user, 'age');
        expect($value)->toBe(25.0);
    });

    it('can set and get a boolean property', function () {
        $propertyService = app(PropertyService::class);
        
        $propertyService->setProperty($this->user, 'active', true);
        
        $value = $propertyService->getProperty($this->user, 'active');
        expect($value)->toBeTrue();
    });

    it('can set multiple properties at once', function () {
        $propertyService = app(PropertyService::class);
        
        $propertyService->setProperties($this->user, [
            'bio' => 'My biography',
            'age' => 25,
            'active' => true
        ]);

        $properties = $propertyService->getProperties($this->user);
        
        expect($properties)->toBeArray();
        expect($properties['bio'])->toBe('My biography');
        expect($properties['age'])->toBe(25.0);
        expect($properties['active'])->toBeTrue();
    });

    it('throws exception for non-existent property', function () {
        $propertyService = app(PropertyService::class);
        
        expect(fn() => $propertyService->setProperty($this->user, 'non_existent', 'value'))
            ->toThrow(\InvalidArgumentException::class, "Property 'non_existent' does not exist");
    });

    it('can search entities by property values', function () {
        $propertyService = app(PropertyService::class);
        
        // Create another user
        $user2 = new class extends \Illuminate\Database\Eloquent\Model {
            use \DynamicProperties\Traits\HasProperties;
            protected $table = 'users';
            protected $fillable = ['name', 'email', 'dynamic_properties'];
            protected $casts = ['dynamic_properties' => 'array'];
        };
        $user2->fill(['name' => 'User 2', 'email' => 'user2@example.com'])->save();

        // Set properties
        $propertyService->setProperty($this->user, 'active', true);
        $propertyService->setProperty($user2, 'active', false);

        // Search for active users
        $results = $propertyService->search(get_class($this->user), ['active' => true]);
        
        expect($results)->toContain($this->user->id);
        expect($results)->not->toContain($user2->id);
    });

    it('syncs to JSON column when available', function () {
        $propertyService = app(PropertyService::class);
        
        // Add JSON column
        if (!Schema::hasColumn('users', 'dynamic_properties')) {
            Schema::table('users', function ($table) {
                $table->json('dynamic_properties')->nullable();
            });
        }

        $propertyService->setProperty($this->user, 'bio', 'My biography');
        
        $this->user->refresh();
        
        expect($this->user->dynamic_properties)->not->toBeNull();
        expect($this->user->dynamic_properties['bio'])->toBe('My biography');
    });

    describe('Advanced Property Operations', function () {
        it('can remove properties', function () {
            $propertyService = app(PropertyService::class);
            
            $propertyService->setProperty($this->user, 'bio', 'Test bio');
            expect($propertyService->getProperty($this->user, 'bio'))->toBe('Test bio');
            
            $propertyService->removeProperty($this->user, 'bio');
            expect($propertyService->getProperty($this->user, 'bio'))->toBeNull();
        });

        it('can get all properties for an entity', function () {
            $propertyService = app(PropertyService::class);
            
            $propertyService->setProperties($this->user, [
                'bio' => 'My biography',
                'age' => 25,
                'active' => true
            ]);

            $properties = $propertyService->getProperties($this->user);
            
            expect($properties)->toBeArray();
            expect($properties)->toHaveCount(3);
            expect($properties['bio'])->toBe('My biography');
            expect($properties['age'])->toBe(25.0);
            expect($properties['active'])->toBeTrue();
        });

        it('handles entity without properties gracefully', function () {
            $propertyService = app(PropertyService::class);
            
            $properties = $propertyService->getProperties($this->user);
            expect($properties)->toBeArray();
            expect($properties)->toBeEmpty();
            
            $value = $propertyService->getProperty($this->user, 'non_existent');
            expect($value)->toBeNull();
        });
    });

    describe('Property Creation', function () {
        it('can create new property definitions', function () {
            $propertyService = app(PropertyService::class);
            
            $property = $propertyService->createProperty([
                'name' => 'new_property',
                'label' => 'New Property',
                'type' => 'text',
                'required' => false
            ]);

            expect($property)->toBeInstanceOf(Property::class);
            expect($property->name)->toBe('new_property');
            expect($property->label)->toBe('New Property');
            expect($property->type)->toBe('text');
        });

        it('throws exception for duplicate property names', function () {
            $propertyService = app(PropertyService::class);
            
            expect(fn() => $propertyService->createProperty([
                'name' => 'bio', // Already exists
                'label' => 'Duplicate Bio',
                'type' => 'text'
            ]))->toThrow(\DynamicProperties\Exceptions\PropertyValidationException::class);
        });

        it('validates property definition before creation', function () {
            $propertyService = app(PropertyService::class);
            
            expect(fn() => $propertyService->createProperty([
                'name' => '', // Invalid name
                'label' => 'Test',
                'type' => 'invalid_type' // Invalid type
            ]))->toThrow(\DynamicProperties\Exceptions\PropertyValidationException::class);
        });
    });

    describe('Advanced Search Functionality', function () {
        beforeEach(function () {
            // Create additional test users
            $this->user2 = new class extends \Illuminate\Database\Eloquent\Model {
                use \DynamicProperties\Traits\HasProperties;
                protected $table = 'users';
                protected $fillable = ['name', 'email', 'dynamic_properties'];
                protected $casts = ['dynamic_properties' => 'array'];
            };
            $this->user2->fill(['name' => 'User 2', 'email' => 'user2@example.com'])->save();

            $this->user3 = new class extends \Illuminate\Database\Eloquent\Model {
                use \DynamicProperties\Traits\HasProperties;
                protected $table = 'users';
                protected $fillable = ['name', 'email', 'dynamic_properties'];
                protected $casts = ['dynamic_properties' => 'array'];
            };
            $this->user3->fill(['name' => 'User 3', 'email' => 'user3@example.com'])->save();
        });

        it('can search with advanced criteria using operators', function () {
            $propertyService = app(PropertyService::class);
            
            $propertyService->setProperty($this->user, 'age', 25);
            $propertyService->setProperty($this->user2, 'age', 35);
            $propertyService->setProperty($this->user3, 'age', 45);

            $results = $propertyService->advancedSearch(get_class($this->user), [
                'age' => ['value' => 30, 'operator' => '>']
            ]);

            expect($results)->not->toContain($this->user->id);
            expect($results)->toContain($this->user2->id);
            expect($results)->toContain($this->user3->id);
        });

        it('can search with OR logic', function () {
            $propertyService = app(PropertyService::class);
            
            $propertyService->setProperty($this->user, 'age', 25);
            $propertyService->setProperty($this->user2, 'age', 35);
            $propertyService->setProperty($this->user3, 'age', 45);

            $results = $propertyService->advancedSearch(get_class($this->user), [
                'age' => 25,
                'age' => 45
            ], 'OR');

            expect($results)->toContain($this->user->id);
            expect($results)->toContain($this->user3->id);
        });

        it('can search number ranges', function () {
            $propertyService = app(PropertyService::class);
            
            $propertyService->setProperty($this->user, 'age', 25);
            $propertyService->setProperty($this->user2, 'age', 35);
            $propertyService->setProperty($this->user3, 'age', 45);

            $results = $propertyService->searchNumberRange(get_class($this->user), 'age', 30, 40);

            expect($results)->not->toContain($this->user->id);
            expect($results)->toContain($this->user2->id);
            expect($results)->not->toContain($this->user3->id);
        });

        it('can search text with partial matching', function () {
            $propertyService = app(PropertyService::class);
            
            $propertyService->setProperty($this->user, 'bio', 'Software developer from New York');
            $propertyService->setProperty($this->user2, 'bio', 'Designer from Los Angeles');
            $propertyService->setProperty($this->user3, 'bio', 'Manager from New York');

            $results = $propertyService->searchText(get_class($this->user), 'bio', 'New York');

            expect($results)->toContain($this->user->id);
            expect($results)->not->toContain($this->user2->id);
            expect($results)->toContain($this->user3->id);
        });

        it('can search boolean values', function () {
            $propertyService = app(PropertyService::class);
            
            $propertyService->setProperty($this->user, 'active', true);
            $propertyService->setProperty($this->user2, 'active', false);
            $propertyService->setProperty($this->user3, 'active', true);

            $results = $propertyService->searchBoolean(get_class($this->user), 'active', true);

            expect($results)->toContain($this->user->id);
            expect($results)->not->toContain($this->user2->id);
            expect($results)->toContain($this->user3->id);
        });
    });

    describe('JSON Column Synchronization', function () {
        it('can sync all entities of a type', function () {
            $propertyService = app(PropertyService::class);
            
            // Set properties for multiple users (without JSON column initially)
            $propertyService->setProperty($this->user, 'bio', 'User 1 bio');
            
            $user2 = new class extends \Illuminate\Database\Eloquent\Model {
                use \DynamicProperties\Traits\HasProperties;
                protected $table = 'users';
                protected $fillable = ['name', 'email', 'dynamic_properties'];
                protected $casts = ['dynamic_properties' => 'array'];
            };
            $user2->fill(['name' => 'User 2', 'email' => 'user2@example.com'])->save();
            $propertyService->setProperty($user2, 'bio', 'User 2 bio');

            // Add JSON column
            if (!Schema::hasColumn('users', 'dynamic_properties')) {
                Schema::table('users', function ($table) {
                    $table->json('dynamic_properties')->nullable();
                });
            }

            // Sync all users
            $synced = $propertyService->syncAllJsonColumns(get_class($this->user));
            expect($synced)->toBe(2);

            // Verify sync worked
            $this->user->refresh();
            $user2->refresh();
            
            expect($this->user->dynamic_properties['bio'])->toBe('User 1 bio');
            expect($user2->dynamic_properties['bio'])->toBe('User 2 bio');
        });
    });

    describe('Error Handling', function () {
        it('throws exception when setting property on unsaved entity', function () {
            $propertyService = app(PropertyService::class);
            
            $unsavedUser = new class extends \Illuminate\Database\Eloquent\Model {
                use \DynamicProperties\Traits\HasProperties;
                protected $table = 'users';
                protected $fillable = ['name', 'email'];
            };
            $unsavedUser->fill(['name' => 'Unsaved', 'email' => 'unsaved@example.com']);
            // Don't save the user

            expect(fn() => $propertyService->setProperty($unsavedUser, 'bio', 'Test'))
                ->toThrow(\DynamicProperties\Exceptions\PropertyOperationException::class);
        });

        it('handles validation errors in batch property setting', function () {
            $propertyService = app(PropertyService::class);
            
            // Create a required property
            Property::create([
                'name' => 'required_field',
                'label' => 'Required Field',
                'type' => 'text',
                'required' => true
            ]);

            expect(fn() => $propertyService->setProperties($this->user, [
                'bio' => 'Valid bio',
                'required_field' => null, // Invalid - required
                'non_existent' => 'value' // Invalid - doesn't exist
            ]))->toThrow(\DynamicProperties\Exceptions\PropertyValidationException::class);
        });
    });
});