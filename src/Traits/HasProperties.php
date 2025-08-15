<?php

namespace DynamicProperties\Traits;

use DynamicProperties\Models\EntityProperty;
use DynamicProperties\Services\PropertyService;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\Schema;

trait HasProperties
{
    /**
     * Get all entity properties for this model (polymorphic relationship)
     */
    public function entityProperties(): MorphMany
    {
        return $this->morphMany(EntityProperty::class, 'entity');
    }

    /**
     * Get all properties as an associative array
     * Tries JSON column first for performance, falls back to querying entity_properties table
     */
    public function getPropertiesAttribute(): array
    {
        // Fast path: JSON column in original table
        if (Schema::hasColumn($this->getTable(), 'dynamic_properties')) {
            // Return JSON column data if it exists and is not null
            if ($this->dynamic_properties !== null) {
                return is_array($this->dynamic_properties) ? $this->dynamic_properties : [];
            }
        }

        // Fallback: Query multiple rows from entity_properties table
        $properties = EntityProperty::where('entity_id', $this->id)
            ->where('entity_type', $this->getMorphClass())
            ->get();

        $result = [];
        foreach ($properties as $prop) {
            $result[$prop->property_name] = $prop->value;
        }

        return $result;
    }

    /**
     * Set a property value using the PropertyService
     *
     * @throws \DynamicProperties\Exceptions\PropertyNotFoundException
     * @throws \DynamicProperties\Exceptions\PropertyValidationException
     * @throws \DynamicProperties\Exceptions\PropertyOperationException
     */
    public function setProperty(string $name, mixed $value): void
    {
        app(PropertyService::class)->setProperty($this, $name, $value);
    }

    /**
     * Get a property value using the PropertyService
     */
    public function getProperty(string $name): mixed
    {
        return app(PropertyService::class)->getProperty($this, $name);
    }

    /**
     * Set multiple properties at once using the PropertyService
     *
     * @throws \DynamicProperties\Exceptions\PropertyNotFoundException
     * @throws \DynamicProperties\Exceptions\PropertyValidationException
     * @throws \DynamicProperties\Exceptions\PropertyOperationException
     */
    public function setProperties(array $properties): void
    {
        app(PropertyService::class)->setProperties($this, $properties);
    }

    /**
     * Remove a property using the PropertyService
     */
    public function removeProperty(string $name): void
    {
        app(PropertyService::class)->removeProperty($this, $name);
    }

    /**
     * Magic getter for property access with 'prop_' prefix
     * Example: $user->prop_phone returns the 'phone' property value
     */
    public function __get($key)
    {
        if (str_starts_with($key, 'prop_')) {
            return $this->getProperty(substr($key, 5));
        }

        return parent::__get($key);
    }

    /**
     * Magic setter for property access with 'prop_' prefix
     * Example: $user->prop_phone = '123-456-7890' sets the 'phone' property
     *
     * Note: Magic methods cannot throw typed exceptions, so property exceptions
     * will be thrown as generic exceptions. Use setProperty() directly for better error handling.
     */
    public function __set($key, $value)
    {
        if (str_starts_with($key, 'prop_')) {
            try {
                $this->setProperty(substr($key, 5), $value);
            } catch (\DynamicProperties\Exceptions\PropertyException $e) {
                // Convert to generic exception for magic method compatibility
                throw new \InvalidArgumentException($e->getUserMessage(), $e->getCode(), $e);
            }

            return;
        }
        parent::__set($key, $value);
    }

    /**
     * Magic isset check for property access with 'prop_' prefix
     */
    public function __isset($key)
    {
        if (str_starts_with($key, 'prop_')) {
            $propertyValue = $this->getProperty(substr($key, 5));

            return $propertyValue !== null;
        }

        return parent::__isset($key);
    }

    /**
     * Magic unset for property access with 'prop_' prefix
     */
    public function __unset($key)
    {
        if (str_starts_with($key, 'prop_')) {
            app(PropertyService::class)->removeProperty($this, substr($key, 5));

            return;
        }
        parent::__unset($key);
    }

    /**
     * Manually sync all properties to the JSON column in the original table
     * Useful for refreshing the cache or after bulk property updates
     */
    public function syncPropertiesToJson(): void
    {
        app(PropertyService::class)->syncJsonColumn($this);
    }

    /**
     * Check if this entity's table has the dynamic_properties JSON column
     */
    public function hasJsonPropertiesColumn(): bool
    {
        return Schema::hasColumn($this->getTable(), 'dynamic_properties');
    }

