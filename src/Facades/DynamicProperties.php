<?php

namespace DynamicProperties\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static void setDynamicProperty(\Illuminate\Database\Eloquent\Model $entity, string $name, mixed $value)
 * @method static void setProperties(\Illuminate\Database\Eloquent\Model $entity, array $properties)
 * @method static mixed getDynamicProperty(\Illuminate\Database\Eloquent\Model $entity, string $name)
 * @method static array getProperties(\Illuminate\Database\Eloquent\Model $entity)
 * @method static void removeProperty(\Illuminate\Database\Eloquent\Model $entity, string $name)
 * @method static \Illuminate\Support\Collection search(string $entityType, array $filters)
 * @method static \Illuminate\Support\Collection advancedSearch(string $entityType, array $filters, string $logic = 'AND')
 * @method static \Illuminate\Support\Collection searchText(string $entityType, string $propertyName, string $searchTerm, array $options = [])
 * @method static \Illuminate\Support\Collection searchNumberRange(string $entityType, string $propertyName, float $min, float $max)
 * @method static \Illuminate\Support\Collection searchDateRange(string $entityType, string $propertyName, mixed $startDate, mixed $endDate)
 * @method static \Illuminate\Support\Collection searchBoolean(string $entityType, string $propertyName, bool $value)
 * @method static void syncJsonColumn(\Illuminate\Database\Eloquent\Model $entity)
 * @method static int syncAllJsonColumns(string $entityType, int $batchSize = 100)
 *
 * @see \DynamicProperties\Services\PropertyService
 */
class DynamicProperties extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'dynamic-properties';
    }
}
