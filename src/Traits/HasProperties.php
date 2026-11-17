<?php

declare(strict_types=1);

namespace SolutionForest\LaravelDynamicProperties\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Query\JoinClause;
use SolutionForest\LaravelDynamicProperties\Exceptions\PropertyException;
use SolutionForest\LaravelDynamicProperties\Exceptions\PropertyNotFoundException;
use SolutionForest\LaravelDynamicProperties\Exceptions\PropertyOperationException;
use SolutionForest\LaravelDynamicProperties\Exceptions\PropertyValidationException;
use SolutionForest\LaravelDynamicProperties\Models\EntityProperty;
use SolutionForest\LaravelDynamicProperties\Models\Property;
use SolutionForest\LaravelDynamicProperties\Services\PropertyService;

/**
 * @mixin Model
 */
trait HasProperties
{
    /**
     * Get all entity properties for this model (polymorphic relationship).
     *
     * @return MorphMany<EntityProperty, $this>
     */
    public function entityProperties(): MorphMany
    {
        return $this->morphMany(EntityProperty::class, 'entity');
    }

    /**
     * Get all properties as an associative array.
     *
     * Prefers an eager-loaded `entityProperties` relation, then the JSON cache
     * column, then a per-entity query.
     *
     * @return array<string, mixed>
     */
    public function getPropertiesAttribute(): array
    {
        return $this->propertiesService()->getProperties($this);
    }

    /**
     * Set a dynamic property value using the PropertyService.
     *
     * @throws PropertyNotFoundException
     * @throws PropertyValidationException
     * @throws PropertyOperationException
     */
    public function setDynamicProperty(string $name, mixed $value): void
    {
        $this->propertiesService()->setDynamicProperty($this, $name, $value);
    }

    /**
     * Get a dynamic property value using the PropertyService.
     */
    public function getDynamicProperty(string $name): mixed
    {
        return $this->propertiesService()->getDynamicProperty($this, $name);
    }

    /**
     * Set multiple properties at once using the PropertyService.
     *
     * @param  array<string, mixed>  $properties
     *
     * @throws PropertyNotFoundException
     * @throws PropertyValidationException
     * @throws PropertyOperationException
     */
    public function setProperties(array $properties): void
    {
        $this->propertiesService()->setProperties($this, $properties);
    }

    /**
     * Remove a property using the PropertyService.
     */
    public function removeProperty(string $name): void
    {
        $this->propertiesService()->removeProperty($this, $name);
    }

    /**
     * Magic getter for property access with 'prop_' prefix.
     * Example: $user->prop_phone returns the 'phone' property value.
     */
    public function __get($key): mixed
    {
        if (is_string($key) && str_starts_with($key, 'prop_')) {
            return $this->getDynamicProperty(substr($key, 5));
        }

        return parent::__get($key);
    }

    /**
     * Magic setter for property access with 'prop_' prefix.
     * Example: $user->prop_phone = '123-456-7890' sets the 'phone' property.
     *
     * Note: magic methods cannot throw typed property exceptions, so property
     * failures are rethrown as InvalidArgumentException. Use
     * setDynamicProperty() directly for precise error handling.
     */
    public function __set($key, $value): void
    {
        if (is_string($key) && str_starts_with($key, 'prop_')) {
            try {
                $this->setDynamicProperty(substr($key, 5), $value);
            } catch (PropertyException $e) {
                throw new \InvalidArgumentException($e->getUserMessage(), (int) $e->getCode(), $e);
            }

            return;
        }

        parent::__set($key, $value);
    }

    /**
     * Magic isset check for property access with 'prop_' prefix.
     */
    public function __isset($key): bool
    {
        if (is_string($key) && str_starts_with($key, 'prop_')) {
            return $this->getDynamicProperty(substr($key, 5)) !== null;
        }

        return parent::__isset($key);
    }

    /**
     * Magic unset for property access with 'prop_' prefix.
     */
    public function __unset($key): void
    {
        if (is_string($key) && str_starts_with($key, 'prop_')) {
            $this->propertiesService()->removeProperty($this, substr($key, 5));

            return;
        }

        parent::__unset($key);
    }

    /**
     * Manually sync all properties to the JSON cache column.
     */
    public function syncPropertiesToJson(): void
    {
        $this->propertiesService()->syncJsonColumn($this);
    }

    /**
     * Check if this entity's table has the dynamic_properties JSON column.
     */
    public function hasJsonPropertiesColumn(): bool
    {
        return $this->propertiesService()->hasJsonColumn($this);
    }

