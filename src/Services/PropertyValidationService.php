<?php

declare(strict_types=1);

namespace SolutionForest\LaravelDynamicProperties\Services;

use Carbon\Carbon;
use SolutionForest\LaravelDynamicProperties\Exceptions\InvalidPropertyTypeException;
use SolutionForest\LaravelDynamicProperties\Exceptions\PropertyValidationException;
use SolutionForest\LaravelDynamicProperties\Models\Property;

class PropertyValidationService
{
    /**
     * Valid property types.
     *
     * @var list<string>
     */
    public const VALID_TYPES = ['text', 'number', 'date', 'boolean', 'select'];

    /**
     * Validation rules accepted for every property type.
     *
     * @var list<string>
     */
    public const COMMON_RULES = ['required'];

    /**
     * Validation rules accepted for text and select properties.
     *
     * @var list<string>
     */
    public const TEXT_RULES = ['min', 'max', 'min_length', 'max_length', 'email', 'url'];

    /**
     * Validation rules accepted for number properties.
     *
     * @var list<string>
     */
    public const NUMBER_RULES = ['min', 'max', 'decimal_places', 'integer'];

    /**
     * Validation rules accepted for date properties.
     *
     * @var list<string>
     */
    public const DATE_RULES = ['after', 'before'];

    /**
     * Validate a property definition.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed> Keyed by field; an array of errors for `validation`.
     */
    public function validatePropertyDefinition(array $data): array
    {
        $errors = [];

        if (empty($data['name'])) {
            $errors['name'] = 'Property name is required.';
        } elseif (! is_string($data['name']) || ! preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/', $data['name'])) {
            $errors['name'] = 'Property name must start with a letter and contain only letters, numbers, and underscores.';
        }

        if (empty($data['label'])) {
            $errors['label'] = 'Property label is required.';
        }

        if (empty($data['type'])) {
            $errors['type'] = 'Property type is required.';
        } elseif (! is_string($data['type']) || ! in_array($data['type'], self::VALID_TYPES, true)) {
            $errors['type'] = 'Property type must be one of: '.implode(', ', self::VALID_TYPES);
        }

        if (! empty($data['type'])) {
            $errors = array_merge($errors, $this->validateTypeSpecificRequirements($data));
        }

        if (! empty($data['validation']) && is_array($data['validation'])) {
            $type = isset($data['type']) && is_string($data['type']) ? $data['type'] : null;
            $validationErrors = $this->validateValidationRules($data['validation'], $type);

            if ($validationErrors !== []) {
                $errors['validation'] = $validationErrors;
            }
        }

        return $errors;
    }

    /**
     * Validate type-specific requirements.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    private function validateTypeSpecificRequirements(array $data): array
    {
        $errors = [];

        if (($data['type'] ?? null) !== 'select') {
            return $errors;
        }

        $options = $data['options'] ?? null;

        if (! is_array($options) || $options === []) {
            $errors['options'] = 'Select properties must have at least one option.';

            return $errors;
        }

        foreach ($options as $index => $option) {
            if (! is_string($option) || trim($option) === '') {
                $errors['options'] = "Option at index {$index} must be a non-empty string.";

                break;
            }
        }

        return $errors;
    }

    /**
     * Validate validation rules for a property type.
     *
     * @param  array<int|string, mixed>  $rules
     * @return array<string, string>
     */
    private function validateValidationRules(array $rules, ?string $type): array
    {
        $errors = [];
        $normalized = $this->normalizeRules($rules);

        foreach ($normalized as $rule => $constraint) {
            if ($rule === 'required') {
                if (! is_bool($constraint)) {
                    $errors[$rule] = 'The required rule must be a boolean.';
                }

                continue;
            }

            if ($rule === 'email' || $rule === 'url' || $rule === 'integer') {
                if ($type !== 'text' && $rule !== 'integer') {
                    $errors[$rule] = "{$rule} validation is only supported for text properties.";
                } elseif ($type !== 'number' && $rule === 'integer') {
                    $errors[$rule] = 'integer validation is only supported for number properties.';
                } elseif (! is_bool($constraint)) {
                    $errors[$rule] = "{$rule} must be a boolean.";
                }

                continue;
            }

            if ($rule === 'min' || $rule === 'max') {
                if ($type === 'text') {
                    if (! is_int($constraint) || $constraint < 0) {
                        $errors[$rule] = "Text {$rule} length must be a non-negative integer.";
                    }
                } elseif ($type === 'number') {
                    if (! is_numeric($constraint)) {
                        $errors[$rule] = "Number {$rule} value must be numeric.";
                    }
                } else {
                    $errors[$rule] = "{$rule} validation is only supported for text and number properties.";
                }

                continue;
            }

            if ($rule === 'min_length' || $rule === 'max_length') {
                if ($type !== 'text') {
                    $errors[$rule] = "{$rule} validation is only supported for text properties.";
                } elseif (! is_int($constraint) || $constraint < 0) {
                    $errors[$rule] = "{$rule} must be a non-negative integer.";
                }

                continue;
            }

            if ($rule === 'decimal_places') {
                if ($type !== 'number') {
                    $errors[$rule] = 'decimal_places validation is only supported for number properties.';
                } elseif (! is_int($constraint) || $constraint < 0) {
                    $errors[$rule] = 'decimal_places must be a non-negative integer.';
                }

                continue;
            }

            if ($rule === 'after' || $rule === 'before') {
                if ($type !== 'date') {
                    $errors[$rule] = "{$rule} validation is only supported for date properties.";
                } elseif (! is_string($constraint) || ($constraint !== 'today' && strtotime($constraint) === false)) {
                    $errors[$rule] = "{$rule} must be 'today' or a valid date string.";
                }

                continue;
            }

            $errors[$rule] = "Unknown validation rule: {$rule}";
        }

        if (isset($normalized['min'], $normalized['max']) && is_numeric($normalized['min']) && is_numeric($normalized['max'])) {
            if ($normalized['min'] > $normalized['max']) {
                $errors['min'] = 'Minimum value cannot be greater than maximum value.';
            }
        }

        if (isset($normalized['min_length'], $normalized['max_length'])
            && is_numeric($normalized['min_length'])
            && is_numeric($normalized['max_length'])
            && $normalized['min_length'] > $normalized['max_length']) {
            $errors['min_length'] = 'Minimum length cannot be greater than maximum length.';
        }

        return $errors;
    }

