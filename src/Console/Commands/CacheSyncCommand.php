<?php

namespace DynamicProperties\Console\Commands;

use DynamicProperties\Models\EntityProperty;
use DynamicProperties\Services\PropertyService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CacheSyncCommand extends Command
{
    protected $signature = 'dynamic-properties:sync-cache
                            {model? : The model class to sync (e.g., App\\Models\\User)}
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
            $this->error('Please specify a model class or use --all to sync all models.');

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
            $synced = $this->syncModel($entityType, $propertyService, $batchSize, $dryRun);
            if ($synced >= 0) {
                $totalSynced += $synced;
            }
        }

        $this->info("Total records synced: {$totalSynced}");

        return 0;
    }

    private function syncModel(string $modelClass, PropertyService $propertyService, int $batchSize, bool $dryRun): int
    {
        if (! class_exists($modelClass)) {
            $this->error("Model class '{$modelClass}' not found.");

            return -1;
        }

        $model = new $modelClass;
        if (! $model instanceof Model) {
            $this->error("'{$modelClass}' is not an Eloquent model.");

            return -1;
        }

        $tableName = $model->getTable();

        // Check if the table has a dynamic_properties column
        if (! Schema::hasColumn($tableName, 'dynamic_properties')) {
            $this->warn("Table '{$tableName}' does not have a 'dynamic_properties' column. Skipping.");

            return 0;
        }

        // Get entities that have dynamic properties
        $entityIds = EntityProperty::where('entity_type', $modelClass)
            ->select('entity_id')
            ->distinct()
            ->pluck('entity_id')
            ->toArray();

        if (empty($entityIds)) {
            $this->info("No dynamic properties found for model '{$modelClass}'.");

            return 0;
        }

        $this->info("Syncing {$modelClass} (Table: {$tableName})");
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
                DB::transaction(function () use ($chunk, $modelClass, $propertyService, &$synced) {
                    foreach ($chunk as $entityId) {
                        $entity = $modelClass::find($entityId);
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
        $this->info("{$synced} {$modelClass} records {$action}.");

        return $synced;
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