    /**
     * Eager-load dynamic properties for every model in the result set.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeWithProperties(Builder $query): Builder
    {
        return $query->with('entityProperties');
    }

    /**
     * Scope to filter entities by a single property value.
     *
     * Canonical signature: whereProperty($name, $operator = '=', $value = null).
     * The two-argument shorthand whereProperty($name, $value) is supported for
     * equality comparisons, and the value-first order whereProperty($name,
     * $value, $operator) is accepted when the third argument is an operator.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeWhereProperty(Builder $query, string $name, mixed $operator = '=', mixed $value = null): Builder
    {
        if ($operator === null) {
            return $this->applyNullPropertyFilter($query, $name);
        }

        if (! is_string($operator)) {
            $candidate = $value;
            $value = $operator;
            $operator = is_string($candidate) && $this->isSearchOperator($candidate) ? $candidate : '=';
        } elseif ($value === null && ! $this->isSearchOperator($operator)) {
            $value = $operator;
            $operator = '=';
        } elseif (! $this->isSearchOperator($operator) && is_string($value) && $this->isSearchOperator($value)) {
            $swap = $operator;
            $operator = $value;
            $value = $swap;
        }

        if ($value === null) {
            return $operator === '=' || $operator === 'IS'
                ? $this->applyNullPropertyFilter($query, $name)
                : $this->applyNullPropertyFilter($query, $name, false);
        }

        return $query->whereHas('entityProperties', function (Builder $q) use ($name, $operator, $value): void {
            $q->where('property_name', $name);

            $property = $this->propertiesService()->findProperty($name);

            if ($property instanceof Property) {
                $column = Property::valueColumn((string) $property->type);
                $castedValue = $property->castValue($value);

                if (in_array(strtolower($operator), ['like', 'ilike'], true)) {
                    $q->where($column, 'like', $castedValue);

                    return;
                }

                $q->where($column, $operator, $castedValue);

                return;
            }

            $this->applyFallbackPropertyFilter($q, $operator, $value);
        });
    }

    /**
     * Scope to filter entities that have a NULL value for a property.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeWherePropertyNull(Builder $query, string $name): Builder
    {
        return $this->applyNullPropertyFilter($query, $name, true);
    }

    /**
     * Scope to filter entities that have a non-NULL value for a property.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeWherePropertyNotNull(Builder $query, string $name): Builder
    {
        return $this->applyNullPropertyFilter($query, $name, false);
    }

    /**
     * Scope to filter entities by multiple property values (AND logic).
     *
     * @param  Builder<$this>  $query
     * @param  array<string, mixed>  $properties
     * @return Builder<$this>
     */
    public function scopeWhereProperties(Builder $query, array $properties): Builder
    {
        foreach ($properties as $name => $criteria) {
            if (is_array($criteria)) {
                $value = $criteria['value'] ?? null;
                $operator = isset($criteria['operator']) && is_string($criteria['operator']) ? $criteria['operator'] : '=';

                $query = $this->scopeWhereProperty($query, (string) $name, $operator, $value);
            } else {
                $query = $this->scopeWhereProperty($query, (string) $name, '=', $criteria);
            }
        }

        return $query;
    }

    /**
     * Scope to search entities by text properties using full-text or LIKE search.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeWherePropertyText(Builder $query, string $name, string $searchTerm, bool $fullText = false): Builder
    {
        return $query->whereHas('entityProperties', function (Builder $q) use ($name, $searchTerm, $fullText): void {
            $q->where('property_name', $name);

            $compatibility = $this->propertiesService()->getDatabaseCompatibilityService();

            $condition = $fullText ? $compatibility->fullTextCondition('string_value', $searchTerm) : null;

            if ($condition !== null) {
                $q->whereFullText($condition['column'], $condition['value']);

                return;
            }

            $like = $compatibility->likeCondition($searchTerm);
            $q->where('string_value', $like['operator'], $like['value']);
        });
    }

    /**
     * Scope to filter entities by property value within a range (numbers and dates).
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeWherePropertyBetween(Builder $query, string $name, mixed $min, mixed $max): Builder
    {
        return $query->whereHas('entityProperties', function (Builder $q) use ($name, $min, $max): void {
            $q->where('property_name', $name);

            $property = $this->propertiesService()->findProperty($name);

            if ($property instanceof Property) {
                $q->whereBetween(Property::valueColumn((string) $property->type), [
                    $property->castValue($min),
                    $property->castValue($max),
                ]);

                return;
            }

            if (is_numeric($min) && is_numeric($max)) {
                $q->whereBetween('number_value', [$min, $max]);
            } elseif (($min instanceof \DateTimeInterface || is_string($min)) && ($max instanceof \DateTimeInterface || is_string($max))) {
                $q->whereBetween('date_value', [$min, $max]);
            }
        });
    }

    /**
     * Scope to filter entities by property values in a list.
     *
     * @param  Builder<$this>  $query
     * @param  array<int, mixed>  $values
     * @return Builder<$this>
     */
    public function scopeWherePropertyIn(Builder $query, string $name, array $values): Builder
    {
        return $query->whereHas('entityProperties', function (Builder $q) use ($name, $values): void {
            $q->where('property_name', $name);

            $property = $this->propertiesService()->findProperty($name);
            $column = $property instanceof Property ? Property::valueColumn((string) $property->type) : 'string_value';

            $casted = $property instanceof Property
                ? array_map(fn (mixed $value): mixed => $property->castValue($value), array_values($values))
                : array_values($values);

            $q->whereIn($column, $casted);
        });
    }

