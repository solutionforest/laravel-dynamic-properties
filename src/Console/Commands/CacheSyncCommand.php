<?php

declare(strict_types=1);

namespace SolutionForest\LaravelDynamicProperties\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
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

    public function handle(PropertyService $propertyService): int
    {
        $modelClass = $this->argument('model');
        $syncAll = (bool) $this->option('all');
        $batchSize = max(1, (int) $this->option('batch-size'));
        $dryRun = (bool) $this->option('dry-run');

        if (! is_string($modelClass) && ! $syncAll) {
            $this->error('Please specify a model class or morph name, or use --all to sync all models.');

            return self::FAILURE;
        }

        if ($syncAll) {
            return $this->syncAllModels($propertyService, $batchSize, $dryRun);
        }

        $result = $this->syncModel((string) $modelClass, $propertyService, $batchSize, $dryRun);

        if ($result['status'] === -1) {
            return -1;
        }

        $action = $dryRun ? 'would be synced' : 'synced';
        $this->info("{$result['synced']} {$result['model']} records {$action}.");

        return self::SUCCESS;
    }

    private function syncAllModels(PropertyService $propertyService, int $batchSize, bool $dryRun): int
    {
        $entityTypes = [];

        foreach (EntityProperty::query()->select('entity_type')->distinct()->pluck('entity_type') as $entityType) {
            if (is_string($entityType)) {
                $entityTypes[] = $entityType;
            }
        }

        if ($entityTypes === []) {
            $this->info('No entity types found with dynamic properties.');

            return self::SUCCESS;
        }

        $this->info('Found '.count($entityTypes).' entity types with dynamic properties:');

        foreach ($entityTypes as $entityType) {
            $this->line('  - '.$entityType);
        }

        $failed = false;
        $total = 0;

        foreach ($entityTypes as $entityType) {
            $result = $this->syncModel((string) $entityType, $propertyService, $batchSize, $dryRun);

            if ($result['status'] === -1) {
                $this->error('  ✗ '.$entityType.' failed to process');
                $failed = true;

                continue;
            }

            $total += $result['synced'];
            $this->line('  ✓ '.$entityType.' processed successfully');
        }

        $this->info("Sync completed for all models ({$total} records).");

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array{status: int, synced: int, model: string}
     */
    private function syncModel(string $modelClass, PropertyService $propertyService, int $batchSize, bool $dryRun): array
    {
        $resolvedModelClass = $this->resolveModelClass($modelClass);

        if ($resolvedModelClass === null) {
            $this->error("Model class or morph name '{$modelClass}' not found.");

            return ['status' => -1, 'synced' => 0, 'model' => $modelClass];
        }

        $model = new $resolvedModelClass;

        if (! $model instanceof Model) {
            $this->error("'{$resolvedModelClass}' is not an Eloquent model.");

            return ['status' => -1, 'synced' => 0, 'model' => $resolvedModelClass];
        }

        $tableName = $model->getTable();

        if (! Schema::hasColumn($tableName, 'dynamic_properties')) {
            $this->warn("Table '{$tableName}' does not have a 'dynamic_properties' column. Skipping.");

            return ['status' => 0, 'synced' => 0, 'model' => $resolvedModelClass];
        }

        $entityType = $model->getMorphClass();

        $entityIds = [];

        foreach (EntityProperty::query()
            ->where('entity_type', $entityType)
            ->select('entity_id')
            ->distinct()
            ->pluck('entity_id') as $entityId) {
            if (is_int($entityId) || is_string($entityId)) {
                $entityIds[] = $entityId;
            }
        }

        if ($entityIds === []) {
            $this->info("No dynamic properties found for model '{$modelClass}' (entity type: '{$entityType}').");

            return ['status' => 0, 'synced' => 0, 'model' => $resolvedModelClass];
        }

        $this->info("Syncing {$modelClass} (resolved to {$resolvedModelClass}, entity type: {$entityType}, Table: {$tableName})");
        $this->info('Found '.count($entityIds).' entities with dynamic properties');

        if ($dryRun) {
            $this->warn('DRY RUN - No changes will be made');

            return ['status' => 0, 'synced' => count($entityIds), 'model' => $resolvedModelClass];
        }

        $bar = $this->output->createProgressBar(count($entityIds));
        $bar->start();
        $synced = 0;

        $chunkSize = $batchSize < 1 ? 1 : $batchSize;

        foreach (array_chunk($entityIds, $chunkSize) as $chunk) {
            $entities = $model->newQuery()->whereIn($model->getKeyName(), $chunk)->get();

            foreach ($entities as $entity) {
                if ($entity instanceof Model) {
                    $propertyService->syncJsonColumn($entity);
                    $synced++;
                }
            }

            $bar->advance(count($chunk));
        }

        $bar->finish();
        $this->newLine();

        return ['status' => 0, 'synced' => $synced, 'model' => $resolvedModelClass];
    }

    /**
     * Resolve the actual model class from either a full class name or morph name.
     */
    private function resolveModelClass(string $input): ?string
    {
        if (class_exists($input) && is_subclass_of($input, Model::class)) {
            return $input;
        }

        $morphMap = Relation::morphMap();

        if (isset($morphMap[$input]) && is_string($morphMap[$input])) {
            return $morphMap[$input];
        }

        return null;
    }
}
