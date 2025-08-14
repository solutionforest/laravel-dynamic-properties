<?php

use DynamicProperties\Models\Property;
use DynamicProperties\Models\EntityProperty;
use Illuminate\Support\Facades\Schema;

describe('EntityProperty Model - Comprehensive Tests', function () {
    beforeEach(function () {
        // Create users table for testing
        Schema::create('users', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->timestamps();
        });

        // Create test properties
        $this->textProperty = Property::create([
            'name' => 'bio',
            'label' => 'Biography',
            'type' => 'text',
            'required' => false
        ]);

        $this->numberProperty = Property::create([
            'name' => 'age',
            'label' => 'Age',
            'type' => 'number',
            'required' => false
        ]);

        $this->booleanProperty = Property::create([
            'name' => 'active',
            'label' => 'Active',
            'type' => 'boolean',
            'required' => false
        ]);

        $this->dateProperty = Property::create([
            'name' => 'birth_date',
            'label' => 'Birth Date',
            'type' => 'date',
            'required' => false
        ]);

        // Create a test user
        $this->user = new class extends \Illuminate\Database\Eloquent\Model {
            protected $table = 'users';
            protected $fillable = ['name', 'email'];
        };
        $this->user->fill(['name' => 'Test User', 'email' => 'test@example.com'])->save();
    });

    it('can create entity property with string value', function () {
        $entityProperty = EntityProperty::create([
            'entity_id' => $this->user->id,
            'entity_type' => get_class($this->user),
            'property_id' => $this->textProperty->id,
            'property_name' => $this->textProperty->name,
            'string_value' => 'This is a biography'
        ]);

        expect($entityProperty)->toBeInstanceOf(EntityProperty::class)
            ->and($entityProperty->entity_id)->toBe($this->user->id)
            ->and($entityProperty->entity_type)->toBe(get_class($this->user))
            ->and($entityProperty->property_id)->toBe($this->textProperty->id)
            ->and($entityProperty->property_name)->toBe('bio')
            ->and($entityProperty->string_value)->toBe('This is a biography');
    });

    it('can create entity property with number value', function () {
        $entityProperty = EntityProperty::create([
            'entity_id' => $this->user->id,
            'entity_type' => get_class($this->user),
            'property_id' => $this->numberProperty->id,
            'property_name' => $this->numberProperty->name,
            'number_value' => 25.5
        ]);

        expect($entityProperty->number_value)->toBe(25.5);
    });

    it('can create entity property with boolean value', function () {
        $entityProperty = EntityProperty::create([
            'entity_id' => $this->user->id,
            'entity_type' => get_class($this->user),
            'property_id' => $this->booleanProperty->id,
            'property_name' => $this->booleanProperty->name,
            'boolean_value' => true
        ]);

        expect($entityProperty->boolean_value)->toBeTrue();
    });

    it('can create entity property with date value', function () {
        $date = '1990-05-15';
        $entityProperty = EntityProperty::create([
            'entity_id' => $this->user->id,
            'entity_type' => get_class($this->user),
            'property_id' => $this->dateProperty->id,
            'property_name' => $this->dateProperty->name,
            'date_value' => $date
        ]);

        expect($entityProperty->date_value)->toBeInstanceOf(\Carbon\Carbon::class);
        expect($entityProperty->date_value->format('Y-m-d'))->toBe($date);
    });

    it('has proper casts for different value types', function () {
        $entityProperty = new EntityProperty([
            'number_value' => '25.75',
            'boolean_value' => '1',
            'date_value' => '1990-05-15'
        ]);

        expect($entityProperty->number_value)->toBe(25.75);
        expect($entityProperty->boolean_value)->toBeTrue();
        expect($entityProperty->date_value)->toBeInstanceOf(\Carbon\Carbon::class);
    });

    it('returns correct value through value accessor', function () {
        // Test string value
        $stringEntity = EntityProperty::create([
            'entity_id' => $this->user->id,
            'entity_type' => get_class($this->user),
            'property_id' => $this->textProperty->id,
            'property_name' => $this->textProperty->name,
            'string_value' => 'Test string'
        ]);
        expect($stringEntity->value)->toBe('Test string');

        // Test number value
        $numberEntity = EntityProperty::create([
            'entity_id' => $this->user->id,
            'entity_type' => get_class($this->user),
            'property_id' => $this->numberProperty->id,
            'property_name' => $this->numberProperty->name,
            'number_value' => 42.5
        ]);
        expect($numberEntity->value)->toBe(42.5);

        // Test boolean value
        $booleanEntity = EntityProperty::create([
            'entity_id' => $this->user->id,
            'entity_type' => get_class($this->user),
            'property_id' => $this->booleanProperty->id,
            'property_name' => $this->booleanProperty->name,
            'boolean_value' => true
        ]);
        expect($booleanEntity->value)->toBeTrue();

        // Test date value
        $dateEntity = EntityProperty::create([
            'entity_id' => $this->user->id,
            'entity_type' => get_class($this->user),
            'property_id' => $this->dateProperty->id,
            'property_name' => $this->dateProperty->name,
            'date_value' => '1990-05-15'
        ]);
        expect($dateEntity->value)->toBeInstanceOf(\Carbon\Carbon::class);
    });

    it('has relationship to property model', function () {
        $entityProperty = EntityProperty::create([
            'entity_id' => $this->user->id,
            'entity_type' => get_class($this->user),
            'property_id' => $this->textProperty->id,
            'property_name' => $this->textProperty->name,
            'string_value' => 'Test value'
        ]);

        $property = $entityProperty->property;
        
        expect($property)->toBeInstanceOf(Property::class);
        expect($property->id)->toBe($this->textProperty->id);
        expect($property->name)->toBe('bio');
    });

    it('has polymorphic relationship to entity', function () {
        $entityProperty = EntityProperty::create([
            'entity_id' => $this->user->id,
            'entity_type' => get_class($this->user),
            'property_id' => $this->textProperty->id,
            'property_name' => $this->textProperty->name,
            'string_value' => 'Test value'
        ]);

        $entity = $entityProperty->entity;
        
        expect($entity)->toBeInstanceOf(get_class($this->user));
        expect($entity->id)->toBe($this->user->id);
    });

    it('enforces unique constraint on entity-property combination', function () {
        // Create first entity property
        EntityProperty::create([
            'entity_id' => $this->user->id,
            'entity_type' => get_class($this->user),
            'property_id' => $this->textProperty->id,
            'property_name' => $this->textProperty->name,
            'string_value' => 'First value'
        ]);

        // Attempting to create another with same entity and property should fail
        expect(fn() => EntityProperty::create([
            'entity_id' => $this->user->id,
            'entity_type' => get_class($this->user),
            'property_id' => $this->textProperty->id,
            'property_name' => $this->textProperty->name,
            'string_value' => 'Second value'
        ]))->toThrow(\Illuminate\Database\QueryException::class);
    });

    it('can update existing entity property', function () {
        $entityProperty = EntityProperty::create([
            'entity_id' => $this->user->id,
            'entity_type' => get_class($this->user),
            'property_id' => $this->textProperty->id,
            'property_name' => $this->textProperty->name,
            'string_value' => 'Original value'
        ]);

        $entityProperty->update(['string_value' => 'Updated value']);
        
        expect($entityProperty->fresh()->string_value)->toBe('Updated value');
    });

    it('can store multiple value types but only one should be used', function () {
        $entityProperty = EntityProperty::create([
            'entity_id' => $this->user->id,
            'entity_type' => get_class($this->user),
            'property_id' => $this->textProperty->id,
            'property_name' => $this->textProperty->name,
            'string_value' => 'Text value',
            'number_value' => 123, // This will be stored but not used by value accessor
            'boolean_value' => true
        ]);

        // The value accessor should return the first non-null value (string_value)
        expect($entityProperty->string_value)->toBe('Text value');
        expect($entityProperty->value)->toBe('Text value'); // Should return string_value since it's first
    });

    it('can scope by entity', function () {
        // Create another user
        $user2 = new class extends \Illuminate\Database\Eloquent\Model {
            protected $table = 'users';
            protected $fillable = ['name', 'email'];
        };
        $user2->fill(['name' => 'User 2', 'email' => 'user2@example.com'])->save();

        // Create properties for both users
        EntityProperty::create([
            'entity_id' => $this->user->id,
            'entity_type' => get_class($this->user),
            'property_id' => $this->textProperty->id,
            'property_name' => $this->textProperty->name,
            'string_value' => 'User 1 bio'
        ]);

        EntityProperty::create([
            'entity_id' => $user2->id,
            'entity_type' => get_class($user2),
            'property_id' => $this->textProperty->id,
            'property_name' => $this->textProperty->name,
            'string_value' => 'User 2 bio'
        ]);

        // Test scoping by entity
        $user1Properties = EntityProperty::forEntity($this->user)->get();
        $user2Properties = EntityProperty::forEntity($user2)->get();

        expect($user1Properties)->toHaveCount(1);
        expect($user2Properties)->toHaveCount(1);
        expect($user1Properties->first()->string_value)->toBe('User 1 bio');
        expect($user2Properties->first()->string_value)->toBe('User 2 bio');
    });

    describe('Value Column Management', function () {
        it('can set value using setValue method', function () {
            $entityProperty = new EntityProperty([
                'entity_id' => $this->user->id,
                'entity_type' => get_class($this->user),
                'property_id' => $this->textProperty->id,
                'property_name' => $this->textProperty->name,
            ]);

            $entityProperty->setValue('Test value', 'text');
            
            expect($entityProperty->string_value)->toBe('Test value');
            expect($entityProperty->number_value)->toBeNull();
            expect($entityProperty->date_value)->toBeNull();
            expect($entityProperty->boolean_value)->toBeNull();
        });

        it('clears other columns when setting value', function () {
            $entityProperty = EntityProperty::create([
                'entity_id' => $this->user->id,
                'entity_type' => get_class($this->user),
                'property_id' => $this->textProperty->id,
                'property_name' => $this->textProperty->name,
                'string_value' => 'Original text',
                'number_value' => 123,
                'boolean_value' => true
            ]);

            $entityProperty->setValue('New text', 'text');
            
            expect($entityProperty->string_value)->toBe('New text');
            expect($entityProperty->number_value)->toBeNull();
            expect($entityProperty->boolean_value)->toBeNull();
        });

        it('returns correct value column for each type', function () {
            expect(EntityProperty::getValueColumnForType('text'))->toBe('string_value');
            expect(EntityProperty::getValueColumnForType('select'))->toBe('string_value');
            expect(EntityProperty::getValueColumnForType('number'))->toBe('number_value');
            expect(EntityProperty::getValueColumnForType('date'))->toBe('date_value');
            expect(EntityProperty::getValueColumnForType('boolean'))->toBe('boolean_value');
            expect(EntityProperty::getValueColumnForType('unknown'))->toBe('string_value');
        });

        it('returns correct value columns array for each type', function () {
            $textColumns = EntityProperty::getValueColumnsForType('text', 'test');
            expect($textColumns['string_value'])->toBe('test');
            expect($textColumns['number_value'])->toBeNull();
            expect($textColumns['date_value'])->toBeNull();
            expect($textColumns['boolean_value'])->toBeNull();

            $numberColumns = EntityProperty::getValueColumnsForType('number', 42.5);
            expect($numberColumns['string_value'])->toBeNull();
            expect($numberColumns['number_value'])->toBe(42.5);
            expect($numberColumns['date_value'])->toBeNull();
            expect($numberColumns['boolean_value'])->toBeNull();
        });
    });

    describe('Typed Value Accessor', function () {
        it('returns typed value based on property definition', function () {
            // Create entity property with property relationship
            $entityProperty = EntityProperty::create([
                'entity_id' => $this->user->id,
                'entity_type' => get_class($this->user),
                'property_id' => $this->numberProperty->id,
                'property_name' => $this->numberProperty->name,
                'number_value' => 25.5
            ]);

            // Load the relationship
            $entityProperty->load('property');
            
            expect($entityProperty->typed_value)->toBe(25.5);
        });

        it('falls back to raw value when property relationship missing', function () {
            $entityProperty = EntityProperty::create([
                'entity_id' => $this->user->id,
                'entity_type' => get_class($this->user),
                'property_id' => 999, // Non-existent property
                'property_name' => 'unknown',
                'string_value' => 'test'
            ]);

            expect($entityProperty->typed_value)->toBe('test');
        });
    });

    describe('Complex Scenarios', function () {
        it('handles multiple properties for same entity', function () {
            EntityProperty::create([
                'entity_id' => $this->user->id,
                'entity_type' => get_class($this->user),
                'property_id' => $this->textProperty->id,
                'property_name' => $this->textProperty->name,
                'string_value' => 'Bio text'
            ]);

            EntityProperty::create([
                'entity_id' => $this->user->id,
                'entity_type' => get_class($this->user),
                'property_id' => $this->numberProperty->id,
                'property_name' => $this->numberProperty->name,
                'number_value' => 30
            ]);

            EntityProperty::create([
                'entity_id' => $this->user->id,
                'entity_type' => get_class($this->user),
                'property_id' => $this->booleanProperty->id,
                'property_name' => $this->booleanProperty->name,
                'boolean_value' => true
            ]);

            $properties = EntityProperty::forEntity($this->user)->get();
            expect($properties)->toHaveCount(3);
            
            $propertyNames = $properties->pluck('property_name')->toArray();
            expect($propertyNames)->toContain('bio');
            expect($propertyNames)->toContain('age');
            expect($propertyNames)->toContain('active');
        });

        it('handles date value casting correctly', function () {
            $date = '2023-12-25';
            $entityProperty = EntityProperty::create([
                'entity_id' => $this->user->id,
                'entity_type' => get_class($this->user),
                'property_id' => $this->dateProperty->id,
                'property_name' => $this->dateProperty->name,
                'date_value' => $date
            ]);

            expect($entityProperty->date_value)->toBeInstanceOf(\Carbon\Carbon::class);
            expect($entityProperty->date_value->format('Y-m-d'))->toBe($date);
            expect($entityProperty->value)->toBeInstanceOf(\Carbon\Carbon::class);
        });

        it('handles decimal precision for number values', function () {
            $entityProperty = EntityProperty::create([
                'entity_id' => $this->user->id,
                'entity_type' => get_class($this->user),
                'property_id' => $this->numberProperty->id,
                'property_name' => $this->numberProperty->name,
                'number_value' => 123.456789
            ]);

            // Should be cast to float
            expect($entityProperty->number_value)->toBeFloat();
            expect($entityProperty->number_value)->toBe(123.456789);
        });
    });
});