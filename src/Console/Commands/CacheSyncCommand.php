<?php

namespace SolutionForest\LaravelDynamicProperties\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use SolutionForest\LaravelDynamicProperties\Models\EntityProperty;
use SolutionForest\LaravelDynamicProperties\Services\PropertyService;

class CacheSyncCommand extends Command
{
    protected $signature = 'dynamic-properties:sync-cache
                            {model? : The model class or morph name to sync (e.g., App\\Models\\User or users)}
                            {--all : Sync all models with dynamic properties}
                            {--batch-size=100 : Number of records to process at once}
                            {--dry-run : Show what would be synced without making changes}';

    protected $description = 'Synchronize JSON cache columns with entity properties';

    public function handle(): int
    {
        $modelClass = $this->argument('model');
        $syncAll = $this->option('all');
        $batchSize = (int) $this->option('batch-size');
        $dryRun = $this->option('dry-run');

        if (! $modelClass && ! $syncAll) {
            $this->error('Please specify a model class or morph name, or use --all to sync all models.');

            return 1;
        }

        $propertyService = app(PropertyService::class);

        if ($syncAll) {
            return $this->syncAllModels($propertyService, $batchSize, $dryRun);
        }

        return $this->syncModel($modelClass, $propertyService, $batchSize, $dryRun);
    }

    private function syncAllModels(PropertyService $propertyService, int $batchSize, bool $dryRun): int
    {
        // Get all unique entity types from entity_properties table
        $entityTypes = EntityProperty::select('entity_type')
            ->distinct()
            ->pluck('entity_type')
            ->toArray();

        if (empty($entityTypes)) {
            $this->info('No entity types found with dynamic properties.');

            return 0;
        }

        $this->info('Found '.count($entityTypes).' entity types with dynamic properties:');
        foreach ($entityTypes as $entityType) {
            $this->line("  - {$entityType}");
        }

        $totalSynced = 0;
        foreach ($entityTypes as $entityType) {
            $result = $this->syncModel($entityType, $propertyService, $batchSize, $dryRun);
            if ($result === 0) {
                // Success - we can't count synced records from here since syncModel now returns 0 on success
                // But we can show that the model was processed successfully
                $this->line("  ✓ {$entityType} processed successfully");
            } else {
                $this->error("  ✗ {$entityType} failed to process");
            }
        }

        $this->info('Sync completed for all models.');

        return 0;
    }

    private function syncModel(string $modelClass, PropertyService $propertyService, int $batchSize, bool $dryRun): int
    {
        // Resolve the actual model class (handles both full class names and morph names)
        $resolvedModelClass = $this->resolveModelClass($modelClass);

        if (! $resolvedModelClass) {
            $this->error("Model class or morph name '{$modelClass}' not found.");

            return -1;
        }

        if (! class_exists($resolvedModelClass)) {
            $this->error("Model class '{$resolvedModelClass}' not found.");

            return -1;
        }

        $model = new $resolvedModelClass;
        if (! $model instanceof Model) {
            $this->error("'{$resolvedModelClass}' is not an Eloquent model.");

            return -1;
        }

        $tableName = $model->getTable();

        // Check if the table has a dynamic_properties column
        if (! Schema::hasColumn($tableName, 'dynamic_properties')) {
            $this->warn("Table '{$tableName}' does not have a 'dynamic_properties' column. Skipping.");

            return 0;
        }

        // Use the model's getMorphClass() to get the correct entity type for database queries
        $entityType = $model->getMorphClass();

        // Get entities that have dynamic properties
        $entityIds = EntityProperty::where('entity_type', $entityType)
            ->select('entity_id')
            ->distinct()
            ->pluck('entity_id')
            ->toArray();

        if (empty($entityIds)) {
            $this->info("No dynamic properties found for model '{$modelClass}' (resolved to '{$resolvedModelClass}', entity type: '{$entityType}').");

            return 0;
        }

        $this->info("Syncing {$modelClass} (resolved to {$resolvedModelClass}, entity type: {$entityType}, Table: {$tableName})");
        $this->info('Found '.count($entityIds).' entities with dynamic properties');

        if ($dryRun) {
            $this->warn('DRY RUN - No changes will be made');
        }

        $bar = $this->output->createProgressBar(count($entityIds));
        $bar->start();

        $synced = 0;
        $chunks = array_chunk($entityIds, $batchSize);

        foreach ($chunks as $chunk) {
            if (! $dryRun) {
                DB::transaction(function () use ($chunk, $resolvedModelClass, $propertyService, &$synced) {
                    foreach ($chunk as $entityId) {
                        $entity = $resolvedModelClass::find($entityId);
                        if ($entity) {
                            $this->syncEntityCache($entity, $propertyService);
                            $synced++;
                        }
                    }
                });
            } else {
                $synced += count($chunk);
            }

            $bar->advance(count($chunk));
        }

        $bar->finish();
        $this->newLine();

        $action = $dryRun ? 'would be synced' : 'synced';
        $this->info("{$synced} {$resolvedModelClass} records {$action}.");

        return 0;
    }

    /**
     * Resolve the actual model class from either a full class name or morph name
     */
    private function resolveModelClass(string $input): ?string
    {
        // First, check if it's already a valid class name
        if (class_exists($input)) {
            return $input;
        }

        // Check if it's a morph name in the morph map
        $morphMap = Relation::morphMap();
        if (! empty($morphMap) && isset($morphMap[$input])) {
            return $morphMap[$input];
        }

        // Input is neither a valid class nor a mapped morph name
        return null;
    }

    private function syncEntityCache(Model $entity, PropertyService $propertyService): void
    {
        // Get all properties for this entity
        $properties = EntityProperty::where('entity_id', $entity->id)
            ->where('entity_type', $entity->getMorphClass())
            ->get();

        $propertyData = [];
        foreach ($properties as $property) {
            $value = $property->string_value ?? $property->number_value ?? $property->date_value ?? $property->boolean_value;
            $propertyData[$property->property_name] = $value;
        }

        // Update the JSON column
        $entity->update(['dynamic_properties' => $propertyData]);
    }
}