    /**
     * Validate a property value against a property definition.
     *
     * @throws PropertyValidationException
     */
    public function validatePropertyValue(Property $property, mixed $value): void
    {
        $rules = $this->normalizeRules(is_array($property->validation) ? $property->validation : []);
        $required = $property->required || ($rules['required'] ?? false) === true;

        if ($required && $this->isEmpty($value)) {
            throw new PropertyValidationException(
                (string) $property->name,
                $value,
                ["The {$property->label} field is required."],
                $property
            );
        }

        if (! $required && $this->isEmpty($value)) {
            if ($value === '' && $property->type === 'select') {
                throw new PropertyValidationException(
                    (string) $property->name,
                    $value,
                    ["The {$property->label} must have a value selected."],
                    $property
                );
            }

            return;
        }

        $errors = [];
        $typeError = $this->validateType($property, $value);

        if ($typeError !== null) {
            $errors[] = $typeError;
        } else {
            $errors = $this->validateAgainstRules($property, $value, $rules);
        }

        if ($errors !== []) {
            throw new PropertyValidationException((string) $property->name, $value, $errors, $property);
        }
    }

    /**
     * Check if a value is considered empty.
     */
    private function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '';
    }

    /**
     * Validate value type.
     */
    private function validateType(Property $property, mixed $value): ?string
    {
        $label = $property->label;

        switch ($property->type) {
            case 'text':
                if (! is_string($value) && ! is_numeric($value)) {
                    return "The {$label} must be text.";
                }

                break;

            case 'number':
                if (! is_numeric($value)) {
                    return "The {$label} must be a number.";
                }

                break;

            case 'date':
                if (! Property::isValidDate($value)) {
                    return "The {$label} must be a valid date.";
                }

                break;

            case 'boolean':
                if (! Property::isValidBoolean($value)) {
                    return "The {$label} must be true or false.";
                }

                break;

            case 'select':
                if ($this->isEmpty($value)) {
                    return "The {$label} must have a value selected.";
                }

                if (! in_array($value, $property->options ?? [], true)) {
                    $options = implode(', ', $property->options ?? []);

                    return "The {$label} must be one of: {$options}.";
                }

                break;

            default:
                throw new InvalidPropertyTypeException((string) $property->type, self::VALID_TYPES, [
                    'property_name' => $property->name,
                ]);
        }

        return null;
    }

    /**
     * Validate against custom validation rules.
     *
     * @param  array<string, mixed>  $rules
     * @return list<string>
     */
    private function validateAgainstRules(Property $property, mixed $value, array $rules): array
    {
        $errors = [];
        $label = $property->label;
        $text = Property::castText($value);

        foreach ($rules as $rule => $constraint) {
            switch ($rule) {
                case 'required':
                    if ($constraint === true && $this->isEmpty($value)) {
                        $errors[] = "The {$label} field is required.";
                    }

                    break;

                case 'min':
                    $limit = $this->numericValue($constraint);

                    if ($limit === null) {
                        break;
                    }

                    if ($property->type === 'text') {
                        if (strlen($text) < (int) $limit) {
                            $errors[] = "The {$label} must be at least {$limit} characters minimum.";
                        }
                    } elseif ($property->type === 'number') {
                        if ($this->numericValue($value) !== null && $this->numericValue($value) < $limit) {
                            $errors[] = "The {$label} must be at least {$limit}.";
                        }
                    }

                    break;

                case 'max':
                    $limit = $this->numericValue($constraint);

                    if ($limit === null) {
                        break;
                    }

                    if ($property->type === 'text') {
                        if (strlen($text) > (int) $limit) {
                            $errors[] = "The {$label} may not be greater than {$limit} characters maximum.";
                        }
                    } elseif ($property->type === 'number') {
                        if ($this->numericValue($value) !== null && $this->numericValue($value) > $limit) {
                            $errors[] = "The {$label} may not be greater than {$limit}.";
                        }
                    }

                    break;

                case 'min_length':
                    $limit = $this->numericValue($constraint);

                    if ($limit !== null && strlen($text) < (int) $limit) {
                        $errors[] = "The {$label} must be at least {$limit} characters.";
                    }

                    break;

                case 'max_length':
                    $limit = $this->numericValue($constraint);

                    if ($limit !== null && strlen($text) > (int) $limit) {
                        $errors[] = "The {$label} may not be greater than {$limit} characters.";
                    }

                    break;

                case 'email':
                    if ($constraint === true && filter_var($text, FILTER_VALIDATE_EMAIL) === false) {
                        $errors[] = "The {$label} must be a valid email address.";
                    }

                    break;

                case 'url':
                    if ($constraint === true && filter_var($text, FILTER_VALIDATE_URL) === false) {
                        $errors[] = "The {$label} must be a valid URL.";
                    }

                    break;

                case 'integer':
                    $number = $this->numericValue($value);

                    if ($constraint === true && $number !== null && $number !== (float) (int) $number) {
                        $errors[] = "The {$label} must be an integer.";
                    }

                    break;

                case 'decimal_places':
                    $limit = $this->numericValue($constraint);

                    if ($limit !== null && $this->countDecimalPlaces($text) > $limit) {
                        $errors[] = "The {$label} may not have more than {$limit} decimal places.";
                    }

                    break;

                case 'after':
                    $date = Property::castDate($value);
                    $constraintDate = $this->constraintDate($constraint);

                    if ($date !== null && $constraintDate !== null && ! $date->isAfter($constraintDate)) {
                        $errors[] = "The {$label} must be after {$this->displayConstraint($constraint)}.";
                    }

                    break;

                case 'before':
                    $date = Property::castDate($value);
                    $constraintDate = $this->constraintDate($constraint);

                    if ($date !== null && $constraintDate !== null && ! $date->isBefore($constraintDate)) {
                        $errors[] = "The {$label} must be before {$this->displayConstraint($constraint)}.";
                    }

                    break;
            }
        }

        return $errors;
    }

    /**
     * Normalise both map-style (`['min' => 1]`) and list-style (`['required']`)
     * rule declarations into a single map.
     *
     * @param  array<int|string, mixed>  $rules
     * @return array<string, mixed>
     */
    private function normalizeRules(array $rules): array
    {
        $normalized = [];

        foreach ($rules as $key => $value) {
            if (is_int($key)) {
                if (is_string($value)) {
                    $normalized[$value] = true;
                }

                continue;
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }

    /**
     * Narrow a rule constraint to a number when possible.
     */
    private function numericValue(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * Resolve a before/after constraint into a Carbon instance.
     */
    private function constraintDate(mixed $constraint): ?Carbon
    {
        if ($constraint === 'today') {
            return Carbon::today();
        }

        if (! is_string($constraint)) {
            return null;
        }

        return Property::castDate($constraint);
    }

    private function displayConstraint(mixed $constraint): string
    {
        return is_scalar($constraint) ? (string) $constraint : 'the configured date';
    }

    /**
     * Number of digits after the decimal separator.
     */
    private function countDecimalPlaces(string $value): int
    {
        if (! str_contains($value, '.')) {
            return 0;
        }

        return strlen(rtrim(substr($value, strpos($value, '.') + 1), '0'));
    }
}
