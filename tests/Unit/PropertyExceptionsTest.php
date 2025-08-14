<?php

use DynamicProperties\Exceptions\PropertyException;
use DynamicProperties\Exceptions\PropertyNotFoundException;
use DynamicProperties\Exceptions\PropertyValidationException;
use DynamicProperties\Exceptions\PropertyOperationException;
use DynamicProperties\Exceptions\InvalidPropertyTypeException;
use DynamicProperties\Models\Property;

describe('Property Exceptions', function () {
    describe('PropertyException', function () {
        it('creates base exception with context', function () {
            $context = ['key' => 'value'];
            $exception = new PropertyException('Test message', 400, null, $context);
            
            expect($exception->getMessage())->toBe('Test message');
            expect($exception->getCode())->toBe(400);
            expect($exception->getContext())->toBe($context);
            expect($exception->getUserMessage())->toBe('Test message');
        });

        it('converts to array format', function () {
            $exception = new PropertyException('Test message', 400, null, ['key' => 'value']);
            $array = $exception->toArray();
            
            expect($array)->toHaveKey('error');
            expect($array)->toHaveKey('message');
            expect($array)->toHaveKey('context');
            expect($array['error'])->toBe('PropertyException');
            expect($array['message'])->toBe('Test message');
            expect($array['context'])->toBe(['key' => 'value']);
        });
    });

    describe('PropertyNotFoundException', function () {
        it('creates exception with property name', function () {
            $exception = new PropertyNotFoundException('test_property');
            
            expect($exception->getMessage())->toContain('test_property');
            expect($exception->getCode())->toBe(404);
            expect($exception->getContext())->toHaveKey('property_name');
            expect($exception->getContext()['property_name'])->toBe('test_property');
        });

        it('provides user-friendly message', function () {
            $exception = new PropertyNotFoundException('test_property');
            $userMessage = $exception->getUserMessage();
            
            expect($userMessage)->toContain('test_property');
            expect($userMessage)->toContain('does not exist');
            expect($userMessage)->toContain('check the property name');
        });
    });

    describe('PropertyValidationException', function () {
        it('creates exception with validation errors', function () {
            $errors = ['Field is required', 'Value is too long'];
            $property = new Property(['name' => 'test', 'label' => 'Test Field', 'type' => 'text']);
            
            $exception = new PropertyValidationException('test', 'invalid_value', $errors, $property);
            
            expect($exception->getValidationErrors())->toBe($errors);
            expect($exception->getContext())->toHaveKey('property_name');
            expect($exception->getContext())->toHaveKey('property_label');
            expect($exception->getContext())->toHaveKey('validation_errors');
        });

        it('adds validation errors', function () {
            $exception = new PropertyValidationException('test', 'value', ['Initial error']);
            $exception->addValidationError('Additional error');
            
            $errors = $exception->getValidationErrors();
            expect($errors)->toHaveCount(2);
            expect($errors)->toContain('Initial error');
            expect($errors)->toContain('Additional error');
        });

        it('provides user-friendly message with property label', function () {
            $property = new Property(['name' => 'test', 'label' => 'Test Field', 'type' => 'text']);
            $exception = new PropertyValidationException('test', 'value', ['Field is required'], $property);
            
            $userMessage = $exception->getUserMessage();
            expect($userMessage)->toContain('Test Field');
            expect($userMessage)->toContain('Field is required');
        });

        it('includes validation errors in array format', function () {
            $errors = ['Error 1', 'Error 2'];
            $exception = new PropertyValidationException('test', 'value', $errors);
            $array = $exception->toArray();
            
            expect($array)->toHaveKey('validation_errors');
            expect($array['validation_errors'])->toBe($errors);
        });
    });

    describe('PropertyOperationException', function () {
        it('creates exception with operation details', function () {
            $exception = new PropertyOperationException('set property', 'Database connection failed');
            
            expect($exception->getMessage())->toContain('set property');
            expect($exception->getMessage())->toContain('Database connection failed');
            expect($exception->getCode())->toBe(500);
            expect($exception->getContext())->toHaveKey('operation');
            expect($exception->getContext())->toHaveKey('reason');
        });

        it('provides user-friendly message', function () {
            $exception = new PropertyOperationException('set property', 'Database error');
            $userMessage = $exception->getUserMessage();
            
            expect($userMessage)->toContain('property set property');
            expect($userMessage)->toContain('could not be completed');
            expect($userMessage)->toContain('try again later');
        });
    });

    describe('InvalidPropertyTypeException', function () {
        it('creates exception with type information', function () {
            $validTypes = ['text', 'number', 'date'];
            $exception = new InvalidPropertyTypeException('invalid_type', $validTypes);
            
            expect($exception->getMessage())->toContain('invalid_type');
            expect($exception->getCode())->toBe(400);
            expect($exception->getContext())->toHaveKey('invalid_type');
            expect($exception->getContext())->toHaveKey('valid_types');
            expect($exception->getContext()['valid_types'])->toBe($validTypes);
        });

        it('provides user-friendly message with valid types', function () {
            $validTypes = ['text', 'number', 'date'];
            $exception = new InvalidPropertyTypeException('invalid_type', $validTypes);
            $userMessage = $exception->getUserMessage();
            
            expect($userMessage)->toContain('invalid_type');
            expect($userMessage)->toContain('not supported');
            expect($userMessage)->toContain('text, number, date');
        });
    });
});