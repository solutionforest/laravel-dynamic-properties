<?php

namespace DynamicProperties\Exceptions;

/**
 * Exception thrown when an invalid property type is used
 */
class InvalidPropertyTypeException extends PropertyException
{
    public function __construct(string $type, array $validTypes = [], array $context = [])
    {
        $message = "Invalid property type '{$type}'.";
        if (! empty($validTypes)) {
            $message .= ' Valid types are: '.implode(', ', $validTypes);
        }

        parent::__construct($message, 400, null, array_merge([
            'invalid_type' => $type,
            'valid_types' => $validTypes,
        ], $context));
    }

    public function getUserMessage(): string
    {
        $invalidType = $this->context['invalid_type'] ?? 'unknown';
        $validTypes = $this->context['valid_types'] ?? [];

        $message = "The property type '{$invalidType}' is not supported.";
        if (! empty($validTypes)) {
            $message .= ' Supported types are: '.implode(', ', $validTypes).'.';
        }

        return $message;
    }
}
