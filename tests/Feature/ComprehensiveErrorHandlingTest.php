<?php

use DynamicProperties\Exceptions\PropertyNotFoundException;
use DynamicProperties\Exceptions\PropertyOperationException;
use DynamicProperties\Exceptions\PropertyValidationException;
use DynamicProperties\Models\Property;
use DynamicProperties\Services\PropertyService;
use Illuminate\Database\Eloquent\Model;

class TestUser extends Model
{
    use \DynamicProperties\Traits\HasProperties;

    protected $table = 'test_users';

    protected $fillable = ['name', 'email'];
}

beforeEach(function () {
    // Create test table
    Schema::create('test_users', function ($table) {
        $table->id();
        $table->string('name');
        $table->string('email');
        $table->timestamps();
    });

    $this->service = new PropertyService;
    $this->user = TestUser::create(['name' => 'John Doe', 'email' => 'john@example.com']);

    // Create comprehensive test properties
    Property::create([
        'name' => 'phone',
        'label' => 'Phone Number',
        'type' => 'text',
        'required' => true,
        'validation' => ['min' => 10, 'max' => 15],
    ]);

    Property::create([
        'name' => 'age',
        'label' => 'Age',
        'type' => 'number',
        'required' => false,
        'validation' => ['min' => 0, 'max' => 120],
    ]);

    Property::create([
        'name' => 'status',
        'label' => 'Status',
        'type' => 'select',
        'required' => true,
        'options' => ['active', 'inactive', 'pending'],
    ]);

    Property::create([
        'name' => 'birth_date',
        'label' => 'Birth Date',
        'type' => 'date',
        'required' => false,
        'validation' => ['before' => 'today'],
    ]);

    Property::create([
        'name' => 'is_verified',
        'label' => 'Is Verified',
        'type' => 'boolean',
        'required' => false,
    ]);
});

