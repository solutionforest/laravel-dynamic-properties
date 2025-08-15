<?php

namespace SolutionForest\LaravelDynamicProperties\Exceptions;

/**
 * Exception thrown when trying to access a property that doesn't exist
 */
class PropertyNotFoundException extends PropertyException
{
    public function __construct(string $propertyName, array $context = [])
    {
        $message = "Property '{$propertyName}' does not exist.";

        parent::__construct($message, 404, null, array_merge([
            'property_name' => $propertyName,
        ], $context));
    }

    public function getUserMessage(): string
    {
        $propertyName = $this->context['property_name'] ?? 'unknown';

        return "The property '{$propertyName}' does not exist. Please check the property name and try again.";
    }
}
