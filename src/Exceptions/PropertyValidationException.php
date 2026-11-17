<?php

declare(strict_types=1);

namespace SolutionForest\LaravelDynamicProperties\Exceptions;

use SolutionForest\LaravelDynamicProperties\Models\Property;

/**
 * Exception thrown when property value validation fails.
 */
class PropertyValidationException extends PropertyException
{
    /**
     * @var array<int|string, mixed>
     */
    protected array $validationErrors = [];

    /**
     * @param  array<int|string, mixed>  $validationErrors
     * @param  array<string, mixed>  $context
     */
    public function __construct(string $propertyName, mixed $value, array $validationErrors = [], ?Property $property = null, array $context = [])
    {
        $this->validationErrors = $validationErrors;

        $message = "Validation failed for property '{$propertyName}'.";

        if ($validationErrors !== []) {
            $message .= ' Errors: '.implode(', ', $this->normaliseErrors($validationErrors));
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
     * @return array<int|string, mixed>
     */
    public function getValidationErrors(): array
    {
        return $this->validationErrors;
    }

    public function addValidationError(string $error): self
    {
        $this->validationErrors[] = $error;
        $this->context['validation_errors'] = $this->validationErrors;

        return $this;
    }

    public function getUserMessage(): string
    {
        $label = $this->context['property_label'] ?? $this->context['property_name'] ?? 'property';
        $name = is_string($label) ? $label : 'property';

        if ($this->validationErrors !== []) {
            return "Validation failed for {$name}: ".implode(', ', $this->normaliseErrors($this->validationErrors));
        }

        return "The value provided for {$name} is not valid.";
    }

    /**
     * @return array{error: string, message: string, context: array<string, mixed>, validation_errors: array<int|string, mixed>}
     */
    public function toArray(): array
    {
        return array_merge(parent::toArray(), [
            'validation_errors' => $this->validationErrors,
        ]);
    }

    /**
     * @param  array<int|string, mixed>  $errors
     * @return list<string>
     */
    private function normaliseErrors(array $errors): array
    {
        $normalised = [];

        foreach ($errors as $key => $error) {
            $text = match (true) {
                is_array($error) => implode(', ', array_map(
                    static fn (mixed $value): string => is_scalar($value) ? (string) $value : '',
                    $error
                )),
                is_scalar($error) => (string) $error,
                default           => '',
            };

            $normalised[] = is_string($key) ? "{$key}: {$text}" : $text;
        }

        return $normalised;
    }
}
