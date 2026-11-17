<?php

declare(strict_types=1);

namespace SolutionForest\LaravelDynamicProperties\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use SolutionForest\LaravelDynamicProperties\Exceptions\InvalidPropertyTypeException;

/**
 * @property int $id
 * @property int|string $entity_id
 * @property string $entity_type
 * @property int $property_id
 * @property string $property_name
 * @property string|null $string_value
 * @property float|null $number_value
 * @property Carbon|null $date_value
 * @property bool|null $boolean_value
 * @property-read Property|null $property
 * @property-read mixed $value
 */
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
        'date_value'    => 'date',
        'boolean_value' => 'boolean',
        'number_value'  => 'float',
    ];

    /**
     * Get the entity that owns this property value (polymorphic).
     *
     * @return MorphTo<Model, $this>
     */
    public function entity(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get the property definition this value belongs to.
     *
     * @return BelongsTo<Property, $this>
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /**
     * Get the actual typed value based on which column is populated.
     */
    public function getValueAttribute(): mixed
    {
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
     * Get the typed value based on the property definition.
     */
    public function getTypedValueAttribute(): mixed
    {
        $property = $this->property;

        if (! $property instanceof Property) {
            return $this->value;
        }

        $rawValue = $this->value;

        return $rawValue === null ? null : $property->castValue($rawValue);
    }

    /**
     * Set the value in the appropriate column based on property type.
     *
     * @throws InvalidPropertyTypeException
     */
    public function setValue(mixed $value, string $propertyType): void
    {
        if (! in_array($propertyType, Property::TYPES, true)) {
            throw new InvalidPropertyTypeException($propertyType, Property::TYPES);
        }

        $this->string_value = null;
        $this->number_value = null;
        $this->date_value = null;
        $this->boolean_value = null;

        $this->setAttribute(Property::valueColumn($propertyType), $value);
    }

    /**
     * Get the column name that should store the value for a given property type.
     */
    public static function getValueColumnForType(string $type): string
    {
        return Property::valueColumn($type);
    }

    /**
     * Get the value columns data for storing a value of a specific type.
     *
     * @return array<string, mixed>
     */
    public static function getValueColumnsForType(string $type, mixed $value): array
    {
        return Property::valueColumnsForType($type, $value);
    }

    /**
     * Scope to filter by entity.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForEntity(Builder $query, Model $entity): Builder
    {
        return $query->where('entity_id', $entity->getKey())
            ->where('entity_type', $entity->getMorphClass());
    }
}
