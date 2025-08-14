<?php

namespace DynamicProperties\Console\Commands;

use DynamicProperties\Models\Property;
use DynamicProperties\Models\EntityProperty;
use Illuminate\Console\Command;

class PropertyDeleteCommand extends Command
{
    protected $signature = 'dynamic-properties:delete
                            {name : The property name to delete}
                            {--force : Force deletion without confirmation}';

    protected $description = 'Delete a dynamic property and all its values';

    public function handle(): int
    {
        $name = $this->argument('name');
        $force = $this->option('force');

        $property = Property::where('name', $name)->first();

        if (!$property) {
            $this->error("Property '{$name}' not found.");
            return 1;
        }

        // Count associated entity properties
        $entityPropertyCount = EntityProperty::where('property_id', $property->id)->count();

        if ($entityPropertyCount > 0) {
            $this->warn("This property has {$entityPropertyCount} associated values that will be deleted.");
        }

        if (!$force) {
            if (!$this->confirm("Are you sure you want to delete property '{$name}'?")) {
                $this->info('Deletion cancelled.');
                return 0;
            }
        }

        try {
            // Delete associated entity properties first
            EntityProperty::where('property_id', $property->id)->delete();
            
            // Delete the property
            $property->delete();

            $this->info("Property '{$name}' and {$entityPropertyCount} associated values deleted successfully.");
            return 0;
        } catch (\Exception $e) {
            $this->error("Failed to delete property: {$e->getMessage()}");
            return 1;
        }
    }
}