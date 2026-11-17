<?php

namespace SolutionForest\LaravelDynamicProperties\Console\Commands;

use Illuminate\Console\Command;
use SolutionForest\LaravelDynamicProperties\Models\EntityProperty;
use SolutionForest\LaravelDynamicProperties\Models\Property;

class PropertyDeleteCommand extends Command
{
    protected $signature = 'dynamic-properties:delete
                            {name : The property name to delete}
                            {--force : Force deletion without confirmation}';

    protected $description = 'Delete a dynamic property and all its values';

    public function handle(): int
    {
        $nameArgument = $this->argument('name');
        $name = is_string($nameArgument) ? $nameArgument : '';
        $force = (bool) $this->option('force');

        $property = Property::where('name', $name)->first();

        if (! $property) {
            $this->error("Property '{$name}' not found.");

            return 1;
        }

        // Count associated entity properties
        $entityPropertyCount = EntityProperty::where('property_id', $property->id)->count();

        if ($entityPropertyCount > 0) {
            $this->warn("This property has {$entityPropertyCount} associated values that will be deleted.");
        }

        if (! $force) {
            if (! $this->confirm("Are you sure you want to delete property '{$name}'?")) {
                $this->info('Deletion cancelled.');

                return 0;
            }
        }

        try {
            // The Property model cascades value deletion and JSON cache resync.
            $property->delete();

            $this->info("Property '{$name}' and {$entityPropertyCount} associated values deleted successfully.");

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error("Failed to delete property: {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
