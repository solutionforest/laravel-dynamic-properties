<?php

declare(strict_types=1);

namespace SolutionForest\LaravelDynamicProperties\Exceptions;

/**
 * Exception thrown when trying to access a property that doesn't exist.
 */
class PropertyNotFoundException extends PropertyException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(string $propertyName, array $context = [])
    {
        parent::__construct("Property '{$propertyName}' does not exist.", 404, null, array_merge([
            'property_name' => $propertyName,
        ], $context));
    }

    public function getUserMessage(): string
    {
        $propertyName = $this->context['property_name'] ?? 'unknown';
        $name = is_string($propertyName) ? $propertyName : 'unknown';

        return "The property '{$name}' does not exist. Please check the property name and try again.";
    }
}
