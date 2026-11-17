<?php

namespace SolutionForest\LaravelDynamicProperties\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use SolutionForest\LaravelDynamicProperties\Exceptions\PropertyException;
use SolutionForest\LaravelDynamicProperties\Services\PropertyService;

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

    public function handle(PropertyService $propertyService): int
    {
        $nameArgument = $this->argument('name');
        $typeArgument = $this->argument('type');
        $labelArgument = $this->argument('label');

        $name = is_string($nameArgument) ? $nameArgument : '';
        $type = is_string($typeArgument) ? $typeArgument : '';
        $label = is_string($labelArgument) && $labelArgument !== ''
            ? $labelArgument
            : ucfirst(str_replace('_', ' ', $name));
        $required = (bool) $this->option('required');

        $options = [];
        $optionsArgument = $this->option('options');

        if (is_array($optionsArgument)) {
            foreach ($optionsArgument as $option) {
                if (is_string($option)) {
                    $options[] = $option;
                }
            }
        }

        $validationArgument = $this->option('validation');
        $validation = [];

        if (is_array($validationArgument)) {
            foreach ($validationArgument as $rule) {
                if (is_string($rule)) {
                    $validation[] = $rule;
                }
            }
        }

        $validationRules = $this->parseValidationRules($validation);

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
            $property = $propertyService->createProperty([
                'name'       => $name,
                'type'       => $type,
                'label'      => $label,
                'required'   => (bool) $required,
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

            return self::SUCCESS;
        } catch (PropertyException $e) {
            $this->error("Failed to create property: {$e->getUserMessage()}");

            return self::FAILURE;
        } catch (\Throwable $e) {
            $this->error("Failed to create property: {$e->getMessage()}");

            return self::FAILURE;
        }
    }

    /**
     * @param  array<int, string>  $rules
     * @return array<string, mixed>
     */
    private function parseValidationRules(array $rules): array
    {
        $parsed = [];

        foreach ($rules as $rule) {
            if (! str_contains($rule, '=')) {
                $parsed[$rule] = true;

                continue;
            }

            [$key, $value] = explode('=', $rule, 2);
            $parsed[$key] = match (true) {
                $value === 'true'                               => true,
                $value === 'false'                              => false,
                is_numeric($value) && str_contains($value, '.') => (float) $value,
                is_numeric($value)                              => (int) $value,
                default                                         => $value,
            };
        }

        return $parsed;
    }
}
