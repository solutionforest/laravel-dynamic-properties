<?php

declare(strict_types=1);

namespace SolutionForest\LaravelDynamicProperties\Facades;

use Illuminate\Support\Facades\Facade;
use SolutionForest\LaravelDynamicProperties\Services\PropertyService;

/**
 * @method static void setDynamicProperty(\Illuminate\Database\Eloquent\Model $entity, string $name, mixed $value)
 * @method static void setProperties(\Illuminate\Database\Eloquent\Model $entity, array<string, mixed> $properties)
 * @method static mixed getDynamicProperty(\Illuminate\Database\Eloquent\Model $entity, string $name)
 * @method static array<string, mixed> getProperties(\Illuminate\Database\Eloquent\Model $entity)
 * @method static array<int|string, array<string, mixed>> getPropertiesFor(\Illuminate\Support\Collection<int, \Illuminate\Database\Eloquent\Model> $entities)
 * @method static void removeProperty(\Illuminate\Database\Eloquent\Model $entity, string $name)
 * @method static \SolutionForest\LaravelDynamicProperties\Models\Property createProperty(array<string, mixed> $data)
 * @method static \SolutionForest\LaravelDynamicProperties\Models\Property|null findProperty(string $name)
 * @method static \Illuminate\Support\Collection<int, int|string> search(string $entityType, array<string, mixed> $filters)
 * @method static \Illuminate\Support\Collection<int, int|string> advancedSearch(string $entityType, array<string, mixed> $filters, string $logic = 'AND')
 * @method static \Illuminate\Support\Collection<int, int|string> searchText(string $entityType, string $propertyName, string $searchTerm, array<string, mixed> $options = [])
 * @method static \Illuminate\Support\Collection<int, int|string> searchNumberRange(string $entityType, string $propertyName, float $min, float $max)
 * @method static \Illuminate\Support\Collection<int, int|string> searchDateRange(string $entityType, string $propertyName, mixed $startDate, mixed $endDate)
 * @method static \Illuminate\Support\Collection<int, int|string> searchBoolean(string $entityType, string $propertyName, bool $value)
 * @method static void syncJsonColumn(\Illuminate\Database\Eloquent\Model $entity)
 * @method static int syncAllJsonColumns(string $entityType, int $batchSize = 100)
 * @method static array<string, bool> getDatabaseInfo()
 * @method static list<string> optimizeDatabase()
 *
 * @see PropertyService
 */
class DynamicProperties extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'dynamic-properties';
    }
}
