<?php

use Carbon\Carbon;
use DynamicProperties\Models\Property;
use Illuminate\Database\QueryException;

describe('Property Model - Comprehensive Tests', function () {
    beforeEach(function () {
        // Create test properties for each test with unique names
        $this->textProperty = Property::firstOrCreate(['name' => 'model_description'], [
            'label' => 'Description',
            'type' => 'text',
            'required' => false,
            'validation' => ['min' => 5, 'max' => 100],
        ]);

        $this->numberProperty = Property::firstOrCreate(['name' => 'model_age'], [
            'label' => 'Age',
            'type' => 'number',
            'required' => true,
            'validation' => ['min' => 0, 'max' => 120],
        ]);

        $this->selectProperty = Property::firstOrCreate(['name' => 'model_status'], [
            'label' => 'Status',
            'type' => 'select',
            'required' => false,
            'options' => ['active', 'inactive', 'pending'],
        ]);

        $this->booleanProperty = Property::firstOrCreate(['name' => 'model_verified'], [
            'label' => 'Verified',
            'type' => 'boolean',
            'required' => false,
        ]);

        $this->dateProperty = Property::firstOrCreate(['name' => 'model_birth_date'], [
            'label' => 'Birth Date',
            'type' => 'date',
            'required' => false,
            'validation' => ['after' => '1900-01-01', 'before' => 'today'],
        ]);
    });

    it('can create a property with all required fields', function () {
        $property = Property::create([
            'name' => 'test_property',
            'label' => 'Test Property',
            'type' => 'text',
            'required' => false,
        ]);

        expect($property)->toBeInstanceOf(Property::class)
            ->and($property->name)->toBe('test_property')
            ->and($property->label)->toBe('Test Property')
            ->and($property->type)->toBe('text')
            ->and($property->required)->toBeFalse();
    });

    it('enforces unique property names', function () {
        Property::create([
            'name' => 'unique_test',
            'label' => 'First Property',
            'type' => 'text',
        ]);

        expect(fn() => Property::create([
            'name' => 'unique_test',
            'label' => 'Second Property',
            'type' => 'number',
        ]))->toThrow(QueryException::class);
    });

    it('casts options to array', function () {
        expect($this->selectProperty->options)
            ->toBeArray()
            ->toEqual(['active', 'inactive', 'pending']);
    });

    it('casts validation to array', function () {
        expect($this->textProperty->validation)
            ->toBeArray()
            ->toEqual(['min' => 5, 'max' => 100]);
    });

    it('can validate text property constraints', function () {
        $property = $this->textProperty;

        // Valid text
        expect($property->validateValue('Hello World'))->toBeTrue();

        // Too short
        expect($property->validateValue('Hi'))->toBeFalse();

        // Too long
        expect($property->validateValue(str_repeat('a', 101)))->toBeFalse();

        // Non-string
        expect($property->validateValue(123))->toBeFalse();
    });

    it('can validate number property constraints', function () {
        $property = $this->numberProperty;

        // Valid numbers
        expect($property->validateValue(25))->toBeTrue();
        expect($property->validateValue(0))->toBeTrue();
        expect($property->validateValue(120))->toBeTrue();
        expect($property->validateValue('25'))->toBeTrue(); // String numbers should be valid

        // Out of range
        expect($property->validateValue(-1))->toBeFalse();
        expect($property->validateValue(121))->toBeFalse();

        // Non-numeric
        expect($property->validateValue('not a number'))->toBeFalse();
        expect($property->validateValue(true))->toBeFalse();
    });

    it('can validate select property options', function () {
        $property = $this->selectProperty;

        // Valid options
        expect($property->validateValue('active'))->toBeTrue();
        expect($property->validateValue('inactive'))->toBeTrue();
        expect($property->validateValue('pending'))->toBeTrue();

        // Invalid options
        expect($property->validateValue('invalid'))->toBeFalse();
        expect($property->validateValue(''))->toBeFalse();

        // Null should be valid for optional select properties
        expect($property->validateValue(null))->toBeTrue();
    });

    it('can validate boolean property values', function () {
        $property = $this->booleanProperty;

        // Valid booleans
        expect($property->validateValue(true))->toBeTrue();
        expect($property->validateValue(false))->toBeTrue();
        expect($property->validateValue(1))->toBeTrue();
        expect($property->validateValue(0))->toBeTrue();
        expect($property->validateValue('1'))->toBeTrue();
        expect($property->validateValue('0'))->toBeTrue();

        // Invalid values
        expect($property->validateValue('true'))->toBeFalse();
        expect($property->validateValue('false'))->toBeFalse();
        expect($property->validateValue(2))->toBeFalse();
        expect($property->validateValue('invalid'))->toBeFalse();
    });

    it('can validate date property values', function () {
        $property = $this->dateProperty;

        // Valid dates
        expect($property->validateValue('1990-05-15'))->toBeTrue();
        expect($property->validateValue('2000-12-31'))->toBeTrue();

        // Invalid dates
        expect($property->validateValue('1899-12-31'))->toBeFalse(); // Before min
        expect($property->validateValue('2050-01-01'))->toBeFalse(); // After max (assuming today is before 2050)
        expect($property->validateValue('invalid-date'))->toBeFalse();
        expect($property->validateValue('2023-13-45'))->toBeFalse(); // Invalid date format
    });

    it('handles required validation', function () {
        $requiredProperty = $this->numberProperty; // This one is required
        $optionalProperty = $this->textProperty; // This one is not required

        // Required property should fail with null/empty
        expect($requiredProperty->validateValue(null))->toBeFalse();
        expect($requiredProperty->validateValue(''))->toBeFalse();

        // Optional property should pass with null/empty
        expect($optionalProperty->validateValue(null))->toBeTrue();
        expect($optionalProperty->validateValue(''))->toBeTrue();
    });

    it('can get validation error messages', function () {
        $property = $this->textProperty;

        $message = $property->getValidationError('Hi');
        expect($message)->toContain('minimum')
            ->and($message)->toContain('5');

        $message = $property->getValidationError(str_repeat('a', 101));
        expect($message)->toContain('maximum')
            ->and($message)->toContain('100');
    });

    it('can cast values to appropriate types', function () {
        // Text property
        expect($this->textProperty->castValue('Hello'))->toBe('Hello');
        expect($this->textProperty->castValue(123))->toBe('123');

        // Number property
        expect($this->numberProperty->castValue('25'))->toBe(25.0);
        expect($this->numberProperty->castValue(25))->toBe(25.0);

        // Boolean property
        expect($this->booleanProperty->castValue('1'))->toBeTrue();
        expect($this->booleanProperty->castValue('0'))->toBeFalse();
        expect($this->booleanProperty->castValue(1))->toBeTrue();
        expect($this->booleanProperty->castValue(0))->toBeFalse();

        // Date property
        $date = $this->dateProperty->castValue('2023-05-15');
        expect($date)->toBeInstanceOf(\Carbon\Carbon::class);
        expect($date->format('Y-m-d'))->toBe('2023-05-15');
    });

    it('has proper relationships', function () {
        // Create an entity property to test the relationship
        $entityProperty = \DynamicProperties\Models\EntityProperty::create([
            'entity_id' => 1,
            'entity_type' => 'App\\Models\\User',
            'property_id' => $this->textProperty->id,
            'property_name' => $this->textProperty->name,
            'string_value' => 'Test value',
        ]);

        $property = Property::with('entityProperties')->find($this->textProperty->id);

        expect($property->entityProperties)->toHaveCount(1);
        expect($property->entityProperties->first()->id)->toBe($entityProperty->id);
    });

    describe('Advanced Type Casting', function () {
        it('casts date strings to Carbon instances', function () {
            $property = Property::create([
                'name' => 'test_date',
                'label' => 'Test Date',
                'type' => 'date',
            ]);

            $result = $property->castValue('2023-12-25');
            expect($result)->toBeInstanceOf(Carbon::class);
            expect($result->format('Y-m-d'))->toBe('2023-12-25');
        });

        it('handles invalid date strings gracefully', function () {
            $property = Property::create([
                'name' => 'test_date',
                'label' => 'Test Date',
                'type' => 'date',
            ]);

            $result = $property->castValue('invalid-date');
            expect($result)->toBeNull();
        });

        it('casts numeric strings to floats for number type', function () {
            $property = Property::create([
                'name' => 'test_number',
                'label' => 'Test Number',
                'type' => 'number',
            ]);

            expect($property->castValue('123.45'))->toBe(123.45);
            expect($property->castValue('0'))->toBe(0.0);
            expect($property->castValue('-42.7'))->toBe(-42.7);
        });

        it('handles boolean casting edge cases', function () {
            $property = Property::create([
                'name' => 'test_bool',
                'label' => 'Test Boolean',
                'type' => 'boolean',
            ]);

            expect($property->castValue(true))->toBeTrue();
            expect($property->castValue(false))->toBeFalse();
            expect($property->castValue(1))->toBeTrue();
            expect($property->castValue(0))->toBeFalse();
            expect($property->castValue('1'))->toBeTrue();
            expect($property->castValue('0'))->toBeFalse();
        });
    });

    describe('Complex Validation Rules', function () {
        it('validates date after constraint with today keyword', function () {
            $property = Property::create([
                'name' => 'future_date',
                'label' => 'Future Date',
                'type' => 'date',
                'validation' => ['after' => 'today'],
            ]);

            $tomorrow = Carbon::tomorrow()->format('Y-m-d');
            $yesterday = Carbon::yesterday()->format('Y-m-d');

            expect($property->validateValue($tomorrow))->toBeTrue();
            expect($property->validateValue($yesterday))->toBeFalse();
        });

        it('validates date before constraint with today keyword', function () {
            $property = Property::create([
                'name' => 'past_date',
                'label' => 'Past Date',
                'type' => 'date',
                'validation' => ['before' => 'today'],
            ]);

            $tomorrow = Carbon::tomorrow()->format('Y-m-d');
            $yesterday = Carbon::yesterday()->format('Y-m-d');

            expect($property->validateValue($yesterday))->toBeTrue();
            expect($property->validateValue($tomorrow))->toBeFalse();
        });

        it('validates text with min_length and max_length rules', function () {
            $property = Property::create([
                'name' => 'description',
                'label' => 'Description',
                'type' => 'text',
                'validation' => ['min_length' => 10, 'max_length' => 50],
            ]);

            expect($property->validateValue('This is a valid description'))->toBeTrue();
            expect($property->validateValue('Too short'))->toBeFalse();
            expect($property->validateValue(str_repeat('a', 60)))->toBeFalse();
        });

        it('validates multiple validation rules together', function () {
            $property = Property::create([
                'name' => 'score',
                'label' => 'Score',
                'type' => 'number',
                'required' => true,
                'validation' => ['min' => 0, 'max' => 100],
            ]);

            expect($property->validateValue(50))->toBeTrue();
            expect($property->validateValue(null))->toBeFalse(); // Required
            expect($property->validateValue(-10))->toBeFalse(); // Below min
            expect($property->validateValue(150))->toBeFalse(); // Above max
        });
    });

    describe('Error Messages', function () {
        it('provides specific error messages for different validation failures', function () {
            $numberProperty = Property::create([
                'name' => 'age',
                'label' => 'Age',
                'type' => 'number',
                'required' => true,
                'validation' => ['min' => 0, 'max' => 120],
            ]);

            $message = $numberProperty->getValidationError(null);
            expect($message)->toContain('required');

            $message = $numberProperty->getValidationError(-5);
            expect($message)->toContain('at least 0');

            $message = $numberProperty->getValidationError(150);
            expect($message)->toContain('not be greater than 120');
        });
    });

    describe('Property Types Edge Cases', function () {
        it('handles select property with empty options array', function () {
            $property = Property::create([
                'name' => 'empty_select',
                'label' => 'Empty Select',
                'type' => 'select',
                'options' => [],
            ]);

            expect($property->validateValue('any_value'))->toBeFalse();
            expect($property->validateValue(null))->toBeTrue(); // Optional by default
        });

        it('handles select property with null options', function () {
            $property = Property::create([
                'name' => 'null_select',
                'label' => 'Null Select',
                'type' => 'select',
                'options' => null,
            ]);

            expect($property->validateValue('any_value'))->toBeFalse();
        });

        it('validates text property with numeric values', function () {
            $property = Property::create([
                'name' => 'text_field',
                'label' => 'Text Field',
                'type' => 'text',
            ]);

            expect($property->validateValue(123))->toBeTrue();
            expect($property->castValue(123))->toBe('123');
        });
    });
});
