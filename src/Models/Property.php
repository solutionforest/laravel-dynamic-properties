<?php

declare(strict_types=1);

namespace SolutionForest\LaravelDynamicProperties\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use SolutionForest\LaravelDynamicProperties\Exceptions\PropertyValidationException;
use SolutionForest\LaravelDynamicProperties\Services\PropertyService;
use SolutionForest\LaravelDynamicProperties\Services\PropertyValidationService;

/**
 * @property int $id
 * @property string $name
 * @property string $label
 * @property string $type
 * @property bool $required
 * @property array<int, string>|null $options
 * @property array<string, mixed>|null $validation
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class Property extends Model
{
    /**
     * Every property type supported by the package.
     *
     * @var list<string>
     */
    public const TYPES = ['text', 'number', 'date', 'boolean', 'select'];

    protected $fillable = [
        'name',
        'label',
        'type',
        'required',
        'options',
        'validation',
    ];

    protected $casts = [
        'required'   => 'boolean',
        'options'    => 'array',
        'validation' => 'array',
    ];

    /**
     * Entity types/ids whose JSON cache must be resynchronised after a delete.
     *
     * @var list<array{entity_type: string, entity_id: int|string}>
     */
    protected array $cacheSyncTargets = [];

    protected static function booted(): void
    {
        static::saving(function (Property $property): void {
            if (! is_string($property->label) || trim($property->label) === '') {
                $property->label = is_string($property->name) ? Str::headline($property->name) : '';
            }

            $errors = (new PropertyValidationService)->validatePropertyDefinition($property->definitionAttributes());

            if ($errors !== []) {
                throw new PropertyValidationException(
                    (string) ($property->name ?? 'property definition'),
                    $property->definitionAttributes(),
                    $errors,
                    null,
                    ['operation' => 'save_property']
                );
            }
        });

        static::saved(function (Property $property): void {
            PropertyService::forgetDefinitionCache($property->name);
        });

        static::updating(function (Property $property): void {
            if (! Schema::hasTable('entity_properties')) {
                return;
            }

            if ($property->isDirty('name')) {
                EntityProperty::query()
                    ->where('property_id', $property->getKey())
                    ->update(['property_name' => (string) $property->name]);
            }

            $originalType = $property->getOriginal('type');

            if ($property->isDirty('type') && is_string($originalType)) {
                DB::transaction(function () use ($property, $originalType): void {
                    $property->castStoredValuesToNewType($originalType);
                });
            }
        });

        static::updated(function (Property $property): void {
            if (! $property->wasChanged('name') && ! $property->wasChanged('type')) {
                return;
            }

            if ($property->wasChanged('name')) {
                $originalName = $property->getOriginal('name');

                if (is_string($originalName)) {
                    PropertyService::forgetDefinitionCache($originalName);
                }
            }

            app(PropertyService::class)->syncJsonForProperty($property);
        });

        static::deleting(function (Property $property): void {
            if (! Schema::hasTable('entity_properties')) {
                return;
            }

            $targets = [];

            // @var EntityProperty $row
            foreach (EntityProperty::query()
                ->where('property_id', $property->getKey())
                ->select(['entity_type', 'entity_id'])
                ->distinct()
                ->get() as $row) {
                $targets[] = [
                    'entity_type' => (string) $row->entity_type,
                    'entity_id'   => $row->entity_id,
                ];
            }

            $property->cacheSyncTargets = $targets;

            EntityProperty::query()->where('property_id', $property->getKey())->delete();
        });

        static::deleted(function (Property $property): void {
            PropertyService::forgetDefinitionCache($property->name);

            if ($property->cacheSyncTargets !== []) {
                app(PropertyService::class)->syncJsonForTargets($property->cacheSyncTargets);
            }
        });
    }

    /**
     * Get all entity properties for this property definition.
     *
     * @return HasMany<EntityProperty, $this>
     */
    public function entityProperties(): HasMany
    {
        return $this->hasMany(EntityProperty::class);
    }

    /**
     * The value column used by a given property type.
     */
    public static function valueColumn(string $type): string
    {
        return match ($type) {
            'number'         => 'number_value',
            'date'           => 'date_value',
            'boolean'        => 'boolean_value',
            'text', 'select' => 'string_value',
            default          => 'string_value',
        };
    }

    /**
     * The full set of value columns for a given type: exactly one is populated.
     *
     * @return array<string, mixed>
     */
    public static function valueColumnsForType(string $type, mixed $value): array
    {
        $columns = [
            'string_value'  => null,
            'number_value'  => null,
            'date_value'    => null,
            'boolean_value' => null,
        ];

        $columns[self::valueColumn($type)] = $value;

        return $columns;
    }

    /**
     * Validate a value against this property's type and validation rules.
     */
    public function validateValue(mixed $value): bool
    {
        try {
            (new PropertyValidationService)->validatePropertyValue($this, $value);

            return true;
        } catch (PropertyValidationException) {
            return false;
        }
    }

    /**
     * Cast a value to the type used for storage.
     */
    public function castValue(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($this->type) {
            'text', 'select' => self::castText($value),
            'number'         => is_numeric($value) ? (float) $value : $value,
            'date'           => self::castDate($value),
            'boolean'        => self::castBoolean($value),
            default          => $value,
        };
    }

    /**
     * Get validation error message for a value.
     */
    public function getValidationError(mixed $value): string
    {
        try {
            (new PropertyValidationService)->validatePropertyValue($this, $value);
        } catch (PropertyValidationException $e) {
            return $e->getUserMessage();
        }

        return '';
    }

    /**
     * Cast a value to the string used by text and select properties.
     */
    public static function castText(mixed $value): string
    {
        return match (true) {
            is_string($value)                => $value,
            is_int($value), is_float($value) => (string) $value,
            is_bool($value)                  => $value ? '1' : '0',
            $value instanceof \Stringable    => (string) $value,
            default                          => '',
        };
    }

    /**
     * Check if a value can be stored in a boolean property.
     */
    public static function isValidBoolean(mixed $value): bool
    {
        return is_bool($value)
            || $value === 1
            || $value === 0
            || $value === '1'
            || $value === '0';
    }

    /**
     * Cast a value to a boolean.
     */
    public static function castBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return $value === 1 || $value === '1';
    }

    /**
     * Check if a value is a valid date representation.
     */
    public static function isValidDate(mixed $value): bool
    {
        if ($value instanceof \DateTimeInterface) {
            return true;
        }

        return is_string($value) && strtotime($value) !== false;
    }

    /**
     * Cast a value to a Carbon instance.
     */
    public static function castDate(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value);
        }

        if (is_string($value)) {
            try {
                return Carbon::parse($value);
            } catch (\Exception) {
                return null;
            }
        }

        return null;
    }

    /**
     * Re-map every stored value when the property type changes.
     *
     * The operation is transactional: a stored value that cannot be converted
     * to the new type aborts the update (and rolls back) instead of being
     * silently nulled or flipped.
     *
     * @throws PropertyValidationException
     */
    protected function castStoredValuesToNewType(string $oldType): void
    {
        $rows = EntityProperty::query()->where('property_id', $this->getKey())->get();

        foreach ($rows as $row) {
            $rawValue = $row->value;

            if ($rawValue === null) {
                continue;
            }

            $newValue = $this->convertForTypeChange($rawValue, $oldType);

            (new PropertyValidationService)->validatePropertyValue($this, $newValue);

            $row->fill(static::valueColumnsForType((string) $this->type, $newValue));
            $row->save();
        }
    }

    /**
     * Strictly convert a stored value to this property's (new) type.
     *
     * Unlike {@see self::castValue()}, this never degrades: a value with no
     * faithful representation in the new type throws.
     *
     * @throws PropertyValidationException
     */
    protected function convertForTypeChange(mixed $value, string $oldType): mixed
    {
        $newType = (string) $this->type;
        $converted = null;

        switch ($newType) {
            case 'text':
            case 'select':
                $converted = match (true) {
                    $value instanceof \DateTimeInterface  => Carbon::instance($value)->toDateString(),
                    is_float($value)                      => rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.'),
                    default                               => self::castText($value),
                };

                break;

            case 'number':
                if (is_bool($value)) {
                    $converted = $value ? 1.0 : 0.0;
                } elseif (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) {
                    $converted = (float) $value;
                }

                break;

            case 'date':
                if ($value instanceof \DateTimeInterface || (is_string($value) && self::isValidDate($value))) {
                    $converted = self::castDate($value);
                }

                break;

            case 'boolean':
                $converted = match (true) {
                    is_bool($value)                                                               => $value,
                    $value === 1, $value === 1.0                                                  => true,
                    $value === 0, $value === 0.0                                                  => false,
                    is_string($value) && in_array(strtolower(trim($value)), ['1', 'true'], true)  => true,
                    is_string($value) && in_array(strtolower(trim($value)), ['0', 'false'], true) => false,
                    default                                                                       => null,
                };

                break;
        }

        if ($converted === null) {
            $shown = $value instanceof \DateTimeInterface ? $value->format('c') : (is_scalar($value) ? (string) $value : get_debug_type($value));

            throw new PropertyValidationException(
                (string) $this->name,
                $value,
                ["Cannot convert stored value \"{$shown}\" from {$oldType} to {$newType}."],
                $this,
                ['operation' => 'change_property_type']
            );
        }

        return $converted;
    }

    /**
     * Attributes passed to the definition validator.
     *
     * @return array<string, mixed>
     */
    protected function definitionAttributes(): array
    {
        return [
            'name'       => $this->name,
            'label'      => $this->label,
            'type'       => $this->type,
            'required'   => (bool) $this->required,
            'options'    => $this->options,
            'validation' => $this->validation,
        ];
    }
}
