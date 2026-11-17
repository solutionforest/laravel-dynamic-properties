<?php

declare(strict_types=1);

namespace SolutionForest\LaravelDynamicProperties\Exceptions;

/**
 * Exception thrown when an invalid property type is used.
 */
class InvalidPropertyTypeException extends PropertyException
{
    /**
     * @param  list<string>  $validTypes
     * @param  array<string, mixed>  $context
     */
    public function __construct(string $type, array $validTypes = [], array $context = [])
    {
        $message = "Invalid property type '{$type}'.";

        if ($validTypes !== []) {
            $message .= ' Valid types are: '.implode(', ', $validTypes);
        }

        parent::__construct($message, 400, null, array_merge([
            'invalid_type' => $type,
            'valid_types'  => $validTypes,
        ], $context));
    }

    public function getUserMessage(): string
    {
        $invalidType = $this->context['invalid_type'] ?? 'unknown';
        $type = is_string($invalidType) ? $invalidType : 'unknown';
        $validTypes = $this->context['valid_types'] ?? [];
        $types = is_array($validTypes) ? array_map(static fn (mixed $value): string => is_string($value) ? $value : '', $validTypes) : [];

        $message = "The property type '{$type}' is not supported.";

        if ($types !== []) {
            $message .= ' Supported types are: '.implode(', ', $types).'.';
        }

        return $message;
    }
}
