<?php

declare(strict_types=1);

namespace SolutionForest\LaravelDynamicProperties\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use SolutionForest\LaravelDynamicProperties\Exceptions\PropertyNotFoundException;
use SolutionForest\LaravelDynamicProperties\Exceptions\PropertyOperationException;
use SolutionForest\LaravelDynamicProperties\Exceptions\PropertyValidationException;
use SolutionForest\LaravelDynamicProperties\Models\EntityProperty;
use SolutionForest\LaravelDynamicProperties\Models\Property;

class PropertyService
{
    /**
     * @var array<string, mixed>
     */
    protected array $config;

    protected PropertyValidationService $validator;

    protected DatabaseCompatibilityService $dbCompat;

    /**
     * Whether the current database connection/table pair has the cache column.
     *
     * @var array<string, bool>
     */
    protected array $jsonColumnCache = [];

    /**
     * Failures from the last optimizeDatabase() call, keyed by statement.
     *
     * @var array<string, string>
     */
    protected array $lastOptimizationFailures = [];

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(array $config = [], ?PropertyValidationService $validator = null, ?DatabaseCompatibilityService $dbCompat = null)
    {
        /** @var array<string, mixed> $mergedConfig */
        $mergedConfig = array_replace_recursive([
            'database' => [
                'enable_json_cache' => true,
            ],
            'performance' => [
                'cache_property_definitions' => true,
                'cache_ttl'                  => 3600,
                'batch_size'                 => 100,
            ],
        ], $config);

        $this->config = $mergedConfig;
        $this->validator = $validator ?? new PropertyValidationService;
        $this->dbCompat = $dbCompat ?? new DatabaseCompatibilityService;
    }

