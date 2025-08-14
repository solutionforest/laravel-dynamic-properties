<?php

namespace DynamicProperties\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Property extends Model
{
    protected $fillable = [
        'name',
        'label', 
        'type',
        'required',
        'options',
        'validation'
    ];

    protected $casts = [
        'required' => 'boolean',
        'options' => 'array',
        'validation' => 'array',
    ];

    /**
     * Get all entity properties for this property definition
     */
    public function entityProperties(): HasMany
    {
        return $this->hasMany(EntityProperty::class);
    }

    /**
     * Validate a value against this property's type and validation rules
     */
    public function validateValue(mixed $value): bool
    {
        // Handle required validation
        if ($this->required && ($value === null || $value === '')) {
            return false;
        }

        // Allow null for optional fields, but not empty string for select types
        if (!$this->required && $value === null) {
            return true;
        }

        // For select types, empty string is not valid even if optional
        if (!$this->required && $value === '' && $this->type === 'select') {
            return false;
        }

        // Allow empty string for other optional field types
        if (!$this->required && $value === '' && $this->type !== 'select') {
            return true;
        }

        // Type validation
        $typeValid = match($this->type) {
            'text' => is_string($value) || is_numeric($value),
            'number' => is_numeric($value),
            'date' => $this->isValidDate($value),
            'boolean' => $this->isValidBoolean($value),
            'select' => $value !== '' && $value !== null && in_array($value, $this->options ?? []),
            default => false
        };

        if (!$typeValid) {
            return false;
        }

        // Additional validation rules
        if ($this->validation) {
            return $this->validateAgainstRules($value, $this->validation);
        }

        return true;
    }

    /**
     * Cast a value to the appropriate type for this property
     */
    public function castValue(mixed $value): mixed
    {
        return match($this->type) {
            'text', 'select' => (string) $value,
            'number' => is_numeric($value) ? (float) $value : $value,
            'date' => $this->castToDate($value),
            'boolean' => $this->castToBoolean($value),
            default => $value
        };
    }

    /**
     * Get validation error message for a value
     */
    public function getValidationError(mixed $value): string
    {
        if ($this->required && ($value === null || $value === '')) {
            return "The {$this->label} field is required.";
        }

        if ($this->type === 'text' && $this->validation) {
            if (isset($this->validation['min']) && strlen($value) < $this->validation['min']) {
                return "The {$this->label} must be at least {$this->validation['min']} characters minimum.";
            }
            if (isset($this->validation['max']) && strlen($value) > $this->validation['max']) {
                return "The {$this->label} may not be greater than {$this->validation['max']} characters maximum.";
            }
        }

        if ($this->type === 'number' && $this->validation) {
            if (isset($this->validation['min']) && $value < $this->validation['min']) {
                return "The {$this->label} must be at least {$this->validation['min']}.";
            }
            if (isset($this->validation['max']) && $value > $this->validation['max']) {
                return "The {$this->label} may not be greater than {$this->validation['max']}.";
            }
        }

        return "The {$this->label} field is invalid.";
    }

    /**
     * Check if a value is a valid boolean
     */
    private function isValidBoolean(mixed $value): bool
    {
        return is_bool($value) || 
               $value === 1 || $value === 0 || 
               $value === '1' || $value === '0';
    }

    /**
     * Cast value to boolean
     */
    private function castToBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        
        return in_array($value, [1, '1', true], true);
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
     * Cast value to date
     */
    private function castToDate(mixed $value): ?\Carbon\Carbon
    {
        if ($value instanceof \Carbon\Carbon) {
            return $value;
        }

        if ($value instanceof \DateTime || $value instanceof \DateTimeInterface) {
            return \Carbon\Carbon::instance($value);
        }

        if (is_string($value)) {
            try {
                return \Carbon\Carbon::parse($value);
            } catch (\Exception $e) {
                return null;
            }
        }

        return null;
    }

    /**
     * Validate value against custom validation rules
     */
    private function validateAgainstRules(mixed $value, array $rules): bool
    {
        foreach ($rules as $rule => $constraint) {
            $valid = match($rule) {
                'min' => $this->type === 'text' ? strlen($value) >= $constraint : $value >= $constraint,
                'max' => $this->type === 'text' ? strlen($value) <= $constraint : $value <= $constraint,
                'min_length' => is_string($value) && strlen($value) >= $constraint,
                'max_length' => is_string($value) && strlen($value) <= $constraint,
                'after' => $this->type === 'date' && $this->isDateAfter($value, $constraint),
                'before' => $this->type === 'date' && $this->isDateBefore($value, $constraint),
                default => true
            };

            if (!$valid) {
                return false;
            }
        }

        return true;
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