    /**
     * Scope to filter entities by a single property value
     * Supports different operators and handles all property types properly
     */
    public function scopeWhereProperty($query, string $name, mixed $value, string $operator = '=')
    {
        return $query->whereHas('entityProperties', function ($q) use ($name, $value, $operator) {
            $q->where('property_name', $name);

            // Handle different value types and operators
            if ($value instanceof \DateTime || $value instanceof \DateTimeInterface) {
                $q->where('date_value', $operator, $value);
            } elseif (is_string($value) && strtotime($value) !== false) {
                // Handle date strings - check this before general string handling
                $q->where('date_value', $operator, $value);
            } elseif (is_numeric($value)) {
                $q->where('number_value', $operator, $value);
            } elseif (is_bool($value)) {
                $q->where('boolean_value', $operator, $value);
            } elseif (is_string($value)) {
                if (strtolower($operator) === 'like' || strtolower($operator) === 'ilike') {
                    $q->where('string_value', 'LIKE', $value);
                } else {
                    $q->where('string_value', $operator, $value);
                }
            } else {
                // Fallback to string comparison
                $q->where('string_value', $operator, $value);
            }
        });
    }

    /**
     * Scope to filter entities by multiple property values
     * Each property filter is applied with AND logic
     */
    public function scopeWhereProperties($query, array $properties)
    {
        foreach ($properties as $name => $criteria) {
            if (is_array($criteria)) {
                // Handle array format: ['value' => $value, 'operator' => $operator]
                $value = $criteria['value'] ?? null;
                $operator = $criteria['operator'] ?? '=';
                $query = $this->scopeWhereProperty($query, $name, $value, $operator);
            } else {
                // Handle simple value format
                $query = $this->scopeWhereProperty($query, $name, $criteria);
            }
        }

        return $query;
    }

    /**
     * Scope to search entities by text properties using full-text or LIKE search
     */
    public function scopeWherePropertyText($query, string $name, string $searchTerm, bool $fullText = false)
    {
        return $query->whereHas('entityProperties', function ($q) use ($name, $searchTerm, $fullText) {
            $q->where('property_name', $name);

            if ($fullText) {
                // Use full-text search if available (MySQL)
                $q->whereRaw('MATCH(string_value) AGAINST(? IN BOOLEAN MODE)', [$searchTerm]);
            } else {
                // Use LIKE search for partial matching
                $q->where('string_value', 'LIKE', '%'.$searchTerm.'%');
            }
        });
    }

    /**
     * Scope to filter entities by property value within a range (for numbers and dates)
     */
    public function scopeWherePropertyBetween($query, string $name, mixed $min, mixed $max)
    {
        return $query->whereHas('entityProperties', function ($q) use ($name, $min, $max) {
            $q->where('property_name', $name);

            if (is_numeric($min) && is_numeric($max)) {
                $q->whereBetween('number_value', [$min, $max]);
            } elseif (($min instanceof \DateTime || is_string($min)) && ($max instanceof \DateTime || is_string($max))) {
                $q->whereBetween('date_value', [$min, $max]);
            }
        });
    }

    /**
     * Scope to filter entities by property values in a list (for select properties)
     */
    public function scopeWherePropertyIn($query, string $name, array $values)
    {
        return $query->whereHas('entityProperties', function ($q) use ($name, $values) {
            $q->where('property_name', $name)
                ->whereIn('string_value', $values);
        });
    }

    /**
     * Scope to filter entities that have any of the specified properties
     */
    public function scopeHasAnyProperty($query, array $propertyNames)
    {
        return $query->whereHas('entityProperties', function ($q) use ($propertyNames) {
            $q->whereIn('property_name', $propertyNames);
        });
    }

    /**
     * Scope to filter entities that have all of the specified properties
     */
    public function scopeHasAllProperties($query, array $propertyNames)
    {
        foreach ($propertyNames as $propertyName) {
            $query->whereHas('entityProperties', function ($q) use ($propertyName) {
                $q->where('property_name', $propertyName);
            });
        }

        return $query;
    }

    /**
     * Scope to order entities by a property value
     */
    public function scopeOrderByProperty($query, string $name, string $direction = 'asc')
    {
        return $query->leftJoin('entity_properties as ep_order', function ($join) use ($name) {
            $join->on($this->getTable().'.id', '=', 'ep_order.entity_id')
                ->where('ep_order.entity_type', '=', $this->getMorphClass())
                ->where('ep_order.property_name', '=', $name);
        })->orderBy('ep_order.string_value', $direction)
            ->orderBy('ep_order.number_value', $direction)
            ->orderBy('ep_order.date_value', $direction)
            ->orderBy('ep_order.boolean_value', $direction);
    }
}
