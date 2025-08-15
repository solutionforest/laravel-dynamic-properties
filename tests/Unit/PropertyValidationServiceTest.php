<?php

use SolutionForest\LaravelDynamicProperties\Exceptions\PropertyValidationException;
use SolutionForest\LaravelDynamicProperties\Models\Property;
use SolutionForest\LaravelDynamicProperties\Services\PropertyValidationService;

beforeEach(function () {
    $this->validator = new PropertyValidationService;
});

describe('PropertyValidationService - Comprehensive Tests', function () {

    describe('validatePropertyDefinition', function () {
        it('validates required fields', function () {
            $errors = $this->validator->validatePropertyDefinition([]);

            expect($errors)->toHaveKey('name');
            expect($errors)->toHaveKey('label');
            expect($errors)->toHaveKey('type');
        });

        it('validates property name format', function () {
            $errors = $this->validator->validatePropertyDefinition([
                'name'  => '123invalid',
                'label' => 'Test',
                'type'  => 'text',
            ]);

            expect($errors)->toHaveKey('name');
            expect($errors['name'])->toContain('must start with a letter');
        });

        it('validates property type', function () {
            $errors = $this->validator->validatePropertyDefinition([
                'name'  => 'test',
                'label' => 'Test',
                'type'  => 'invalid_type',
            ]);

            expect($errors)->toHaveKey('type');
            expect($errors['type'])->toContain('must be one of');
        });

        it('validates select property options', function () {
            $errors = $this->validator->validatePropertyDefinition([
                'name'  => 'test',
                'label' => 'Test',
                'type'  => 'select',
            ]);

            expect($errors)->toHaveKey('options');
            expect($errors['options'])->toContain('must have at least one option');
        });

        it('validates validation rules', function () {
            $errors = $this->validator->validatePropertyDefinition([
                'name'       => 'test',
                'label'      => 'Test',
                'type'       => 'text',
                'validation' => [
                    'min' => -1,
                    'max' => 'invalid',
                ],
            ]);

            expect($errors)->toHaveKey('validation');
            expect($errors['validation'])->toBeArray();
        });

        it('passes valid property definition', function () {
            $errors = $this->validator->validatePropertyDefinition([
                'name'       => 'test_property',
                'label'      => 'Test Property',
                'type'       => 'text',
                'required'   => true,
                'validation' => [
                    'min' => 1,
                    'max' => 100,
                ],
            ]);

            expect($errors)->toBeEmpty();
        });
    });

    describe('validatePropertyValue', function () {
        beforeEach(function () {
            $this->textProperty = new Property([
                'name'       => 'test_text',
                'label'      => 'Test Text',
                'type'       => 'text',
                'required'   => true,
                'validation' => ['min' => 3, 'max' => 10],
            ]);

            $this->numberProperty = new Property([
                'name'       => 'test_number',
                'label'      => 'Test Number',
                'type'       => 'number',
                'required'   => false,
                'validation' => ['min' => 0, 'max' => 100],
            ]);

            $this->selectProperty = new Property([
                'name'     => 'test_select',
                'label'    => 'Test Select',
                'type'     => 'select',
                'required' => true,
                'options'  => ['option1', 'option2', 'option3'],
            ]);
        });

        it('throws exception for required field with null value', function () {
            expect(fn () => $this->validator->validatePropertyValue($this->textProperty, null))
                ->toThrow(PropertyValidationException::class);
        });

        it('throws exception for required field with empty string', function () {
            expect(fn () => $this->validator->validatePropertyValue($this->textProperty, ''))
                ->toThrow(PropertyValidationException::class);
        });

        it('allows null for non-required fields', function () {
            // This should not throw an exception
            $this->validator->validatePropertyValue($this->numberProperty, null);
            expect(true)->toBeTrue(); // If we get here, no exception was thrown
        });

        it('validates text length constraints', function () {
            expect(fn () => $this->validator->validatePropertyValue($this->textProperty, 'ab'))
                ->toThrow(PropertyValidationException::class);

            expect(fn () => $this->validator->validatePropertyValue($this->textProperty, 'this is too long'))
                ->toThrow(PropertyValidationException::class);
        });

        it('validates number range constraints', function () {
            expect(fn () => $this->validator->validatePropertyValue($this->numberProperty, -1))
                ->toThrow(PropertyValidationException::class);

            expect(fn () => $this->validator->validatePropertyValue($this->numberProperty, 101))
                ->toThrow(PropertyValidationException::class);
        });

        it('validates select options', function () {
            expect(fn () => $this->validator->validatePropertyValue($this->selectProperty, 'invalid_option'))
                ->toThrow(PropertyValidationException::class);
        });

        it('passes valid values', function () {
            // These should not throw exceptions
            $this->validator->validatePropertyValue($this->textProperty, 'valid');
            $this->validator->validatePropertyValue($this->numberProperty, 50);
            $this->validator->validatePropertyValue($this->selectProperty, 'option1');
            expect(true)->toBeTrue(); // If we get here, no exceptions were thrown
        });
    });

    describe('type validation', function () {
        it('validates text type', function () {
            $property = new Property(['name' => 'test', 'label' => 'Test', 'type' => 'text']);

            // These should not throw exceptions
            $this->validator->validatePropertyValue($property, 'text');
            $this->validator->validatePropertyValue($property, 123); // Numbers are allowed for text
            expect(true)->toBeTrue();
        });

        it('validates number type', function () {
            $property = new Property(['name' => 'test', 'label' => 'Test', 'type' => 'number']);

            // These should not throw exceptions
            $this->validator->validatePropertyValue($property, 123);
            $this->validator->validatePropertyValue($property, '123.45');

            expect(fn () => $this->validator->validatePropertyValue($property, 'not a number'))
                ->toThrow(PropertyValidationException::class);
        });

        it('validates boolean type', function () {
            $property = new Property(['name' => 'test', 'label' => 'Test', 'type' => 'boolean']);

            // These should not throw exceptions
            $this->validator->validatePropertyValue($property, true);
            $this->validator->validatePropertyValue($property, false);
            $this->validator->validatePropertyValue($property, 1);
            $this->validator->validatePropertyValue($property, '0');

            expect(fn () => $this->validator->validatePropertyValue($property, 'invalid'))
                ->toThrow(PropertyValidationException::class);
        });

        it('validates date type', function () {
            $property = new Property(['name' => 'test', 'label' => 'Test', 'type' => 'date']);

            // These should not throw exceptions
            $this->validator->validatePropertyValue($property, '2023-01-01');
            $this->validator->validatePropertyValue($property, new DateTime);

            expect(fn () => $this->validator->validatePropertyValue($property, 'invalid date'))
                ->toThrow(PropertyValidationException::class);
        });

        it('validates select type with array options', function () {
            $property = new Property([
                'name'    => 'test',
                'label'   => 'Test',
                'type'    => 'select',
                'options' => ['option1', 'option2', 'option3'],
            ]);

            // Valid options
            $this->validator->validatePropertyValue($property, 'option1');
            $this->validator->validatePropertyValue($property, 'option2');

            expect(fn () => $this->validator->validatePropertyValue($property, 'invalid_option'))
                ->toThrow(PropertyValidationException::class);
        });
    });

    describe('date validation rules', function () {
        it('validates after constraint', function () {
            $property = new Property([
                'name'       => 'test',
                'label'      => 'Test',
                'type'       => 'date',
                'validation' => ['after' => '2023-01-01'],
            ]);

            // This should not throw an exception
            $this->validator->validatePropertyValue($property, '2023-01-02');

            expect(fn () => $this->validator->validatePropertyValue($property, '2022-12-31'))
                ->toThrow(PropertyValidationException::class);
        });

        it('validates before constraint', function () {
            $property = new Property([
                'name'       => 'test',
                'label'      => 'Test',
                'type'       => 'date',
                'validation' => ['before' => '2023-12-31'],
            ]);

            // This should not throw an exception
            $this->validator->validatePropertyValue($property, '2023-12-30');

            expect(fn () => $this->validator->validatePropertyValue($property, '2024-01-01'))
                ->toThrow(PropertyValidationException::class);
        });
    });

    describe('Complex Validation Scenarios', function () {
        it('validates property definition with all fields', function () {
            $errors = $this->validator->validatePropertyDefinition([
                'name'       => 'complex_property',
                'label'      => 'Complex Property',
                'type'       => 'text',
                'required'   => true,
                'validation' => [
                    'min' => 5,
                    'max' => 100,
                ],
                'options' => null,
            ]);

            expect($errors)->toBeEmpty();
        });

        it('validates select property definition with options', function () {
            $errors = $this->validator->validatePropertyDefinition([
                'name'     => 'status',
                'label'    => 'Status',
                'type'     => 'select',
                'required' => false,
                'options'  => ['active', 'inactive', 'pending'],
            ]);

            expect($errors)->toBeEmpty();
        });

        it('validates number property with range constraints', function () {
            $property = new Property([
                'name'       => 'score',
                'label'      => 'Score',
                'type'       => 'number',
                'required'   => true,
                'validation' => ['min' => 0, 'max' => 100],
            ]);

            // Valid values
            $this->validator->validatePropertyValue($property, 0);
            $this->validator->validatePropertyValue($property, 50);
            $this->validator->validatePropertyValue($property, 100);
            $this->validator->validatePropertyValue($property, '75'); // String numbers

            // Invalid values
            expect(fn () => $this->validator->validatePropertyValue($property, -1))
                ->toThrow(PropertyValidationException::class);
            expect(fn () => $this->validator->validatePropertyValue($property, 101))
                ->toThrow(PropertyValidationException::class);
        });

        it('validates text property with length constraints', function () {
            $property = new Property([
                'name'       => 'description',
                'label'      => 'Description',
                'type'       => 'text',
                'required'   => false,
                'validation' => ['min' => 10, 'max' => 50],
            ]);

            // Valid values
            $this->validator->validatePropertyValue($property, 'This is a valid description');
            $this->validator->validatePropertyValue($property, str_repeat('a', 10)); // Minimum length
            $this->validator->validatePropertyValue($property, str_repeat('a', 50)); // Maximum length

            // Invalid values
            expect(fn () => $this->validator->validatePropertyValue($property, 'Too short'))
                ->toThrow(PropertyValidationException::class);
            expect(fn () => $this->validator->validatePropertyValue($property, str_repeat('a', 51)))
                ->toThrow(PropertyValidationException::class);
        });

        it('handles edge cases for boolean validation', function () {
            $property = new Property([
                'name'     => 'active',
                'label'    => 'Active',
                'type'     => 'boolean',
                'required' => false,
            ]);

            // Valid boolean values
            $this->validator->validatePropertyValue($property, true);
            $this->validator->validatePropertyValue($property, false);
            $this->validator->validatePropertyValue($property, 1);
            $this->validator->validatePropertyValue($property, 0);
            $this->validator->validatePropertyValue($property, '1');
            $this->validator->validatePropertyValue($property, '0');

            // Invalid boolean values
            expect(fn () => $this->validator->validatePropertyValue($property, 2))
                ->toThrow(PropertyValidationException::class);
            expect(fn () => $this->validator->validatePropertyValue($property, 'invalid'))
                ->toThrow(PropertyValidationException::class);
        });
    });

    describe('Error Message Quality', function () {
        it('provides meaningful error messages for validation failures', function () {
            $property = new Property([
                'name'       => 'age',
                'label'      => 'Age',
                'type'       => 'number',
                'required'   => true,
                'validation' => ['min' => 0, 'max' => 120],
            ]);

            try {
                $this->validator->validatePropertyValue($property, null);
                expect(false)->toBeTrue(); // Should not reach here
            } catch (PropertyValidationException $e) {
                expect($e->getUserMessage())->toContain('required');
            }

            try {
                $this->validator->validatePropertyValue($property, -5);
                expect(false)->toBeTrue(); // Should not reach here
            } catch (PropertyValidationException $e) {
                expect($e->getUserMessage())->toContain('at least');
            }

            try {
                $this->validator->validatePropertyValue($property, 150);
                expect(false)->toBeTrue(); // Should not reach here
            } catch (PropertyValidationException $e) {
                expect($e->getUserMessage())->toContain('may not be greater than');
            }
        });
    });

    describe('Property Definition Edge Cases', function () {
        it('validates property name format restrictions', function () {
            $errors = $this->validator->validatePropertyDefinition([
                'name'  => 'valid_property_name',
                'label' => 'Valid Property',
                'type'  => 'text',
            ]);
            expect($errors)->toBeEmpty();

            $errors = $this->validator->validatePropertyDefinition([
                'name'  => '123invalid',
                'label' => 'Invalid Property',
                'type'  => 'text',
            ]);
            expect($errors)->toHaveKey('name');

            $errors = $this->validator->validatePropertyDefinition([
                'name'  => 'invalid-name-with-dashes',
                'label' => 'Invalid Property',
                'type'  => 'text',
            ]);
            expect($errors)->toHaveKey('name');
        });

        it('validates select property requires options', function () {
            $errors = $this->validator->validatePropertyDefinition([
                'name'  => 'status',
                'label' => 'Status',
                'type'  => 'select',
                // Missing options
            ]);
            expect($errors)->toHaveKey('options');

            $errors = $this->validator->validatePropertyDefinition([
                'name'    => 'status',
                'label'   => 'Status',
                'type'    => 'select',
                'options' => [],
            ]);
            expect($errors)->toHaveKey('options');
        });

        it('validates validation rules format', function () {
            $errors = $this->validator->validatePropertyDefinition([
                'name'       => 'test',
                'label'      => 'Test',
                'type'       => 'number',
                'validation' => [
                    'min' => 'invalid', // Should be numeric
                    'max' => 100,
                ],
            ]);
            expect($errors)->toHaveKey('validation');
        });
    });
});
