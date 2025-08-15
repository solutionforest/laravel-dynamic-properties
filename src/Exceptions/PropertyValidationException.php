<?php

namespace SolutionForest\LaravelDynamicProperties\Exceptions;

use SolutionForest\LaravelDynamicProperties\Models\Property;

/**
 * Exception thrown when property value validation fails
 */
class PropertyValidationException extends PropertyException
{
    protected array $validationErrors = [];

    public function __construct(string $propertyName, mixed $value, array $validationErrors = [], ?Property $property = null, array $context = [])
    {
        $this->validationErrors = $validationErrors;

        $message = "Validation failed for property '{$propertyName}'.";
        if (! empty($validationErrors)) {
            $errorStrings = [];
            foreach ($validationErrors as $key => $error) {
                if (is_array($error)) {
                    $errorStrings[] = $key.': '.implode(', ', $error);
                } else {
                    $errorStrings[] = is_string($key) ? $key.': '.$error : $error;
                }
            }
            $message .= ' Errors: '.implode(', ', $errorStrings);
        }

        parent::__construct($message, 422, null, array_merge([
            'property_name'     => $propertyName,
            'value'             => $value,
            'validation_errors' => $validationErrors,
            'property_type'     => $property?->type,
            'property_label'    => $property?->label,
        ], $context));
    }

    /**
     * Get validation errors
     */
    public function getValidationErrors(): array
    {
        return $this->validationErrors;
    }

    /**
     * Add a validation error
     */
    public function addValidationError(string $error): self
    {
        $this->validationErrors[] = $error;
        $this->context['validation_errors'] = $this->validationErrors;

        return $this;
    }

    public function getUserMessage(): string
    {
        $propertyLabel = $this->context['property_label'] ?? $this->context['property_name'] ?? 'property';

        if (! empty($this->validationErrors)) {
            return "Validation failed for {$propertyLabel}: ".implode(', ', $this->validationErrors);
        }

        return "The value provided for {$propertyLabel} is not valid.";
    }

    public function toArray(): array
    {
        return array_merge(parent::toArray(), [
            'validation_errors' => $this->validationErrors,
        ]);
    }
}