    /**
     * Set a single dynamic property value for an entity.
     *
     * @throws PropertyNotFoundException
     * @throws PropertyValidationException
     * @throws PropertyOperationException
     */
    public function setDynamicProperty(Model $entity, string $name, mixed $value): void
    {
        try {
            $this->assertEntityIsPersisted($entity, $name);

            $property = $this->findPropertyOrFail($name, [
                'entity_type' => $entity::class,
                'entity_id'   => $entity->getKey(),
            ]);

            $this->validator->validatePropertyValue($property, $value);
            $row = $this->entityPropertyRow($entity, $property, $property->castValue($value));

            $this->transaction(function () use ($entity, $row): void {
                $this->upsertRows([$row]);
                $this->syncJsonColumn($entity);
            });
        } catch (PropertyNotFoundException|PropertyValidationException|PropertyOperationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->logUnexpected('setDynamicProperty', $entity, $name, $e);

            throw new PropertyOperationException('set property', 'An unexpected error occurred', [
                'entity_type'    => $entity::class,
                'entity_id'      => $entity->getKey(),
                'property_name'  => $name,
                'original_error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Set multiple properties at once for an entity.
     *
     * All values are validated before anything is written.
     *
     * @param  array<string, mixed>  $properties
     *
     * @throws PropertyNotFoundException
     * @throws PropertyValidationException
     * @throws PropertyOperationException
     */
    public function setProperties(Model $entity, array $properties): void
    {
        if ($properties === []) {
            return;
        }

        $errors = [];
        $rows = [];

        foreach ($properties as $name => $value) {
            try {
                $this->assertEntityIsPersisted($entity, (string) $name);
                $property = $this->findPropertyOrFail((string) $name);
                $this->validator->validatePropertyValue($property, $value);
                $rows[] = $this->entityPropertyRow($entity, $property, $property->castValue($value));
            } catch (PropertyNotFoundException|PropertyValidationException $e) {
                $errors[(string) $name] = $e->getUserMessage();
            }
        }

        if ($errors !== []) {
            throw new PropertyValidationException('multiple properties', null, $errors, null, [
                'entity_type'       => $entity::class,
                'entity_id'         => $entity->getKey(),
                'failed_properties' => array_keys($errors),
            ]);
        }

        try {
            $this->transaction(function () use ($entity, $rows): void {
                $this->upsertRows($rows);
                $this->syncJsonColumn($entity);
            });
        } catch (\Throwable $e) {
            $this->logUnexpected('setProperties', $entity, implode(', ', array_keys($properties)), $e);

            throw new PropertyOperationException('set properties', 'Failed to save properties', [
                'entity_type'    => $entity::class,
                'entity_id'      => $entity->getKey(),
                'properties'     => array_keys($properties),
                'original_error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Create a new property definition.
     *
     * This is the canonical write path: it validates the definition and checks
     * for duplicate names before persisting.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws PropertyValidationException
     * @throws PropertyOperationException
     */
    public function createProperty(array $data): Property
    {
        $errors = $this->validator->validatePropertyDefinition($data);

        if ($errors !== []) {
            throw new PropertyValidationException('property definition', $data, $errors, null, [
                'operation' => 'create_property',
            ]);
        }

        $name = is_string($data['name'] ?? null) ? $data['name'] : '';

        if (Property::query()->where('name', $name)->exists()) {
            throw new PropertyValidationException('property definition', $data, [
                "A property with the name '{$name}' already exists.",
            ], null, [
                'operation'      => 'create_property',
                'duplicate_name' => $name,
            ]);
        }

        try {
            $property = Property::query()->create($data);
            self::forgetDefinitionCache($name);

            return $property;
        } catch (\Throwable $e) {
            Log::error('Property creation failed', [
                'property_name' => $name,
                'error'         => $e->getMessage(),
            ]);

            throw new PropertyOperationException('create property', 'Failed to create property', [
                'property_name'  => $name,
                'original_error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Sync all entity properties to the JSON cache column of the entity table.
     *
     * The cache is written with a targeted query so the entity model's
     * `$fillable`, dirty attributes, timestamps and events are untouched.
     */
    public function syncJsonColumn(Model $entity): void
    {
        if (! $this->hasJsonColumn($entity) || $entity->getKey() === null) {
            return;
        }

        $properties = $this->propertiesFromDatabase($entity);
        $payload = (string) json_encode($properties);

        $entity->getConnection()->table($entity->getTable())
            ->where($entity->getKeyName(), $entity->getKey())
            ->update(['dynamic_properties' => $payload]);

        if ($entity->hasCast('dynamic_properties', ['array', 'json', 'collection', 'object', 'encrypted:array'])) {
            $entity->forceFill(['dynamic_properties' => $properties]);
        } else {
            $entity->forceFill(['dynamic_properties' => $payload]);
        }

        $entity->syncOriginalAttribute('dynamic_properties');
    }

    /**
     * Sync the JSON cache for every entity that owns a given property.
     */
    public function syncJsonForProperty(Property $property): void
    {
        if (! Schema::hasTable('entity_properties')) {
            return;
        }

        $targets = [];

        foreach (EntityProperty::query()
            ->where('property_id', $property->getKey())
            ->select(['entity_type', 'entity_id'])
            ->distinct()
            ->get() as $row) {
            if (! $row instanceof EntityProperty) {
                continue;
            }

            $targets[] = [
                'entity_type' => (string) $row->entity_type,
                'entity_id'   => $row->entity_id,
            ];
        }

        $this->syncJsonForTargets($targets);
    }

    /**
     * Sync the JSON cache for a list of `{entity_type, entity_id}` targets.
     *
     * @param  list<array{entity_type: string, entity_id: int|string}>  $targets
     */
    public function syncJsonForTargets(array $targets): void
    {
        foreach ($targets as $target) {
            $class = Relation::getMorphedModel($target['entity_type']) ?? $target['entity_type'];

            if (! is_string($class) || ! is_subclass_of($class, Model::class)) {
                continue;
            }

            /** @var Model|null $entity */
            $entity = $class::query()->find($target['entity_id']);

            if ($entity instanceof Model) {
                $this->syncJsonColumn($entity);
            }
        }
    }

    /**
     * Sync JSON columns for all entities of a given type.
     *
     * @return int Number of entities synced
     */
    public function syncAllJsonColumns(string $entityType, int $batchSize = 100): int
    {
        // The caller may have just added the cache column: re-read the schema.
        $this->forgetJsonColumnCache();

        /** @var Model $sampleEntity */
        $sampleEntity = new $entityType;

        if (! $this->hasJsonColumn($sampleEntity)) {
            return 0;
        }

        $synced = 0;

        EntityProperty::query()
            ->where('entity_type', $entityType)
            ->select('entity_id')
            ->distinct()
            ->pluck('entity_id')
            ->chunk($batchSize)
            ->each(function (Collection $chunk) use ($sampleEntity, &$synced): void {
                $ids = [];

                foreach ($chunk as $id) {
                    if (is_int($id) || is_string($id)) {
                        $ids[] = $id;
                    }
                }

                foreach ($sampleEntity->newQuery()->whereIn($sampleEntity->getKeyName(), $ids)->get() as $entity) {
                    if ($entity instanceof Model) {
                        $this->syncJsonColumn($entity);
                        $synced++;
                    }
                }
            });

        return $synced;
    }

    /**
     * Get a dynamic property value for an entity.
     */
    public function getDynamicProperty(Model $entity, string $name): mixed
    {
        $cached = $this->cachedProperties($entity);

        if ($cached !== null && array_key_exists($name, $cached)) {
            return $cached[$name];
        }

        $loaded = $this->loadedProperties($entity);

        if ($loaded !== null) {
            $row = $loaded->firstWhere('property_name', $name);

            return $row instanceof EntityProperty ? $row->value : null;
        }

        $row = EntityProperty::query()
            ->where('entity_id', $entity->getKey())
            ->where('entity_type', $entity->getMorphClass())
            ->where('property_name', $name)
            ->first();

        return $row instanceof EntityProperty ? $row->value : null;
    }

    /**
     * Get all properties for an entity as an associative array.
     *
     * @return array<string, mixed>
     */
    public function getProperties(Model $entity): array
    {
        $cached = $this->cachedProperties($entity);

        if ($cached !== null) {
            return $cached;
        }

        $loaded = $this->loadedProperties($entity);

        if ($loaded !== null) {
            return $this->mapProperties($loaded);
        }

        return $this->propertiesFromDatabase($entity);
    }

    /**
     * Load the properties of many entities with a single query.
     *
     * Use this instead of reading `$model->properties` in a loop; the package's
     * `withProperties()` scope does the same through eager loading.
     *
     * @param  Collection<int, Model>  $entities
     * @return array<int|string, array<string, mixed>> Keyed by entity primary key.
     */
    public function getPropertiesFor(Collection $entities): array
    {
        if ($entities->isEmpty()) {
            return [];
        }

        $result = [];

        foreach ($entities as $entity) {
            $cached = $this->cachedProperties($entity);

            if ($cached !== null) {
                $result[$this->entityKey($entity)] = $cached;
            }
        }

        $missing = $entities->filter(fn (Model $entity): bool => ! array_key_exists($this->entityKey($entity), $result));

        if ($missing->isEmpty()) {
            return $result;
        }

        $grouped = $missing->groupBy(fn (Model $entity): string => $entity->getMorphClass());

        foreach ($grouped as $entityType => $group) {
            $ids = [];

            foreach ($group as $entity) {
                $ids[] = $entity->getKey();
            }

            $rows = EntityProperty::query()
                ->where('entity_type', $entityType)
                ->whereIn('entity_id', $ids)
                ->get();

            foreach ($group as $entity) {
                $key = $this->entityKey($entity);
                /** @var Collection<int, EntityProperty> $entityRows */
                $entityRows = new Collection;

                foreach ($rows as $row) {
                    if ($row instanceof EntityProperty && $this->entityKeyFromValue($row->entity_id) === $key) {
                        $entityRows->push($row);
                    }
                }

                $result[$key] = $this->mapProperties($entityRows);
            }
        }

        return $result;
    }

    /**
     * Remove a property value for an entity.
     */
    public function removeProperty(Model $entity, string $name): void
    {
        $this->transaction(function () use ($entity, $name): void {
            EntityProperty::query()
                ->where('entity_id', $entity->getKey())
                ->where('entity_type', $entity->getMorphClass())
                ->where('property_name', $name)
                ->delete();

            $this->syncJsonColumn($entity);
        });
    }

    /**
     * Search entities by their property values.
     *
     * @param  string  $entityType  The entity class name or morph alias
     * @param  array<string, mixed>  $filters
     * @return Collection<int, int|string> Matching entity IDs
     *
     * @throws PropertyNotFoundException When a filter references an undefined property
     */
    public function search(string $entityType, array $filters): Collection
    {
        if ($filters === []) {
            return $this->toCollection([]);
        }

        /** @var Collection<int, int|string>|null $matches */
        $matches = null;

        foreach ($filters as $propertyName => $criteria) {
            $property = $this->findPropertyOrFail((string) $propertyName, [
                'operation'   => 'search',
                'entity_type' => $entityType,
            ]);

            $ids = $this->isNullSearch($criteria)
                ? $this->nullSearchIds($entityType, $property)
                : $this->matchingIds($entityType, $property, $criteria);

            $matches = $matches === null ? $ids : $this->typedIds($matches->intersect($ids));

            if ($matches->isEmpty()) {
                return $matches;
            }
        }

        return $matches;
    }

    /**
     * Search entities with advanced filtering options.
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, int|string>
     */
    public function advancedSearch(string $entityType, array $filters, string $logic = 'AND'): Collection
    {
        if (strtoupper($logic) === 'OR') {
            return $this->searchWithOrLogic($entityType, $filters);
        }

        return $this->search($entityType, $filters);
    }

    /**
     * Search entities by text properties with full-text or LIKE search.
     *
     * @param  array<string, mixed>  $options
     * @return Collection<int, int|string>
     */
    public function searchText(string $entityType, string $propertyName, string $searchTerm, array $options = []): Collection
    {
        $property = $this->findPropertyOrFail($propertyName);
        $query = EntityProperty::query()
            ->where('entity_type', $entityType)
            ->where('property_name', $propertyName);

        $this->applyLikeFilter($query, $property, $searchTerm, $options);

        return $this->pluckIds($query);
    }

    /**
     * Search entities by number properties within a range.
     *
     * @return Collection<int, int|string>
     */
    public function searchNumberRange(string $entityType, string $propertyName, float $min, float $max): Collection
    {
        $property = $this->findPropertyOrFail($propertyName);

        if ($property->type !== 'number') {
            return $this->toCollection([]);
        }

        return $this->pluckIds(
            EntityProperty::query()
                ->where('entity_type', $entityType)
                ->where('property_name', $propertyName)
                ->whereBetween(Property::valueColumn($property->type), [$min, $max])
        );
    }

    /**
     * Search entities by date properties within a date range.
     *
     * @return Collection<int, int|string>
     */
    public function searchDateRange(string $entityType, string $propertyName, mixed $startDate, mixed $endDate): Collection
    {
        $property = $this->findPropertyOrFail($propertyName);

        if ($property->type !== 'date') {
            return $this->toCollection([]);
        }

        return $this->pluckIds(
            EntityProperty::query()
                ->where('entity_type', $entityType)
                ->where('property_name', $propertyName)
                ->whereBetween(Property::valueColumn($property->type), [
                    $property->castValue($startDate),
                    $property->castValue($endDate),
                ])
        );
    }

    /**
     * Search entities by boolean properties.
     *
     * @return Collection<int, int|string>
     */
    public function searchBoolean(string $entityType, string $propertyName, bool $value): Collection
    {
        $property = $this->findPropertyOrFail($propertyName);

        if ($property->type !== 'boolean') {
            return $this->toCollection([]);
        }

        return $this->pluckIds(
            EntityProperty::query()
                ->where('entity_type', $entityType)
                ->where('property_name', $propertyName)
                ->where(Property::valueColumn($property->type), $value)
        );
    }

    /**
     * Get database compatibility information.
     *
     * @return array{driver: string, features: array<string, bool>, migration_config: array<string, bool|string>}
     */
    public function getDatabaseInfo(): array
    {
        return [
            'driver'           => $this->dbCompat->getDriver(),
            'features'         => $this->dbCompat->getFeatures(),
            'migration_config' => $this->dbCompat->getMigrationConfig(),
        ];
    }

    public function getDatabaseCompatibilityService(): DatabaseCompatibilityService
    {
        return $this->dbCompat;
    }

    /**
     * Optimize the database for property searches.
     *
     * Failures are collected (and logged) instead of aborting the whole run;
     * call getLastOptimizationFailures() for details.
     *
     * @return list<string> Statements that were applied
     */
    public function optimizeDatabase(): array
    {
        $this->lastOptimizationFailures = [];
        $applied = [];

        foreach ($this->dbCompat->createOptimizedIndexes('entity_properties') as $statement) {
            try {
                // A savepoint keeps a failed statement from poisoning an enclosing
                // transaction (PostgreSQL aborts it otherwise).
                DB::transaction(static fn () => DB::statement($statement));
                $applied[] = $statement;
                Log::info('Database optimization applied', ['query' => $statement]);
            } catch (\Throwable $e) {
                $this->lastOptimizationFailures[$statement] = $e->getMessage();
                Log::warning('Database optimization failed', [
                    'query' => $statement,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $applied;
    }

    /**
     * Failures collected by the last optimizeDatabase() call.
     *
     * @return array<string, string>
     */
    public function getLastOptimizationFailures(): array
    {
        return $this->lastOptimizationFailures;
    }

    /**
     * Find a property definition by name, honouring the definition cache.
     */
    public function findProperty(string $name): ?Property
    {
        if (! $this->configBool('performance.cache_property_definitions', true)) {
            return Property::query()->where('name', $name)->first();
        }

        $key = self::definitionCacheKey($name);
        $ttl = $this->configInt('performance.cache_ttl', 3600);

        try {
            $cached = Cache::remember($key, $ttl, fn (): ?Property => Property::query()->where('name', $name)->first());
        } catch (\Throwable) {
            return Property::query()->where('name', $name)->first();
        }

        return $cached instanceof Property ? $cached : null;
    }

    /**
     * Drop one (or every) cached property definition.
     */
    public static function forgetDefinitionCache(?string $name = null): void
    {
        if ($name === null) {
            return;
        }

        try {
            Cache::forget(self::definitionCacheKey($name));
        } catch (\Throwable) {
            // Cache is optional.
        }
    }

    /**
     * Whether the entity table has a JSON cache column and caching is enabled.
     */
    public function hasJsonColumn(Model $entity): bool
    {
        if (! $this->configBool('database.enable_json_cache', true)) {
            return false;
        }

        $key = $entity->getConnectionName().'|'.$entity->getTable();

        // Only positive results are cached: a missing column is re-checked, so
        // migrating a cache column in mid-request still takes effect.
        if (($this->jsonColumnCache[$key] ?? false) === true) {
            return true;
        }

        if ($entity->getConnection()->getSchemaBuilder()->hasColumn($entity->getTable(), 'dynamic_properties')) {
            $this->jsonColumnCache[$key] = true;

            return true;
        }

        return false;
    }

    /**
     * Drop the cached "table has a JSON cache column" result.
     */
    public function forgetJsonColumnCache(): void
    {
        $this->jsonColumnCache = [];
    }

    /**
     * @param  array<string, mixed>  $context
     *
     * @throws PropertyNotFoundException
     */
    protected function findPropertyOrFail(string $name, array $context = []): Property
    {
        $property = $this->findProperty($name);

        if (! $property instanceof Property) {
            throw new PropertyNotFoundException($name, $context);
        }

        return $property;
    }

    /**
     * @throws PropertyOperationException
     */
    protected function assertEntityIsPersisted(Model $entity, string $propertyName): void
    {
        if ($entity->getKey() !== null) {
            return;
        }

        throw new PropertyOperationException('set property', 'Entity must be saved before setting properties', [
            'entity_type'   => $entity::class,
            'property_name' => $propertyName,
        ]);
    }

    /**
     * Build the row written to entity_properties.
     *
     * @return array<string, mixed>
     */
    protected function entityPropertyRow(Model $entity, Property $property, mixed $castedValue): array
    {
        return [
            'entity_id'     => $entity->getKey(),
            'entity_type'   => $entity->getMorphClass(),
            'property_id'   => $property->getKey(),
            'property_name' => $property->name,
            ...Property::valueColumnsForType((string) $property->type, $castedValue),
        ];
    }

    /**
     * Insert or update the given rows using the unique entity/property key.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    protected function upsertRows(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        EntityProperty::query()->upsert(
            $rows,
            ['entity_id', 'entity_type', 'property_id'],
            ['property_name', 'string_value', 'number_value', 'date_value', 'boolean_value']
        );
    }

    /**
     * Run a callback inside a transaction.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    protected function transaction(callable $callback): mixed
    {
        return DB::transaction(static function () use ($callback): mixed {
            return $callback();
        });
    }

    /**
     * @return array<string, mixed>
     */
    protected function propertiesFromDatabase(Model $entity): array
    {
        return $this->mapProperties(
            EntityProperty::query()
                ->where('entity_id', $entity->getKey())
                ->where('entity_type', $entity->getMorphClass())
                ->get()
        );
    }

    /**
     * @param  Collection<int, EntityProperty>  $rows
     * @return array<string, mixed>
     */
    protected function mapProperties(Collection $rows): array
    {
        $result = [];

        foreach ($rows as $row) {
            $result[(string) $row->property_name] = $row->value;
        }

        return $result;
    }

    /**
     * Read the JSON cache column, decoding it when the model has no array cast.
     *
     * @return array<string, mixed>|null
     */
    protected function cachedProperties(Model $entity): ?array
    {
        $raw = $this->decodeCachedProperties($entity);

        return $raw === null ? null : $this->castCachedProperties($raw);
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function decodeCachedProperties(Model $entity): ?array
    {
        if (! $this->hasJsonColumn($entity)) {
            return null;
        }

        $value = $entity->getAttribute('dynamic_properties');

        if (is_array($value)) {
            return $this->stringKeyedArray($value);
        }

        if ($value instanceof Collection) {
            return $this->stringKeyedArray($value->all());
        }

        if (is_object($value)) {
            return $this->stringKeyedArray((array) $value);
        }

        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $this->stringKeyedArray($decoded) : null;
        }

        return null;
    }

    /**
     * Cast JSON-cache values through their definitions so cached reads return
     * the same types as reads from the entity_properties table (Carbon for
     * dates, float for numbers, bool for booleans).
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    protected function castCachedProperties(array $values): array
    {
        foreach ($values as $name => $value) {
            if ($value === null) {
                continue;
            }

            $property = $this->findProperty($name);

            if ($property instanceof Property) {
                $values[$name] = $property->castValue($value);
            }
        }

        return $values;
    }

    /**
     * @return Collection<int, EntityProperty>|null
     */
    protected function loadedProperties(Model $entity): ?Collection
    {
        if (! $entity->relationLoaded('entityProperties')) {
            return null;
        }

        $relation = $entity->getRelation('entityProperties');

        return $relation instanceof Collection ? $relation : null;
    }

    protected function isNullSearch(mixed $criteria): bool
    {
        return is_array($criteria)
            && isset($criteria['operator'])
            && is_string($criteria['operator'])
            && in_array(strtolower($criteria['operator']), ['null', 'is null'], true);
    }

    /**
     * @return Collection<int, int|string>
     */
    protected function nullSearchIds(string $entityType, Property $property): Collection
    {
        $column = Property::valueColumn((string) $property->type);

        $withValue = EntityProperty::query()
            ->where('entity_type', $entityType)
            ->where('property_name', $property->name)
            ->whereNotNull($column)
            ->pluck('entity_id');

        $explicitNull = EntityProperty::query()
            ->where('entity_type', $entityType)
            ->where('property_name', $property->name)
            ->whereNull($column)
            ->pluck('entity_id');

        $withAnyProperty = EntityProperty::query()
            ->where('entity_type', $entityType)
            ->select('entity_id')
            ->distinct()
            ->pluck('entity_id');

        /** @var Collection<int, int|string> $ids */
        $ids = $withAnyProperty
            ->diff($withValue)
            ->merge($explicitNull)
            ->unique()
            ->values();

        return $ids;
    }

    /**
     * Entity IDs matching a single property filter.
     *
     * @return Collection<int, int|string>
     */
    protected function matchingIds(string $entityType, Property $property, mixed $criteria): Collection
    {
        $query = EntityProperty::query()
            ->where('entity_type', $entityType)
            ->where('property_name', $property->name);

        $this->applyCriteria($query, $property, $criteria);

        return $this->pluckIds($query);
    }

    /**
     * Search entities where ANY of the property filters match (OR logic).
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, int|string>
     */
    protected function searchWithOrLogic(string $entityType, array $filters): Collection
    {
        $resolved = [];

        foreach ($filters as $propertyName => $criteria) {
            $resolved[] = [
                'property' => $this->findPropertyOrFail((string) $propertyName),
                'criteria' => $criteria,
            ];
        }

        $query = EntityProperty::query()->where('entity_type', $entityType);

        $query->where(function ($query) use ($resolved): void {
            foreach ($resolved as $filter) {
                $query->orWhere(function ($subQuery) use ($filter): void {
                    $subQuery->where('property_name', $filter['property']->name);
                    $this->applyCriteria($subQuery, $filter['property'], $filter['criteria']);
                });
            }
        });

        return $this->pluckIds($query);
    }

    /**
     * Apply one filter's criteria to an entity_properties query.
     *
     * @param  Builder<EntityProperty>  $query
     */
    protected function applyCriteria($query, Property $property, mixed $criteria): void
    {
        if (is_array($criteria)) {
            $this->applyAdvancedFilter($query, $property, $criteria);

            return;
        }

        $this->applySimpleFilter($query, $property, $criteria);
    }

    /**
     * @param  Builder<EntityProperty>  $query
     * @param  array<string, mixed>  $criteria
     */
    /**
     * @param  Builder<EntityProperty>  $query
     * @param  array<array-key, mixed>  $criteria
     */
    protected function applyAdvancedFilter($query, Property $property, array $criteria): void
    {
        $value = $criteria['value'] ?? null;
        $operator = isset($criteria['operator']) && is_string($criteria['operator']) ? strtolower($criteria['operator']) : '=';
        $options = isset($criteria['options']) && is_array($criteria['options']) ? $criteria['options'] : [];

        switch ($operator) {
            case 'between':
                $min = $criteria['min'] ?? null;
                $max = $criteria['max'] ?? null;

                if ($min !== null && $max !== null) {
                    $this->applyBetweenFilter($query, $property, $min, $max);
                }

                return;

            case 'in':
                if (is_array($value)) {
                    $this->applyInFilter($query, $property, $value);
                }

                return;

            case 'like':
            case 'ilike':
            case 'fulltext':
                if (! is_scalar($value) && $value !== null) {
                    return;
                }

                /** @var array<string, mixed> $options */
                $options['full_text'] = $operator === 'fulltext' || (bool) ($options['full_text'] ?? false);
                $this->applyLikeFilter($query, $property, (string) $value, $options);

                return;

            case 'null':
            case 'is null':
                $this->applyNullFilter($query, $property, true);

                return;

            case 'not null':
            case 'is not null':
                $this->applyNullFilter($query, $property, false);

                return;

            default:
                $this->applyOperatorFilter($query, $property, $value, $operator);
        }
    }

    /**
     * @param  Builder<EntityProperty>  $query
     */
    protected function applySimpleFilter($query, Property $property, mixed $value): void
    {
        if ($value === null) {
            $this->applyNullFilter($query, $property, true);

            return;
        }

        $this->applyOperatorFilter($query, $property, $value, '=');
    }

    /**
     * @param  Builder<EntityProperty>  $query
     */
    protected function applyOperatorFilter($query, Property $property, mixed $value, string $operator): void
    {
        $operator = strtolower($operator);

        if (in_array($operator, ['like', 'ilike'], true)) {
            $this->applyLikeFilter($query, $property, Property::castText($value), [
                'case_sensitive' => $operator === 'like',
            ]);

            return;
        }

        $allowed = ['=', '!=', '<>', '<', '>', '<=', '>='];

        if (! in_array($operator, $allowed, true)) {
            throw new PropertyValidationException((string) $property->name, $value, [
                "Unsupported search operator '{$operator}'.",
            ], $property);
        }

        $castedValue = $property->castValue($value);
        $column = Property::valueColumn((string) $property->type);

        if ($castedValue === null) {
            if (in_array($operator, ['!=', '<>'], true)) {
                $query->whereNotNull($column);
            } else {
                $query->whereNull($column);
            }

            return;
        }

        $query->where($column, $operator, $castedValue);
    }

    /**
     * @param  Builder<EntityProperty>  $query
     */
    protected function applyBetweenFilter($query, Property $property, mixed $min, mixed $max): void
    {
        $query->whereBetween(Property::valueColumn((string) $property->type), [
            $property->castValue($min),
            $property->castValue($max),
        ]);
    }

    /**
     * @param  Builder<EntityProperty>  $query
     * @param  array<int|string, mixed>  $values
     */
    protected function applyInFilter($query, Property $property, array $values): void
    {
        $castedValues = array_map(
            fn (mixed $value): mixed => $property->castValue($value),
            array_values($values)
        );

        $query->whereIn(Property::valueColumn((string) $property->type), $castedValues);
    }

    /**
     * @param  Builder<EntityProperty>  $query
     * @param  array<string, mixed>  $options
     */
    protected function applyLikeFilter($query, Property $property, string $value, array $options = []): void
    {
        $fullText = (bool) ($options['full_text'] ?? false);
        $caseSensitive = (bool) ($options['case_sensitive'] ?? false);
        $column = Property::valueColumn((string) $property->type);

        $fullTextCondition = $property->type === 'text' && $fullText
            ? $this->dbCompat->fullTextCondition($column, $value)
            : null;

        if ($fullTextCondition !== null) {
            $query->whereFullText($fullTextCondition['column'], $fullTextCondition['value']);

            return;
        }

        $this->dbCompat->applyLike($query, $column, $value, $caseSensitive);
    }

    /**
     * @param  Builder<EntityProperty>  $query
     */
    protected function applyNullFilter($query, Property $property, bool $isNull): void
    {
        $column = Property::valueColumn((string) $property->type);

        if ($isNull) {
            $query->whereNull($column);
        } else {
            $query->whereNotNull($column);
        }
    }

    /**
     * @param  Builder<EntityProperty>  $query
     * @return Collection<int, int|string>
     */
    protected function pluckIds($query): Collection
    {
        $ids = [];

        foreach ($query->distinct()->pluck('entity_id') as $id) {
            if (is_int($id) || is_string($id)) {
                $ids[] = $id;
            }
        }

        return $this->toCollection($ids);
    }

    protected function logUnexpected(string $operation, Model $entity, string $propertyName, \Throwable $e): void
    {
        Log::error('Property operation failed', [
            'operation'     => $operation,
            'entity_type'   => $entity::class,
            'entity_id'     => $entity->getKey(),
            'property_name' => $propertyName,
            'error'         => $e->getMessage(),
        ]);
    }

    /**
     * @param  iterable<array-key, mixed>  $ids
     * @return Collection<int, int|string>
     */
    protected function typedIds(iterable $ids): Collection
    {
        $typed = [];

        foreach ($ids as $id) {
            if (is_int($id) || is_string($id)) {
                $typed[] = $id;
            }
        }

        return $this->toCollection($typed);
    }

    /**
     * @template TValue
     *
     * @param  array<int, TValue>  $items
     * @return Collection<int, TValue>
     */
    protected function toCollection(array $items): Collection
    {
        return new Collection($items);
    }

    protected function entityKey(Model $entity): string
    {
        return $this->entityKeyFromValue($entity->getKey());
    }

    protected function entityKeyFromValue(mixed $value): string
    {
        return match (true) {
            is_int($value), is_string($value) => (string) $value,
            default                           => '',
        };
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<string, mixed>
     */
    protected function stringKeyedArray(array $value): array
    {
        $result = [];

        foreach ($value as $key => $item) {
            $result[(string) $key] = $item;
        }

        return $result;
    }

    protected function configBool(string $key, bool $default): bool
    {
        $value = data_get($this->config, $key, $default);

        return is_bool($value) ? $value : (bool) $value;
    }

    protected function configInt(string $key, int $default): int
    {
        $value = data_get($this->config, $key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    protected static function definitionCacheKey(string $name): string
    {
        return 'dynamic-properties.definition.'.$name;
    }
}
