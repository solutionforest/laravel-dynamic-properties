<?php

namespace DynamicProperties\Services;

use DynamicProperties\Models\Property;
use DynamicProperties\Exceptions\PropertyValidationException;
use DynamicProperties\Exceptions\InvalidPropertyTypeException;

class PropertyValidationService
{
    /**
     * Valid property types
     */
    public const VALID_TYPES = ['text', 'number', 'date', 'boolean', 'select'];

    /**
     * Validate a property definition
     */
    public function validatePropertyDefinition(array $data): array
    {
        $errors = [];

        // Validate required fields
        if (empty($data['name'])) {
            $errors['name'] = 'Property name is required.';
        } elseif (!is_string($data['name']) || !preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/', $data['name'])) {
            $errors['name'] = 'Property name must start with a letter and contain only letters, numbers, and underscores.';
        }

        if (empty($data['label'])) {
            $errors['label'] = 'Property label is required.';
        }

        if (empty($data['type'])) {
            $errors['type'] = 'Property type is required.';
        } elseif (!in_array($data['type'], self::VALID_TYPES)) {
            $errors['type'] = 'Property type must be one of: ' . implode(', ', self::VALID_TYPES);
        }

        // Validate type-specific requirements
        if (!empty($data['type'])) {
            $typeErrors = $this->validateTypeSpecificRequirements($data);
            $errors = array_merge($errors, $typeErrors);
        }

        // Validate validation rules
        if (!empty($data['validation']) && is_array($data['validation'])) {
            $validationErrors = $this->validateValidationRules($data['validation'], $data['type'] ?? null);
            if (!empty($validationErrors)) {
                $errors['validation'] = $validationErrors;
            }
        }

        return $errors;
    }

    /**
     * Validate type-specific requirements
     */
    private function validateTypeSpecificRequirements(array $data): array
    {
        $errors = [];
        $type = $data['type'];

        switch ($type) {
            case 'select':
                if (empty($data['options']) || !is_array($data['options'])) {
                    $errors['options'] = 'Select properties must have at least one option.';
                } elseif (count($data['options']) === 0) {
                    $errors['options'] = 'Select properties must have at least one option.';
                } else {
                    // Validate that all options are strings
                    foreach ($data['options'] as $index => $option) {
                        if (!is_string($option) || trim($option) === '') {
                            $errors['options'] = "Option at index {$index} must be a non-empty string.";
                            break;
                        }
                    }
                }
                break;
        }

        return $errors;
    }

    /**
     * Validate validation rules for a property type
     */
    private function validateValidationRules(array $rules, ?string $type): array
    {
        $errors = [];

        foreach ($rules as $rule => $value) {
            switch ($rule) {
                case 'min':
                case 'max':
                    if ($type === 'text') {
                        if (!is_int($value) || $value < 0) {
                            $errors[$rule] = "Text {$rule} length must be a non-negative integer.";
                        }
                    } elseif ($type === 'number') {
                        if (!is_numeric($value)) {
                            $errors[$rule] = "Number {$rule} value must be numeric.";
                        }
                    } else {
                        $errors[$rule] = "{$rule} validation is only supported for text and number properties.";
                    }
                    break;

                case 'min_length':
                case 'max_length':
                    if ($type !== 'text') {
                        $errors[$rule] = "{$rule} validation is only supported for text properties.";
                    } elseif (!is_int($value) || $value < 0) {
                        $errors[$rule] = "{$rule} must be a non-negative integer.";
                    }
                    break;

                case 'after':
                case 'before':
                    if ($type !== 'date') {
                        $errors[$rule] = "{$rule} validation is only supported for date properties.";
                    } elseif (!is_string($value) || ($value !== 'today' && strtotime($value) === false)) {
                        $errors[$rule] = "{$rule} must be 'today' or a valid date string.";
                    }
                    break;

                default:
                    $errors[$rule] = "Unknown validation rule: {$rule}";
                    break;
            }
        }

        // Validate min/max relationships
        if (isset($rules['min']) && isset($rules['max'])) {
            if ($rules['min'] > $rules['max']) {
                $errors['min'] = 'Minimum value cannot be greater than maximum value.';
            }
        }

        if (isset($rules['min_length']) && isset($rules['max_length'])) {
            if ($rules['min_length'] > $rules['max_length']) {
                $errors['min_length'] = 'Minimum length cannot be greater than maximum length.';
            }
        }

        return $errors;
    }

    /**
     * Validate a property value against a property definition
     */
    public function validatePropertyValue(Property $property, mixed $value): void
    {
        $errors = [];

        // Check if required property has a value
        if ($property->required && $this->isEmpty($value)) {
            throw new PropertyValidationException(
                $property->name,
                $value,
                ["The {$property->label} field is required."],
                $property
            );
        }

        // Allow null/empty values for non-required properties
        if (!$property->required && $this->isEmpty($value)) {
            return;
        }

        // Type validation
        $typeError = $this->validateType($property, $value);
        if ($typeError) {
            $errors[] = $typeError;
        }

        // Custom validation rules
        if ($property->validation && is_array($property->validation)) {
            $ruleErrors = $this->validateAgainstRules($property, $value, $property->validation);
            $errors = array_merge($errors, $ruleErrors);
        }

        if (!empty($errors)) {
            throw new PropertyValidationException($property->name, $value, $errors, $property);
        }
    }

