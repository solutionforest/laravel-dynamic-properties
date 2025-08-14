<?php

namespace DynamicProperties\Console\Commands;

use DynamicProperties\Models\Property;
use Illuminate\Console\Command;

class PropertyListCommand extends Command
{
    protected $signature = 'dynamic-properties:list
                            {--type= : Filter by property type}
                            {--required : Show only required properties}
                            {--format=table : Output format (table, json)}';

    protected $description = 'List all dynamic properties';

    public function handle(): int
    {
        $query = Property::query();

        // Apply filters
        if ($type = $this->option('type')) {
            $query->where('type', $type);
        }

        if ($this->option('required')) {
            $query->where('required', true);
        }

        $properties = $query->orderBy('name')->get();

        if ($properties->isEmpty()) {
            $this->info('No properties found.');
            return 0;
        }

        $format = $this->option('format');

        if ($format === 'json') {
            $this->line($properties->toJson(JSON_PRETTY_PRINT));
            return 0;
        }

        // Table format
        $headers = ['ID', 'Name', 'Type', 'Label', 'Required', 'Options', 'Validation'];
        $rows = $properties->map(function ($property) {
            return [
                $property->id,
                $property->name,
                $property->type,
                $property->label,
                $property->required ? 'Yes' : 'No',
                $property->options ? implode(', ', $property->options) : 'N/A',
                $property->validation ? json_encode($property->validation) : 'None',
            ];
        })->toArray();

        $this->table($headers, $rows);

        $this->info("Total properties: {$properties->count()}");

        return 0;
    }
}