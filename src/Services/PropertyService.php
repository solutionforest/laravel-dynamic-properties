<?php

namespace DynamicProperties\Services;

use DynamicProperties\Exceptions\PropertyNotFoundException;
use DynamicProperties\Exceptions\PropertyOperationException;
use DynamicProperties\Exceptions\PropertyValidationException;
use DynamicProperties\Models\EntityProperty;
use DynamicProperties\Models\Property;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class PropertyService
{
    protected array $config;

    protected PropertyValidationService $validator;

    protected DatabaseCompatibilityService $dbCompat;

    public function __construct(array $config = [], ?PropertyValidationService $validator = null, ?DatabaseCompatibilityService $dbCompat = null)
    {
        $this->config = $config;
        $this->validator = $validator ?? new PropertyValidationService;
        $this->dbCompat = $dbCompat ?? new DatabaseCompatibilityService($config);
    }

    /**
     * Set a single property value for an entity
     *
     * @param  Model  $entity  The entity to set the property on
     * @param  string  $name  The property name
     * @param  mixed  $value  The property value
     *
     * @throws PropertyNotFoundException If property doesn't exist
     * @throws PropertyValidationException If value is invalid
     * @throws PropertyOperationException If operation fails
     */
    public function setProperty(Model $entity, string $name, mixed $value): void
    {
        try {
            // Validate entity has an ID
            if (! $entity->id) {
                throw new PropertyOperationException('set property', 'Entity must be saved before setting properties', [
                    'entity_type' => get_class($entity),
                    'property_name' => $name,
                ]);
            }

            // Find the property definition
            $property = Property::where('name', $name)->first();
            if (! $property) {
                throw new PropertyNotFoundException($name, [
                    'entity_type' => get_class($entity),
                    'entity_id' => $entity->id,
                ]);
            }

            // Validate the value against property type and validation rules
            $this->validator->validatePropertyValue($property, $value);

            // Cast the value to the appropriate type
            $castedValue = $property->castValue($value);

            // Use database transaction for consistency
            DB::transaction(function () use ($entity, $property, $castedValue) {
                // Create or update the entity property record
                EntityProperty::updateOrCreate([
                    'entity_id' => $entity->id,
                    'entity_type' => $entity->getMorphClass(),
                    'property_id' => $property->id,
                ], [
                    'property_name' => $property->name,
                    ...$this->getValueColumns($property->type, $castedValue),
                ]);

                // Update JSON column in original table if it exists
                if (Schema::hasColumn($entity->getTable(), 'dynamic_properties')) {
                    $this->syncJsonColumn($entity);
                }
            });

        } catch (PropertyNotFoundException|PropertyValidationException $e) {
            // Re-throw known exceptions
            throw $e;
        } catch (\Exception $e) {
            // Log unexpected errors and throw operation exception
            Log::error('Property operation failed', [
                'operation' => 'setProperty',
                'entity_type' => get_class($entity),
                'entity_id' => $entity->id ?? null,
                'property_name' => $name,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw new PropertyOperationException('set property', 'An unexpected error occurred', [
                'entity_type' => get_class($entity),
                'entity_id' => $entity->id ?? null,
                'property_name' => $name,
                'original_error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Set multiple properties at once for an entity
     *
     * @param  Model  $entity  The entity to set properties on
     * @param  array  $properties  Array of property name => value pairs
     *
     * @throws PropertyNotFoundException If any property doesn't exist
     * @throws PropertyValidationException If any value is invalid
     * @throws PropertyOperationException If operation fails
     */
    public function setProperties(Model $entity, array $properties): void
    {
        if (empty($properties)) {
            return;
        }

        $errors = [];
        $validatedProperties = [];

        // Validate all properties first before making any changes
        foreach ($properties as $name => $value) {
            try {
                // Find the property definition
                $property = Property::where('name', $name)->first();
                if (! $property) {
                    throw new PropertyNotFoundException($name);
                }

                // Validate the value
                $this->validator->validatePropertyValue($property, $value);

                // Store for batch processing
                $validatedProperties[$name] = [
                    'property' => $property,
                    'value' => $value,
                    'casted_value' => $property->castValue($value),
                ];

            } catch (PropertyNotFoundException|PropertyValidationException $e) {
                $errors[$name] = $e->getUserMessage();
            }
        }

        // If there are validation errors, throw a comprehensive exception
        if (! empty($errors)) {
            throw new PropertyValidationException('multiple properties', $properties, $errors, null, [
                'entity_type' => get_class($entity),
                'entity_id' => $entity->id ?? null,
                'failed_properties' => array_keys($errors),
            ]);
        }

        try {
            // Use database transaction for consistency
            DB::transaction(function () use ($entity, $validatedProperties) {
                foreach ($validatedProperties as $name => $data) {
                    $property = $data['property'];
                    $castedValue = $data['casted_value'];

                    // Create or update the entity property record
                    EntityProperty::updateOrCreate([
                        'entity_id' => $entity->id,
                        'entity_type' => $entity->getMorphClass(),
                        'property_id' => $property->id,
                    ], [
                        'property_name' => $property->name,
                        ...$this->getValueColumns($property->type, $castedValue),
                    ]);
                }

                // Update JSON column once after all properties are set
                if (Schema::hasColumn($entity->getTable(), 'dynamic_properties')) {
                    $this->syncJsonColumn($entity);
                }
            });

        } catch (\Exception $e) {
            Log::error('Batch property operation failed', [
                'operation' => 'setProperties',
                'entity_type' => get_class($entity),
                'entity_id' => $entity->id ?? null,
                'properties' => array_keys($properties),
                'error' => $e->getMessage(),
            ]);

            throw new PropertyOperationException('set properties', 'Failed to save properties', [
                'entity_type' => get_class($entity),
                'entity_id' => $entity->id ?? null,
                'properties' => array_keys($properties),
                'original_error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Create a new property definition
     *
     * @param  array  $data  Property definition data
     *
     * @throws PropertyValidationException If property definition is invalid
     * @throws PropertyOperationException If creation fails
     */
    public function createProperty(array $data): Property
    {
        // Validate property definition
        $errors = $this->validator->validatePropertyDefinition($data);
        if (! empty($errors)) {
            throw new PropertyValidationException('property definition', $data, $errors, null, [
                'operation' => 'create_property',
            ]);
        }

        // Check if property name already exists
        if (Property::where('name', $data['name'])->exists()) {
            throw new PropertyValidationException('property definition', $data, [
                "A property with the name '{$data['name']}' already exists.",
            ], null, [
                'operation' => 'create_property',
                'duplicate_name' => $data['name'],
            ]);
        }

        try {
            return Property::create($data);
        } catch (\Exception $e) {
            Log::error('Property creation failed', [
                'data' => $data,
                'error' => $e->getMessage(),
            ]);

            throw new PropertyOperationException('create property', 'Failed to create property', [
                'property_data' => $data,
                'original_error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get the appropriate value columns for storing a value based on property type
     *
     * @param  string  $type  The property type
     * @param  mixed  $value  The value to store
     * @return array Array of column => value pairs
     */
    private function getValueColumns(string $type, mixed $value): array
    {
        return EntityProperty::getValueColumnsForType($type, $value);
    }

    /**
     * Sync all entity properties to the JSON column in the original table
     * This provides fast access to all properties without multiple queries
     *
     * @param  Model  $entity  The entity to sync properties for
     */
    public function syncJsonColumn(Model $entity): void
    {
        // Check if the entity table has the dynamic_properties column
        if (! Schema::hasColumn($entity->getTable(), 'dynamic_properties')) {
            return; // Skip if column doesn't exist
        }

        // Get all properties for this entity
        $properties = EntityProperty::where('entity_id', $entity->id)
            ->where('entity_type', $entity->getMorphClass())
            ->get();

        // Build the properties array
        $propertiesArray = [];
        foreach ($properties as $entityProperty) {
            $propertiesArray[$entityProperty->property_name] = $entityProperty->value;
        }

        // Update the JSON column in the original table
        // Use updateQuietly to avoid triggering model events that might cause recursion
        $entity->updateQuietly(['dynamic_properties' => $propertiesArray]);
    }

    /**
     * Sync JSON columns for all entities of a given type
     * Useful when adding dynamic_properties column to existing tables with data
     *
     * @param  string  $entityType  The entity class name (e.g., 'App\Models\User')
     * @param  int  $batchSize  Number of entities to process at once
     * @return int Number of entities synced
     */
    public function syncAllJsonColumns(string $entityType, int $batchSize = 100): int
    {
        // Get a sample entity to check if the table has the column
        $sampleEntity = new $entityType;
        if (! Schema::hasColumn($sampleEntity->getTable(), 'dynamic_properties')) {
            return 0; // Skip if column doesn't exist
        }

        // Get all entity IDs that have properties
        $entityIds = EntityProperty::where('entity_type', $entityType)
            ->select('entity_id')
            ->distinct()
            ->pluck('entity_id');

        $synced = 0;

        // Process entities in batches to avoid memory issues
        $entityIds->chunk($batchSize)->each(function ($chunk) use ($entityType, &$synced) {
            $entities = $entityType::whereIn('id', $chunk)->get();

            foreach ($entities as $entity) {
                $this->syncJsonColumn($entity);
                $synced++;
            }
        });

        return $synced;
    }

    /**
     * Get a property value for an entity
     *
     * @param  Model  $entity  The entity to get the property from
     * @param  string  $name  The property name
     * @return mixed The property value or null if not found
     */
    public function getProperty(Model $entity, string $name): mixed
    {
        // Try JSON column first if it exists
        if (Schema::hasColumn($entity->getTable(), 'dynamic_properties') && $entity->dynamic_properties) {
            return $entity->dynamic_properties[$name] ?? null;
        }

        // Fallback to querying entity_properties table
        $entityProperty = EntityProperty::where('entity_id', $entity->id)
            ->where('entity_type', $entity->getMorphClass())
            ->where('property_name', $name)
            ->first();

        return $entityProperty ? $entityProperty->value : null;
    }

    /**
     * Get all properties for an entity
     *
     * @param  Model  $entity  The entity to get properties for
     * @return array Array of property name => value pairs
     */
    public function getProperties(Model $entity): array
    {
        // Try JSON column first if it exists
        if (Schema::hasColumn($entity->getTable(), 'dynamic_properties') && $entity->dynamic_properties) {
            return $entity->dynamic_properties;
        }

        // Fallback to querying entity_properties table
        $properties = EntityProperty::where('entity_id', $entity->id)
            ->where('entity_type', $entity->getMorphClass())
            ->get();

        $result = [];
        foreach ($properties as $entityProperty) {
            $result[$entityProperty->property_name] = $entityProperty->value;
        }

        return $result;
    }

    /**
     * Remove a property value for an entity
     *
     * @param  Model  $entity  The entity to remove the property from
     * @param  string  $name  The property name to remove
     */
    public function removeProperty(Model $entity, string $name): void
    {
        // Remove from entity_properties table
        EntityProperty::where('entity_id', $entity->id)
            ->where('entity_type', $entity->getMorphClass())
            ->where('property_name', $name)
            ->delete();

        // Update JSON column if it exists
        if (Schema::hasColumn($entity->getTable(), 'dynamic_properties')) {
            $this->syncJsonColumn($entity);
        }
    }

    /**
     * Search entities by their property values
     *
     * @param  string  $entityType  The entity class name (e.g., 'App\Models\User')
     * @param  array  $filters  Array of property filters
     * @return \Illuminate\Support\Collection Collection of entity IDs that match the criteria
     */
    public function search(string $entityType, array $filters): \Illuminate\Support\Collection
    {
        if (empty($filters)) {
            return collect();
        }

        // Check if any filter is a NULL search - handle these specially
        $nullSearches = [];
        $regularFilters = [];

        foreach ($filters as $propertyName => $criteria) {
            if (is_array($criteria) && isset($criteria['operator']) &&
                in_array(strtolower($criteria['operator']), ['null', 'is null'])) {
                $nullSearches[$propertyName] = $criteria;
            } else {
                $regularFilters[$propertyName] = $criteria;
            }
        }

        // Start with all entity IDs for this type
        // For NULL searches, we need to consider entities that might not have any properties
        if (! empty($nullSearches)) {
            // We need to get all possible entity IDs from the actual entity table
            // Since we don't know the table name, we'll use a broader approach
            $entityIds = collect();

            // Get all entity IDs that have any properties
            $entitiesWithProperties = EntityProperty::where('entity_type', $entityType)
                ->select('entity_id')
                ->distinct()
                ->pluck('entity_id');

            $entityIds = $entitiesWithProperties;

            // For NULL searches, we also need to consider that there might be entities
            // without any properties. We'll handle this in the NULL search logic.
        } else {
            $entityIds = EntityProperty::where('entity_type', $entityType)
                ->select('entity_id')
                ->distinct()
                ->pluck('entity_id');
        }

        // Apply regular filters first
        foreach ($regularFilters as $propertyName => $criteria) {
            $query = EntityProperty::where('entity_type', $entityType);
            $query = $this->applyPropertyFilter($query, $propertyName, $criteria);

            $matchingIds = $query->select('entity_id')
                ->distinct()
                ->pluck('entity_id');

            // Intersect with previous results (AND logic)
            $entityIds = $entityIds->intersect($matchingIds);

            // If no entities match, we can stop early
            if ($entityIds->isEmpty()) {
                break;
            }
        }

        // Handle NULL searches
        foreach ($nullSearches as $propertyName => $criteria) {
            $property = Property::where('name', $propertyName)->first();
            if (! $property) {
                continue;
            }

            $column = $this->getSearchColumnForType($property->type);

            // For NULL searches, we need to find entities that either:
            // 1. Don't have this property at all, OR
            // 2. Have this property with NULL value

            // Find entities that have this property with non-null values
            $entitiesWithNonNullProperty = EntityProperty::where('entity_type', $entityType)
                ->where('property_name', $propertyName)
                ->whereNotNull($column)
                ->pluck('entity_id');

            // Find entities that have this property with NULL value
            $entitiesWithNullProperty = EntityProperty::where('entity_type', $entityType)
                ->where('property_name', $propertyName)
                ->whereNull($column)
                ->pluck('entity_id');

            // If we have regular filters, we work with the existing entity list
            if (! empty($regularFilters)) {
                // Entities without this property are those not in the non-null list
                $entitiesWithoutProperty = $entityIds->diff($entitiesWithNonNullProperty);
                $nullMatchingIds = $entitiesWithoutProperty->merge($entitiesWithNullProperty);
                $entityIds = $entityIds->intersect($nullMatchingIds);
            } else {
                // If this is a pure NULL search, we need to be more creative
                // We'll return entities with NULL values, and we can't easily find
                // entities that don't exist in the table at all without knowing the entity table

                // For now, let's assume that if an entity has ANY property, it should be considered
                // This is a limitation - we can't find entities with no properties at all
                $allEntitiesWithAnyProperty = EntityProperty::where('entity_type', $entityType)
                    ->select('entity_id')
                    ->distinct()
                    ->pluck('entity_id');

                $entitiesWithoutThisProperty = $allEntitiesWithAnyProperty->diff($entitiesWithNonNullProperty);
                $entityIds = $entitiesWithoutThisProperty->merge($entitiesWithNullProperty);
            }
        }

        return $entityIds;
    }

    /**
     * Search entities with advanced filtering options
     *
     * @param  string  $entityType  The entity class name
     * @param  array  $filters  Array of property filters with advanced options
     * @param  string  $logic  Logic operator between filters ('AND' or 'OR')
     */
    public function advancedSearch(string $entityType, array $filters, string $logic = 'AND'): \Illuminate\Support\Collection
    {
        if (strtoupper($logic) === 'OR') {
            return $this->searchWithOrLogic($entityType, $filters);
        }

        return $this->search($entityType, $filters);
    }

    /**
     * Search entities where ANY of the property filters match (OR logic)
     */
    private function searchWithOrLogic(string $entityType, array $filters): \Illuminate\Support\Collection
    {
        $query = EntityProperty::where('entity_type', $entityType);

        $query->where(function ($q) use ($filters) {
            foreach ($filters as $propertyName => $criteria) {
                $q->orWhere(function ($subQuery) use ($propertyName, $criteria) {
                    $this->applyPropertyFilter($subQuery, $propertyName, $criteria);
                });
            }
        });

        return $query->select('entity_id')
            ->distinct()
            ->pluck('entity_id');
    }

    /**
     * Apply a single property filter to a query
     */
    private function applyPropertyFilter($query, string $propertyName, mixed $criteria)
    {
        // Get property definition for type information
        $property = Property::where('name', $propertyName)->first();
        if (! $property) {
            return $query; // Skip unknown properties
        }

        $query->where('property_name', $propertyName);

        // Handle different criteria formats
        if (is_array($criteria)) {
            $this->applyAdvancedFilter($query, $property, $criteria);
        } else {
            $this->applySimpleFilter($query, $property, $criteria);
        }

        return $query;
    }

    /**
     * Apply advanced filter criteria (array format with operators)
     */
    private function applyAdvancedFilter($query, Property $property, array $criteria)
    {
        $value = $criteria['value'] ?? null;
        $operator = $criteria['operator'] ?? '=';
        $options = $criteria['options'] ?? [];

        // Handle special operators
        switch (strtolower($operator)) {
            case 'between':
                if (isset($criteria['min']) && isset($criteria['max'])) {
                    $this->applyBetweenFilter($query, $property, $criteria['min'], $criteria['max']);
                }
                break;

            case 'in':
                if (is_array($value)) {
                    $this->applyInFilter($query, $property, $value);
                }
                break;

            case 'like':
            case 'ilike':
                $this->applyLikeFilter($query, $property, $value, $options);
                break;

            case 'null':
            case 'is null':
                $this->applyNullFilter($query, $property, true);
                break;

            case 'not null':
            case 'is not null':
                $this->applyNullFilter($query, $property, false);
                break;

            default:
                $this->applyOperatorFilter($query, $property, $value, $operator);
                break;
        }
    }

    /**
     * Apply simple filter (direct value comparison)
     */
    private function applySimpleFilter($query, Property $property, mixed $value)
    {
        // Handle NULL values specifically
        if ($value === null) {
            $this->applyNullFilter($query, $property, true);
        } else {
            $this->applyOperatorFilter($query, $property, $value, '=');
        }
    }

    /**
     * Apply operator-based filter based on property type with database optimizations
     */
    private function applyOperatorFilter($query, Property $property, mixed $value, string $operator)
    {
        $column = $this->getSearchColumnForType($property->type);

        // Cast value to appropriate type
        $castedValue = $property->castValue($value);

        // Use database-specific optimized query if available
        if (in_array(strtolower($operator), ['like', 'ilike', 'fulltext'])) {
            $searchQuery = $this->dbCompat->buildOptimizedSearchQuery($property->type, $column, $castedValue, $operator);
            $query->whereRaw($searchQuery);
        } else {
            $query->where($column, $operator, $castedValue);
        }
    }

    /**
     * Apply BETWEEN filter for ranges
     */
    private function applyBetweenFilter($query, Property $property, mixed $min, mixed $max)
    {
        $column = $this->getSearchColumnForType($property->type);

        $castedMin = $property->castValue($min);
        $castedMax = $property->castValue($max);

        $query->whereBetween($column, [$castedMin, $castedMax]);
    }

    /**
     * Apply IN filter for multiple values
     */
    private function applyInFilter($query, Property $property, array $values)
    {
        $column = $this->getSearchColumnForType($property->type);

        $castedValues = array_map(fn ($value) => $property->castValue($value), $values);

        $query->whereIn($column, $castedValues);
    }

    /**
     * Apply LIKE filter for text search with database-specific optimizations
     */
    private function applyLikeFilter($query, Property $property, mixed $value, array $options = [])
    {
        $fullText = $options['full_text'] ?? false;
        $caseSensitive = $options['case_sensitive'] ?? false;
        $column = $this->getSearchColumnForType($property->type);

        if ($property->type === 'text' && $fullText && $this->dbCompat->supports('fulltext_search')) {
            // Use database-specific full-text search
            $searchQuery = $this->dbCompat->buildFullTextSearchQuery($column, $value);
            $query->whereRaw($searchQuery);
        } else {
            // Use database-specific LIKE search
            $searchQuery = $this->dbCompat->buildLikeSearchQuery($column, $value, $caseSensitive);
            $query->whereRaw($searchQuery);
        }
    }

    /**
     * Apply NULL/NOT NULL filter
     */
    private function applyNullFilter($query, Property $property, bool $isNull)
    {
        $column = $this->getSearchColumnForType($property->type);

        if ($isNull) {
            $query->whereNull($column);
        } else {
            $query->whereNotNull($column);
        }
    }

    /**
     * Get the appropriate search column based on property type
     */
    private function getSearchColumnForType(string $type): string
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
     * Search entities by text properties with full-text search capabilities
     *
     * @param  string  $entityType  The entity class name
     * @param  string  $propertyName  The property to search in
     * @param  string  $searchTerm  The search term
     * @param  array  $options  Search options (full_text, case_sensitive, etc.)
     */
    public function searchText(string $entityType, string $propertyName, string $searchTerm, array $options = []): \Illuminate\Support\Collection
    {
        $property = Property::where('name', $propertyName)->first();
        if (! $property || $property->type !== 'text') {
            return collect();
        }

        $query = EntityProperty::where('entity_type', $entityType)
            ->where('property_name', $propertyName);

        $this->applyLikeFilter($query, $property, $searchTerm, $options);

        return $query->select('entity_id')
            ->distinct()
            ->pluck('entity_id');
    }

    /**
     * Search entities by number properties within a range
     *
     * @param  string  $entityType  The entity class name
     * @param  string  $propertyName  The property to search in
     * @param  float  $min  Minimum value
     * @param  float  $max  Maximum value
     */
    public function searchNumberRange(string $entityType, string $propertyName, float $min, float $max): \Illuminate\Support\Collection
    {
        $property = Property::where('name', $propertyName)->first();
        if (! $property || $property->type !== 'number') {
            return collect();
        }

        return EntityProperty::where('entity_type', $entityType)
            ->where('property_name', $propertyName)
            ->whereBetween('number_value', [$min, $max])
            ->select('entity_id')
            ->distinct()
            ->pluck('entity_id');
    }

    /**
     * Search entities by date properties within a date range
     *
     * @param  string  $entityType  The entity class name
     * @param  string  $propertyName  The property to search in
     * @param  mixed  $startDate  Start date
     * @param  mixed  $endDate  End date
     */
    public function searchDateRange(string $entityType, string $propertyName, mixed $startDate, mixed $endDate): \Illuminate\Support\Collection
    {
        $property = Property::where('name', $propertyName)->first();
        if (! $property || $property->type !== 'date') {
            return collect();
        }

        // Cast dates to proper format
        $start = $property->castValue($startDate);
        $end = $property->castValue($endDate);

        return EntityProperty::where('entity_type', $entityType)
            ->where('property_name', $propertyName)
            ->whereBetween('date_value', [$start, $end])
            ->select('entity_id')
            ->distinct()
            ->pluck('entity_id');
    }

    /**
     * Search entities by boolean properties
     *
     * @param  string  $entityType  The entity class name
     * @param  string  $propertyName  The property to search in
     * @param  bool  $value  The boolean value to search for
     */
    public function searchBoolean(string $entityType, string $propertyName, bool $value): \Illuminate\Support\Collection
    {
        $property = Property::where('name', $propertyName)->first();
        if (! $property || $property->type !== 'boolean') {
            return collect();
        }

        return EntityProperty::where('entity_type', $entityType)
            ->where('property_name', $propertyName)
            ->where('boolean_value', $value)
            ->select('entity_id')
            ->distinct()
            ->pluck('entity_id');
    }

    /**
     * Get database compatibility information
     *
     * @return array Database features and capabilities
     */
    public function getDatabaseInfo(): array
    {
        return [
            'driver' => $this->dbCompat->getDriver(),
            'features' => $this->dbCompat->getFeatures(),
            'migration_config' => $this->dbCompat->getMigrationConfig(),
        ];
    }

    /**
     * Get the database compatibility service instance
     */
    public function getDatabaseCompatibilityService(): DatabaseCompatibilityService
    {
        return $this->dbCompat;
    }

    /**
     * Optimize database for property searches
     * Creates database-specific indexes and optimizations
     *
     * @return array List of optimization queries executed
     */
    public function optimizeDatabase(): array
    {
        $executed = [];
        $optimizations = $this->dbCompat->createOptimizedIndexes('entity_properties');

        foreach ($optimizations as $query) {
            try {
                DB::statement($query);
                $executed[] = $query;
                Log::info('Database optimization applied', ['query' => $query]);
            } catch (\Exception $e) {
                Log::warning('Database optimization failed', [
                    'query' => $query,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $executed;
    }
}