    /**
     * Scope to filter entities that have any of the specified properties.
     *
     * @param  Builder<$this>  $query
     * @param  array<int, string>  $propertyNames
     * @return Builder<$this>
     */
    public function scopeHasAnyProperty(Builder $query, array $propertyNames): Builder
    {
        return $query->whereHas('entityProperties', function (Builder $q) use ($propertyNames): void {
            $q->whereIn('property_name', $propertyNames);
        });
    }

    /**
     * Scope to filter entities that have all of the specified properties.
     *
     * @param  Builder<$this>  $query
     * @param  array<int, string>  $propertyNames
     * @return Builder<$this>
     */
    public function scopeHasAllProperties(Builder $query, array $propertyNames): Builder
    {
        foreach ($propertyNames as $propertyName) {
            $query->whereHas('entityProperties', function (Builder $q) use ($propertyName): void {
                $q->where('property_name', $propertyName);
            });
        }

        return $query;
    }

    /**
     * Scope to order entities by a property value.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeOrderByProperty(Builder $query, string $name, string $direction = 'asc'): Builder
    {
        $property = $this->propertiesService()->findProperty($name);
        $column = $property instanceof Property ? Property::valueColumn((string) $property->type) : 'string_value';
        $direction = strtolower($direction) === 'desc' ? 'desc' : 'asc';

        return $query
            ->select($this->getTable().'.*')
            ->leftJoin('entity_properties as ep_order', function (JoinClause $join) use ($name): void {
                $join->on($this->getTable().'.'.$this->getKeyName(), '=', 'ep_order.entity_id')
                    ->where('ep_order.entity_type', '=', $this->getMorphClass())
                    ->where('ep_order.property_name', '=', $name);
            })
            ->orderBy('ep_order.'.$column, $direction)
            ->orderBy($this->getTable().'.'.$this->getKeyName(), $direction);
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    protected function applyNullPropertyFilter(Builder $query, string $name, bool $isNull = true): Builder
    {
        return $query->whereHas('entityProperties', function (Builder $q) use ($name, $isNull): void {
            $q->where('property_name', $name);

            $property = $this->propertiesService()->findProperty($name);
            $column = $property instanceof Property
                ? Property::valueColumn((string) $property->type)
                : 'string_value';

            if ($isNull) {
                $q->whereNull($column);
            } else {
                $q->whereNotNull($column);
            }
        });
    }

    /**
     * Best-effort filtering for properties that have no definition yet.
     *
     * @param  Builder<EntityProperty>  $q
     */
    protected function applyFallbackPropertyFilter(Builder $q, string $operator, mixed $value): void
    {
        if ($value instanceof \DateTimeInterface) {
            $q->where('date_value', $operator, $value);

            return;
        }

        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}/', $value) === 1) {
            $q->where('date_value', $operator, $value);

            return;
        }

        if (is_bool($value)) {
            $q->where('boolean_value', $operator, $value);

            return;
        }

        if (is_numeric($value)) {
            $q->where('number_value', $operator, $value);

            return;
        }

        if (is_string($value) && in_array(strtolower($operator), ['like', 'ilike'], true)) {
            $like = $this->propertiesService()->getDatabaseCompatibilityService()->likeCondition($value);
            $q->where('string_value', $like['operator'], $like['value']);

            return;
        }

        if (is_string($value)) {
            $q->where('string_value', $operator, $value);

            return;
        }

        $q->where('string_value', $operator, $value);
    }

    protected function isSearchOperator(string $candidate): bool
    {
        return in_array(strtolower($candidate), [
            '=', '!=', '<>', '<', '>', '<=', '>=', 'like', 'ilike', 'not like',
        ], true);
    }

    protected function propertiesService(): PropertyService
    {
        return app(PropertyService::class);
    }
}
