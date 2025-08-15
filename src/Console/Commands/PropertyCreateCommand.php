<?php

namespace SolutionForest\LaravelDynamicProperties\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use SolutionForest\LaravelDynamicProperties\Models\Property;

class PropertyCreateCommand extends Command
{
    protected $signature = 'dynamic-properties:create
                            {name : The property name}
                            {type : The property type (text, number, date, boolean, select)}
                            {label? : The property label}
                            {--required : Make the property required}
                            {--options=* : Options for select type properties}
                            {--validation=* : Validation rules in key=value format}';

    protected $description = 'Create a new dynamic property';

    public function handle(): int
    {
        $name = $this->argument('name');
        $type = $this->argument('type');
        $label = $this->argument('label') ?: ucfirst(str_replace('_', ' ', $name));
        $required = $this->option('required');
        $options = $this->option('options');
        $validationRules = $this->parseValidationRules($this->option('validation'));

        // Validate input
        $validator = Validator::make([
            'name'  => $name,
            'type'  => $type,
            'label' => $label,
        ], [
            'name'  => 'required|string|max:255|unique:properties,name',
            'type'  => 'required|in:text,number,date,boolean,select',
            'label' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            $this->error('Validation failed:');
            foreach ($validator->errors()->all() as $error) {
                $this->error("  - {$error}");
            }

            return 1;
        }

        // Validate select type has options
        if ($type === 'select' && empty($options)) {
            $this->error('Select type properties must have at least one option. Use --options=option1 --options=option2');

            return 1;
        }

        try {
            $property = Property::create([
                'name'       => $name,
                'type'       => $type,
                'label'      => $label,
                'required'   => $required,
                'options'    => $type === 'select' ? $options : null,
                'validation' => $validationRules,
            ]);

            $this->info("Property '{$name}' created successfully!");
            $this->table(['Field', 'Value'], [
                ['ID', $property->id],
                ['Name', $property->name],
                ['Type', $property->type],
                ['Label', $property->label],
                ['Required', $property->required ? 'Yes' : 'No'],
                ['Options', $property->options ? implode(', ', $property->options) : 'N/A'],
                ['Validation', $property->validation ? json_encode($property->validation) : 'None'],
            ]);

            return 0;
        } catch (\Exception $e) {
            $this->error("Failed to create property: {$e->getMessage()}");

            return 1;
        }
    }

    private function parseValidationRules(array $rules): array
    {
        $parsed = [];
        foreach ($rules as $rule) {
            if (strpos($rule, '=') !== false) {
                [$key, $value] = explode('=', $rule, 2);
                $parsed[$key] = is_numeric($value) ? (float) $value : $value;
            }
        }

        return $parsed;
    }
}