describe('Comprehensive Error Handling', function () {
    describe('Property not found errors', function () {
        it('provides clear error messages for non-existent properties', function () {
            try {
                $this->service->setDynamicProperty($this->user, 'non_existent_property', 'value');
                expect(false)->toBeTrue('Should have thrown exception');
            } catch (PropertyNotFoundException $e) {
                expect($e->getMessage())->toContain('non_existent_property');
                expect($e->getUserMessage())->toContain('does not exist');
                expect($e->getUserMessage())->toContain('check the property name');
                expect($e->getCode())->toBe(404);

                $context = $e->getContext();
                expect($context)->toHaveKey('property_name');
                expect($context)->toHaveKey('entity_type');
                expect($context)->toHaveKey('entity_id');
                expect($context['property_name'])->toBe('non_existent_property');
            }
        });
    });

    describe('Validation errors', function () {
        it('validates required fields', function () {
            try {
                $this->service->setDynamicProperty($this->user, 'phone', '');
                expect(false)->toBeTrue('Should have thrown exception');
            } catch (PropertyValidationException $e) {
                expect($e->getUserMessage())->toContain('Phone Number');
                expect($e->getUserMessage())->toContain('required');
                expect($e->getCode())->toBe(422);
            }
        });

        it('validates text length constraints', function () {
            try {
                $this->service->setDynamicProperty($this->user, 'phone', '123'); // Too short
                expect(false)->toBeTrue('Should have thrown exception');
            } catch (PropertyValidationException $e) {
                expect($e->getUserMessage())->toContain('Phone Number');
                expect($e->getUserMessage())->toContain('at least 10 characters');
            }

            try {
                $this->service->setDynamicProperty($this->user, 'phone', '1234567890123456'); // Too long
                expect(false)->toBeTrue('Should have thrown exception');
            } catch (PropertyValidationException $e) {
                expect($e->getUserMessage())->toContain('Phone Number');
                expect($e->getUserMessage())->toContain('may not be greater than 15 characters');
            }
        });

        it('validates number range constraints', function () {
            try {
                $this->service->setDynamicProperty($this->user, 'age', -5);
                expect(false)->toBeTrue('Should have thrown exception');
            } catch (PropertyValidationException $e) {
                expect($e->getUserMessage())->toContain('Age');
                expect($e->getUserMessage())->toContain('at least 0');
            }

            try {
                $this->service->setDynamicProperty($this->user, 'age', 150);
                expect(false)->toBeTrue('Should have thrown exception');
            } catch (PropertyValidationException $e) {
                expect($e->getUserMessage())->toContain('Age');
                expect($e->getUserMessage())->toContain('may not be greater than 120');
            }
        });

        it('validates select options', function () {
            try {
                $this->service->setDynamicProperty($this->user, 'status', 'invalid_status');
                expect(false)->toBeTrue('Should have thrown exception');
            } catch (PropertyValidationException $e) {
                expect($e->getUserMessage())->toContain('Status');
                expect($e->getUserMessage())->toContain('active, inactive, pending');
            }
        });

        it('validates date constraints', function () {
            try {
                $this->service->setDynamicProperty($this->user, 'birth_date', '2030-01-01'); // Future date
                expect(false)->toBeTrue('Should have thrown exception');
            } catch (PropertyValidationException $e) {
                expect($e->getUserMessage())->toContain('Birth Date');
                expect($e->getUserMessage())->toContain('before today');
            }
        });

        it('validates type constraints', function () {
            try {
                $this->service->setDynamicProperty($this->user, 'age', 'not a number');
                expect(false)->toBeTrue('Should have thrown exception');
            } catch (PropertyValidationException $e) {
                expect($e->getUserMessage())->toContain('Age');
                expect($e->getUserMessage())->toContain('must be a number');
            }
        });
    });

    describe('Batch operation errors', function () {
        it('validates all properties before making changes', function () {
            $properties = [
                'phone' => '123', // Too short
                'age' => -5, // Too low
                'status' => 'invalid', // Invalid option
                'non_existent' => 'value', // Doesn't exist
            ];

            try {
                $this->service->setProperties($this->user, $properties);
                expect(false)->toBeTrue('Should have thrown exception');
            } catch (PropertyValidationException $e) {
                $errors = $e->getValidationErrors();
                expect($errors)->toBeArray();
                expect(count($errors))->toBeGreaterThan(1);

                $context = $e->getContext();
                expect($context)->toHaveKey('failed_properties');
                expect($context['failed_properties'])->toContain('phone');
                expect($context['failed_properties'])->toContain('age');
                expect($context['failed_properties'])->toContain('status');
                expect($context['failed_properties'])->toContain('non_existent');
            }
        });

        it('maintains data consistency on failures', function () {
            // Set initial valid values
            $this->service->setDynamicProperty($this->user, 'phone', '1234567890');
            $this->service->setDynamicProperty($this->user, 'age', 25);

            // Try batch update with some invalid values
            try {
                $this->service->setProperties($this->user, [
                    'phone' => '123', // Invalid
                    'age' => 30, // Valid
                    'status' => 'active', // Valid
                ]);
                expect(false)->toBeTrue('Should have thrown exception');
            } catch (PropertyValidationException $e) {
                // Original values should be unchanged
                expect($this->user->getDynamicProperty('phone'))->toBe('1234567890');
                expect($this->user->getDynamicProperty('age'))->toBe(25.0);

                // New property should not be set
                expect($this->user->getDynamicProperty('status'))->toBeNull();
            }
        });
    });

    describe('Operation errors', function () {
        it('prevents operations on unsaved entities', function () {
            $unsavedUser = new TestUser(['name' => 'Unsaved', 'email' => 'unsaved@example.com']);

            try {
                $this->service->setDynamicProperty($unsavedUser, 'phone', '1234567890');
                expect(false)->toBeTrue('Should have thrown exception');
            } catch (PropertyOperationException $e) {
                expect($e->getUserMessage())->toContain('could not be completed');
                expect($e->getCode())->toBe(500);

                $context = $e->getContext();
                expect($context)->toHaveKey('operation');
                expect($context)->toHaveKey('reason');
                expect($context['operation'])->toBe('set property');
            }
        });
    });

    describe('Property creation errors', function () {
        it('validates property definitions comprehensively', function () {
            try {
                $this->service->createProperty([
                    'name' => '123invalid', // Invalid name
                    'label' => '', // Missing label
                    'type' => 'invalid_type', // Invalid type
                    'validation' => [
                        'min' => -1, // Invalid validation
                        'max' => 'invalid', // Invalid validation
                    ],
                ]);
                expect(false)->toBeTrue('Should have thrown exception');
            } catch (PropertyValidationException $e) {
                $errors = $e->getValidationErrors();
                expect($errors)->toBeArray();
                expect(count($errors))->toBeGreaterThan(1);
            }
        });

        it('prevents duplicate property names', function () {
            try {
                $this->service->createProperty([
                    'name' => 'phone', // Already exists
                    'label' => 'Duplicate Phone',
                    'type' => 'text',
                ]);
                expect(false)->toBeTrue('Should have thrown exception');
            } catch (PropertyValidationException $e) {
                expect($e->getUserMessage())->toContain('already exists');
            }
        });
    });

    describe('HasProperties trait error handling', function () {
        it('propagates exceptions through trait methods', function () {
            expect(fn () => $this->user->setDynamicProperty('non_existent', 'value'))
                ->toThrow(PropertyNotFoundException::class);

            expect(fn () => $this->user->setDynamicProperty('phone', ''))
                ->toThrow(PropertyValidationException::class);
        });

        it('converts exceptions in magic methods', function () {
            expect(fn () => $this->user->prop_non_existent = 'value')
                ->toThrow(InvalidArgumentException::class);
        });
    });

    describe('Error message quality', function () {
        it('provides user-friendly messages with property labels', function () {
            try {
                $this->service->setDynamicProperty($this->user, 'phone', '123');
            } catch (PropertyValidationException $e) {
                $message = $e->getUserMessage();
                expect($message)->toContain('Phone Number'); // Uses label, not 'phone'
                expect($message)->not->toContain('phone'); // Doesn't use internal name
            }
        });

        it('provides structured error data for APIs', function () {
            try {
                $this->service->setDynamicProperty($this->user, 'phone', '');
            } catch (PropertyValidationException $e) {
                $array = $e->toArray();

                expect($array)->toHaveKey('error');
                expect($array)->toHaveKey('message');
                expect($array)->toHaveKey('context');
                expect($array)->toHaveKey('validation_errors');

                expect($array['error'])->toBe('PropertyValidationException');
                expect($array['context'])->toHaveKey('property_name');
                expect($array['context'])->toHaveKey('property_label');
                expect($array['context'])->toHaveKey('property_type');
            }
        });
    });

    describe('Successful operations', function () {
        it('allows valid property operations', function () {
            // These should all succeed without throwing exceptions
            $this->service->setDynamicProperty($this->user, 'phone', '1234567890');
            $this->service->setDynamicProperty($this->user, 'age', 25);
            $this->service->setDynamicProperty($this->user, 'status', 'active');
            $this->service->setDynamicProperty($this->user, 'birth_date', '1990-01-01');
            $this->service->setDynamicProperty($this->user, 'is_verified', true);

            // Verify values were set correctly
            expect($this->user->getDynamicProperty('phone'))->toBe('1234567890');
            expect($this->user->getDynamicProperty('age'))->toBe(25.0);
            expect($this->user->getDynamicProperty('status'))->toBe('active');
            expect($this->user->getDynamicProperty('is_verified'))->toBe(true);
        });

        it('allows batch property operations', function () {
            $properties = [
                'phone' => '1234567890',
                'age' => 30,
                'status' => 'active',
                'is_verified' => true,
            ];

            $this->service->setProperties($this->user, $properties);

            foreach ($properties as $name => $expectedValue) {
                $actualValue = $this->user->getDynamicProperty($name);
                if ($name === 'age') {
                    expect($actualValue)->toBe((float) $expectedValue);
                } else {
                    expect($actualValue)->toBe($expectedValue);
                }
            }
        });
    });
});
