<?php

use Illuminate\Support\Facades\Schema;
use SolutionForest\LaravelDynamicProperties\Exceptions\PropertyNotFoundException;
use SolutionForest\LaravelDynamicProperties\Exceptions\PropertyOperationException;
use SolutionForest\LaravelDynamicProperties\Exceptions\PropertyValidationException;
use SolutionForest\LaravelDynamicProperties\Models\Property;
use SolutionForest\LaravelDynamicProperties\Services\PropertyService;

class TestEntity extends \Illuminate\Database\Eloquent\Model
{
    use \SolutionForest\LaravelDynamicProperties\Traits\HasProperties;

    protected $table = 'test_entities';

    protected $fillable = ['name'];
}

beforeEach(function () {
    // Create test entities table
    if (! Schema::hasTable('test_entities')) {
        Schema::create('test_entities', function ($table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
    }
});

describe('Property Error Handling', function () {
    beforeEach(function () {
        $this->service = new PropertyService;
        $this->entity = TestEntity::create(['name' => 'Test Entity']);

        // Create test properties with unique names for this test suite
        $this->textProperty = Property::firstOrCreate(['name' => 'error_test_text'], [
            'label'      => 'Test Text',
            'type'       => 'text',
            'required'   => true,
            'validation' => ['min' => 3, 'max' => 10],
        ]);

        $this->numberProperty = Property::firstOrCreate(['name' => 'error_test_number'], [
            'label'      => 'Test Number',
            'type'       => 'number',
            'required'   => false,
            'validation' => ['min' => 0, 'max' => 100],
        ]);

        $this->selectProperty = Property::firstOrCreate(['name' => 'error_test_select'], [
            'label'    => 'Test Select',
            'type'     => 'select',
            'required' => true,
            'options'  => ['option1', 'option2', 'option3'],
        ]);
    });

    describe('PropertyService error handling', function () {

        it('throws PropertyNotFoundException for non-existent property', function () {
            expect(fn () => $this->service->setDynamicProperty($this->entity, 'non_existent', 'value'))
                ->toThrow(PropertyNotFoundException::class);
        });

        it('throws PropertyValidationException for invalid values', function () {
            // Test required field validation
            expect(fn () => $this->service->setDynamicProperty($this->entity, 'error_test_text', ''))
                ->toThrow(PropertyValidationException::class);

            // Test length validation
            expect(fn () => $this->service->setDynamicProperty($this->entity, 'error_test_text', 'ab'))
                ->toThrow(PropertyValidationException::class);

            // Test range validation
            expect(fn () => $this->service->setDynamicProperty($this->entity, 'error_test_number', -1))
                ->toThrow(PropertyValidationException::class);

            // Test select options validation
            expect(fn () => $this->service->setDynamicProperty($this->entity, 'error_test_select', 'invalid'))
                ->toThrow(PropertyValidationException::class);
        });

        it('throws PropertyOperationException for unsaved entity', function () {
            $unsavedEntity = new TestEntity(['name' => 'Unsaved']);

            expect(fn () => $this->service->setDynamicProperty($unsavedEntity, 'error_test_text', 'value'))
                ->toThrow(PropertyOperationException::class);
        });

        it('provides detailed error context in exceptions', function () {
            $service = new PropertyService;
            $entity = TestEntity::create(['name' => 'Test Entity']);

            try {
                $service->setDynamicProperty($entity, 'non_existent', 'value');
                expect(false)->toBeTrue('Exception should have been thrown');
            } catch (PropertyNotFoundException $e) {
                expect($e->getContext())->toHaveKey('property_name');
                expect($e->getContext())->toHaveKey('entity_type');
                expect($e->getContext())->toHaveKey('entity_id');
                expect($e->getContext()['property_name'])->toBe('non_existent');
            }
        });

        it('handles batch property validation errors', function () {
            $service = new PropertyService;
            $entity = TestEntity::create(['name' => 'Test Entity']);

            $properties = [
                'error_test_text'   => 'ab', // Too short
                'error_test_number' => 101, // Too high
                'error_test_select' => 'invalid', // Invalid option
                'non_existent'      => 'value', // Doesn't exist
            ];

            try {
                $service->setProperties($entity, $properties);
                expect(false)->toBeTrue('Exception should have been thrown');
            } catch (PropertyValidationException $e) {
                $errors = $e->getValidationErrors();
                expect($errors)->toBeArray();
                expect(count($errors))->toBeGreaterThan(1);
                expect($e->getContext())->toHaveKey('failed_properties');
            }
        });
    });

    describe('HasProperties trait error handling', function () {

        it('throws exceptions through trait methods', function () {
            expect(fn () => $this->entity->setDynamicProperty('non_existent', 'value'))
                ->toThrow(PropertyNotFoundException::class);

            expect(fn () => $this->entity->setDynamicProperty('error_test_text', ''))
                ->toThrow(PropertyValidationException::class);
        });

        it('converts exceptions in magic methods', function () {
            // Magic methods convert PropertyExceptions to InvalidArgumentException
            expect(fn () => $this->entity->prop_non_existent = 'value')
                ->toThrow(InvalidArgumentException::class);
        });

        it('handles batch property errors through trait', function () {
            $properties = [
                'error_test_text' => 'ab',
                'non_existent'    => 'value',
            ];

            expect(fn () => $this->entity->setProperties($properties))
                ->toThrow(PropertyValidationException::class);
        });
    });

    describe('Property creation error handling', function () {

        it('validates property definition', function () {
            expect(fn () => $this->service->createProperty([]))
                ->toThrow(PropertyValidationException::class);

            expect(fn () => $this->service->createProperty([
                'name'  => '123invalid',
                'label' => 'Test',
                'type'  => 'text',
            ]))->toThrow(PropertyValidationException::class);
        });

        it('prevents duplicate property names', function () {
            expect(fn () => $this->service->createProperty([
                'name'  => 'error_test_text', // Already exists
                'label' => 'Duplicate',
                'type'  => 'text',
            ]))->toThrow(PropertyValidationException::class);
        });

        it('validates select property options', function () {
            expect(fn () => $this->service->createProperty([
                'name'  => 'invalid_select',
                'label' => 'Invalid Select',
                'type'  => 'select',
                // Missing options
            ]))->toThrow(PropertyValidationException::class);
        });
    });

    describe('Error message quality', function () {

        it('provides user-friendly error messages', function () {
            try {
                $this->service->setDynamicProperty($this->entity, 'error_test_text', 'ab');
            } catch (PropertyValidationException $e) {
                $userMessage = $e->getUserMessage();
                expect($userMessage)->toContain('Test Text'); // Uses label, not name
                expect($userMessage)->toContain('at least 3 characters');
            }

            try {
                $this->service->setDynamicProperty($this->entity, 'non_existent', 'value');
            } catch (PropertyNotFoundException $e) {
                $userMessage = $e->getUserMessage();
                expect($userMessage)->toContain('does not exist');
                expect($userMessage)->toContain('check the property name');
            }
        });

        it('includes validation context in error arrays', function () {
            try {
                $this->service->setDynamicProperty($this->entity, 'error_test_text', '');
            } catch (PropertyValidationException $e) {
                $array = $e->toArray();
                expect($array)->toHaveKey('error');
                expect($array)->toHaveKey('message');
                expect($array)->toHaveKey('context');
                expect($array)->toHaveKey('validation_errors');
                expect($array['context'])->toHaveKey('property_name');
                expect($array['context'])->toHaveKey('property_label');
            }
        });
    });

    describe('Edge cases and error recovery', function () {

        it('handles null and empty values correctly', function () {
            // Non-required field should accept null
            expect(fn () => $this->service->setDynamicProperty($this->entity, 'error_test_number', null))
                ->not->toThrow(\Exception::class);

            // Required field should reject null
            expect(fn () => $this->service->setDynamicProperty($this->entity, 'error_test_text', null))
                ->toThrow(PropertyValidationException::class);

            // Required field should reject empty string
            expect(fn () => $this->service->setDynamicProperty($this->entity, 'error_test_text', ''))
                ->toThrow(PropertyValidationException::class);
        });

        it('handles type conversion errors gracefully', function () {
            expect(fn () => $this->service->setDynamicProperty($this->entity, 'error_test_number', 'not a number'))
                ->toThrow(PropertyValidationException::class);
        });

        it('maintains data consistency on batch operation failures', function () {
            // Set some initial valid properties
            $this->service->setDynamicProperty($this->entity, 'error_test_text', 'valid');
            $this->service->setDynamicProperty($this->entity, 'error_test_number', 50);

            // Try to batch update with some invalid values
            try {
                $this->service->setProperties($this->entity, [
                    'error_test_text'   => 'ab', // Invalid
                    'error_test_number' => 75,  // Valid
                    'error_test_select' => 'option1', // Valid
                ]);
            } catch (PropertyValidationException $e) {
                // Original values should be unchanged
                expect($this->entity->getDynamicProperty('error_test_text'))->toBe('valid');
                expect($this->entity->getDynamicProperty('error_test_number'))->toBe(50.0);

                // New property should not be set
                expect($this->entity->getDynamicProperty('error_test_select'))->toBeNull();
            }
        });
    });
});