    /**
     * Check if a value is considered empty
     */
    private function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '';
    }

    /**
     * Validate value type
     */
    private function validateType(Property $property, mixed $value): ?string
    {
        $label = $property->label;

        switch ($property->type) {
            case 'text':
                if (!is_string($value) && !is_numeric($value)) {
                    return "The {$label} must be text.";
                }
                break;

            case 'number':
                if (!is_numeric($value)) {
                    return "The {$label} must be a number.";
                }
                break;

            case 'date':
                if (!$this->isValidDate($value)) {
                    return "The {$label} must be a valid date.";
                }
                break;

            case 'boolean':
                if (!$this->isValidBoolean($value)) {
                    return "The {$label} must be true or false.";
                }
                break;

            case 'select':
                if ($this->isEmpty($value)) {
                    return "The {$label} must have a value selected.";
                }
                if (!in_array($value, $property->options ?? [])) {
                    $options = implode(', ', $property->options ?? []);
                    return "The {$label} must be one of: {$options}.";
                }
                break;

            default:
                throw new InvalidPropertyTypeException($property->type, self::VALID_TYPES, [
                    'property_name' => $property->name,
                ]);
        }

        return null;
    }

    /**
     * Validate against custom validation rules
     */
    private function validateAgainstRules(Property $property, mixed $value, array $rules): array
    {
        $errors = [];
        $label = $property->label;

        foreach ($rules as $rule => $constraint) {
            switch ($rule) {
                case 'min':
                    if ($property->type === 'text') {
                        if (strlen((string)$value) < $constraint) {
                            $errors[] = "The {$label} must be at least {$constraint} characters.";
                        }
                    } elseif ($property->type === 'number') {
                        if ((float)$value < $constraint) {
                            $errors[] = "The {$label} must be at least {$constraint}.";
                        }
                    }
                    break;

                case 'max':
                    if ($property->type === 'text') {
                        if (strlen((string)$value) > $constraint) {
                            $errors[] = "The {$label} may not be greater than {$constraint} characters.";
                        }
                    } elseif ($property->type === 'number') {
                        if ((float)$value > $constraint) {
                            $errors[] = "The {$label} may not be greater than {$constraint}.";
                        }
                    }
                    break;

                case 'min_length':
                    if (strlen((string)$value) < $constraint) {
                        $errors[] = "The {$label} must be at least {$constraint} characters.";
                    }
                    break;

                case 'max_length':
                    if (strlen((string)$value) > $constraint) {
                        $errors[] = "The {$label} may not be greater than {$constraint} characters.";
                    }
                    break;

                case 'after':
                    if (!$this->isDateAfter($value, $constraint)) {
                        $constraintText = $constraint === 'today' ? 'today' : $constraint;
                        $errors[] = "The {$label} must be after {$constraintText}.";
                    }
                    break;

                case 'before':
                    if (!$this->isDateBefore($value, $constraint)) {
                        $constraintText = $constraint === 'today' ? 'today' : $constraint;
                        $errors[] = "The {$label} must be before {$constraintText}.";
                    }
                    break;
            }
        }

        return $errors;
    }

    /**
     * Check if a value is a valid date
     */
    private function isValidDate(mixed $value): bool
    {
        if ($value instanceof \DateTime || $value instanceof \DateTimeInterface) {
            return true;
        }

        if (is_string($value)) {
            return strtotime($value) !== false;
        }

        return false;
    }

    /**
     * Check if a value is a valid boolean
     */
    private function isValidBoolean(mixed $value): bool
    {
        return is_bool($value) || 
               $value === 1 || $value === 0 || 
               $value === '1' || $value === '0' ||
               strtolower($value) === 'true' || strtolower($value) === 'false';
    }

    /**
     * Check if date is after constraint
     */
    private function isDateAfter(mixed $value, string $constraint): bool
    {
        try {
            $date = \Carbon\Carbon::parse($value);
            $constraintDate = $constraint === 'today' ? \Carbon\Carbon::today() : \Carbon\Carbon::parse($constraint);
            return $date->isAfter($constraintDate);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Check if date is before constraint
     */
    private function isDateBefore(mixed $value, string $constraint): bool
    {
        try {
            $date = \Carbon\Carbon::parse($value);
            $constraintDate = $constraint === 'today' ? \Carbon\Carbon::today() : \Carbon\Carbon::parse($constraint);
            return $date->isBefore($constraintDate);
        } catch (\Exception $e) {
            return false;
        }
    }
}