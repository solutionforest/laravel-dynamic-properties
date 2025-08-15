<?php

namespace DynamicProperties\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class EntityProperty extends Model
{
    protected $fillable = [
        'entity_id',
        'entity_type',
        'property_id',
        'property_name',
        'string_value',
        'number_value',
        'date_value',
        'boolean_value',
    ];

    protected $casts = [
        'date_value' => 'date',
        'boolean_value' => 'boolean',
        'number_value' => 'float',
    ];

    /**
     * Get the entity that owns this property value (polymorphic)
     */
    public function entity(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get the property definition this value belongs to
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /**
     * Get the actual typed value based on which column is populated
     * This accessor returns the appropriate value based on property type
     */
    public function getValueAttribute(): mixed
    {
        // Return the non-null value from the appropriate column
        if ($this->string_value !== null) {
            return $this->string_value;
        }

        if ($this->number_value !== null) {
            return $this->number_value;
        }

        if ($this->date_value !== null) {
            return $this->date_value;
        }

        if ($this->boolean_value !== null) {
            return $this->boolean_value;
        }

        return null;
    }

    /**
     * Get the typed value based on the property definition
     * This ensures proper type casting based on the property type
     */
    public function getTypedValueAttribute(): mixed
    {
        if (! $this->property) {
            return $this->value;
        }

        $rawValue = $this->value;

        if ($rawValue === null) {
            return null;
        }

        return $this->property->castValue($rawValue);
    }

    /**
     * Set the value in the appropriate column based on property type
     */
    public function setValue(mixed $value, string $propertyType): void
    {
        // Clear all value columns first
        $this->string_value = null;
        $this->number_value = null;
        $this->date_value = null;
        $this->boolean_value = null;

        // Set the appropriate column based on property type
        match ($propertyType) {
            'text', 'select' => $this->string_value = $value,
            'number' => $this->number_value = $value,
            'date' => $this->date_value = $value,
            'boolean' => $this->boolean_value = $value,
        };
    }

    /**
     * Get the column name that should store the value for a given property type
     */
    public static function getValueColumnForType(string $type): string
    {
        return match ($type) {
            'text', 'select' => 'string_value',
            'number' => 'number_value',
            'date' => 'date_value',
            'boolean' => 'boolean_value',
            default => 'string_value'
        };
    }

    /**
     * Get the value columns data for storing a value of a specific type
     */
    public static function getValueColumnsForType(string $type, mixed $value): array
    {
        return match ($type) {
            'text', 'select' => [
                'string_value' => $value,
                'number_value' => null,
                'date_value' => null,
                'boolean_value' => null,
            ],
            'number' => [
                'string_value' => null,
                'number_value' => $value,
                'date_value' => null,
                'boolean_value' => null,
            ],
            'date' => [
                'string_value' => null,
                'number_value' => null,
                'date_value' => $value,
                'boolean_value' => null,
            ],
            'boolean' => [
                'string_value' => null,
                'number_value' => null,
                'date_value' => null,
                'boolean_value' => $value,
            ],
            default => [
                'string_value' => $value,
                'number_value' => null,
                'date_value' => null,
                'boolean_value' => null,
            ]
        };
    }

    /**
     * Scope to filter by entity
     */
    public function scopeForEntity($query, Model $entity)
    {
        return $query->where('entity_id', $entity->id)
            ->where('entity_type', get_class($entity));
    }
}
