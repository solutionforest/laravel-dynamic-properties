<?php

use DynamicProperties\Exceptions\PropertyNotFoundException;
use DynamicProperties\Exceptions\PropertyOperationException;
use DynamicProperties\Exceptions\PropertyValidationException;
use DynamicProperties\Models\Property;
use DynamicProperties\Services\PropertyService;
use Illuminate\Database\Eloquent\Model;

class TestModel extends Model
{
    use \DynamicProperties\Traits\HasProperties;

    protected $table = 'test_models';

    protected $fillable = ['name'];
}

beforeEach(function () {
    // Create test table
    Schema::create('test_models', function ($table) {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });

    $this->service = new PropertyService;
    $this->model = TestModel::create(['name' => 'Test']);

    // Create a test property
    $this->property = Property::create([
        'name' => 'test_prop',
        'label' => 'Test Property',
        'type' => 'text',
        'required' => true,
        'validation' => ['min' => 3],
    ]);
});

describe('PropertyService Error Handling', function () {
    it('throws PropertyNotFoundException for non-existent property', function () {
        expect(fn () => $this->service->setDynamicProperty($this->model, 'non_existent', 'value'))
            ->toThrow(PropertyNotFoundException::class);
    });

    it('throws PropertyValidationException for invalid values', function () {
        expect(fn () => $this->service->setDynamicProperty($this->model, 'test_prop', ''))
            ->toThrow(PropertyValidationException::class);

        expect(fn () => $this->service->setDynamicProperty($this->model, 'test_prop', 'ab'))
            ->toThrow(PropertyValidationException::class);
    });

    it('throws PropertyOperationException for unsaved entity', function () {
        $unsavedModel = new TestModel(['name' => 'Unsaved']);

        expect(fn () => $this->service->setDynamicProperty($unsavedModel, 'test_prop', 'value'))
            ->toThrow(PropertyOperationException::class);
    });

    it('provides detailed error context', function () {
        try {
            $this->service->setDynamicProperty($this->model, 'non_existent', 'value');
            expect(false)->toBeTrue('Should have thrown exception');
        } catch (PropertyNotFoundException $e) {
            expect($e->getContext())->toHaveKey('property_name');
            expect($e->getContext()['property_name'])->toBe('non_existent');
            expect($e->getUserMessage())->toContain('does not exist');
        }
    });

    it('validates property creation', function () {
        expect(fn () => $this->service->createProperty([]))
            ->toThrow(PropertyValidationException::class);
    });
});
