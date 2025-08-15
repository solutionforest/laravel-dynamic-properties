# Usage Examples

This document provides comprehensive examples of how to use the Laravel Dynamic Properties package in various scenarios.

## Requirements

- **PHP**: 8.3 or higher
- **Laravel**: 11.0 or higher
- **Database**: MySQL 8.0+, PostgreSQL 12+, or SQLite 3.35+

> **⚠️ CRITICAL**: Before setting any property values, you must first create property definitions using `Property::create()`. Attempting to set properties without definitions will throw `PropertyNotFoundException`.

## Table of Contents

- [Basic Usage](#basic-usage)
- [Property Types](#property-types)
- [Validation Examples](#validation-examples)
- [Search and Filtering](#search-and-filtering)
- [Performance Optimization](#performance-optimization)
- [Advanced Scenarios](#advanced-scenarios)
- [Integration Examples](#integration-examples)

## Basic Usage

### Setting Up Your First Property

> **📝 STEP 1**: Always create property definitions first!

```php
<?php

use SolutionForest\LaravelDynamicProperties\Models\Property;
use App\Models\User;

// ✅ STEP 1: Create property definition first
$phoneProperty = Property::create([
    'name' => 'phone',
    'label' => 'Phone Number',
    'type' => 'text',
    'required' => false
]);

// ✅ STEP 2: Add the trait to your User model
class User extends Model
{
    use HasProperties;
    
    // Your existing model code...
}

// ✅ STEP 3: Now you can use the property
$user = User::find(1);
$user->setDynamicProperty('phone', '+1-555-123-4567'); // Works!

echo $user->getDynamicProperty('phone'); // +1-555-123-4567
echo $user->prop_phone; // +1-555-123-4567 (magic method)

// ❌ WRONG: This would fail
// $user->setDynamicProperty('undefined_prop', 'value'); // PropertyNotFoundException
```

### Working with Multiple Properties

```php
// Create multiple properties at once
$properties = [
    [
        'name' => 'phone',
        'label' => 'Phone Number',
        'type' => 'text',
        'required' => false
    ],
    [
        'name' => 'age',
        'label' => 'Age',
        'type' => 'number',
        'required' => true,
        'validation' => ['min' => 0, 'max' => 120]
    ],
    [
        'name' => 'newsletter_subscribed',
        'label' => 'Newsletter Subscription',
        'type' => 'boolean',
        'required' => false
    ]
];

foreach ($properties as $propertyData) {
    Property::create($propertyData);
}

// Set multiple properties for a user
$user = User::find(1);
$user->setProperties([
    'phone' => '+1-555-123-4567',
    'age' => 28,
    'newsletter_subscribed' => true
]);

// Get all properties
$allProperties = $user->properties;
/*
[
    'phone' => '+1-555-123-4567',
    'age' => 28,
    'newsletter_subscribed' => true
]
*/
```

## Property Types

### Text Properties

```php
// Basic text property
Property::create([
    'name' => 'bio',
    'label' => 'Biography',
    'type' => 'text',
    'required' => false,
    'validation' => [
        'min' => 10,
        'max' => 500
    ]
]);

// Usage
$user->setDynamicProperty('bio', 'Software engineer with 5 years of experience...');

// Long text property
Property::create([
    'name' => 'notes',
    'label' => 'Internal Notes',
    'type' => 'text',
    'validation' => [
        'max' => 2000
    ]
]);
```

### Number Properties

```php
// Integer property
Property::create([
    'name' => 'years_experience',
    'label' => 'Years of Experience',
    'type' => 'number',
    'validation' => [
        'min' => 0,
        'max' => 50,
        'integer' => true
    ]
]);

// Decimal property
Property::create([
    'name' => 'salary',
    'label' => 'Annual Salary',
    'type' => 'number',
    'validation' => [
        'min' => 0,
        'max' => 1000000,
        'decimal_places' => 2
    ]
]);

// Usage
$user->setDynamicProperty('years_experience', 5);
$user->setDynamicProperty('salary', 75000.50);
```

### Date Properties

```php
// Basic date property
Property::create([
    'name' => 'hire_date',
    'label' => 'Hire Date',
    'type' => 'date',
    'required' => true,
    'validation' => [
        'after' => '2020-01-01',
        'before' => 'today'
    ]
]);

// Birthday property
Property::create([
    'name' => 'birthday',
    'label' => 'Date of Birth',
    'type' => 'date',
    'validation' => [
        'before' => '18 years ago' // Must be at least 18
    ]
]);

// Usage
$user->setDynamicProperty('hire_date', '2023-03-15');
$user->setDynamicProperty('birthday', '1990-05-20');

// Access as Carbon instance
$hireDate = $user->getDynamicProperty('hire_date'); // Carbon instance
echo $hireDate->format('F j, Y'); // March 15, 2023
```

### Boolean Properties

```php
// Simple boolean property
Property::create([
    'name' => 'is_active',
    'label' => 'Active Status',
    'type' => 'boolean',
    'required' => true
]);

// Optional boolean with default
Property::create([
    'name' => 'email_notifications',
    'label' => 'Email Notifications',
    'type' => 'boolean',
    'required' => false
]);

// Usage
$user->setDynamicProperty('is_active', true);
$user->setDynamicProperty('email_notifications', false);

// Boolean values are properly typed
$isActive = $user->getDynamicProperty('is_active'); // true (boolean)
if ($isActive) {
    echo "User is active";
}
```

### Select Properties

```php
// Single select property
Property::create([
    'name' => 'department',
    'label' => 'Department',
    'type' => 'select',
    'required' => true,
    'options' => [
        'engineering',
        'marketing',
        'sales',
        'hr',
        'finance'
    ]
]);

// Select with custom labels (use associative array)
Property::create([
    'name' => 'employment_type',
    'label' => 'Employment Type',
    'type' => 'select',
    'required' => true,
    'options' => [
        'full_time' => 'Full Time',
        'part_time' => 'Part Time',
        'contract' => 'Contract',
        'intern' => 'Intern'
    ]
]);

// Usage
$user->setDynamicProperty('department', 'engineering');
$user->setDynamicProperty('employment_type', 'full_time');
```

## Validation Examples

### Custom Validation Rules

```php
// Text with pattern validation
Property::create([
    'name' => 'employee_id',
    'label' => 'Employee ID',
    'type' => 'text',
    'required' => true,
    'validation' => [
        'pattern' => '/^EMP-\d{4}$/', // Must match EMP-1234 format
        'unique' => true // Must be unique across all users
    ]
]);

// Number with custom validation
Property::create([
    'name' => 'performance_score',
    'label' => 'Performance Score',
    'type' => 'number',
    'required' => true,
    'validation' => [
        'min' => 1,
        'max' => 10,
        'step' => 0.5 // Only allow increments of 0.5
    ]
]);

// Date with complex validation
Property::create([
    'name' => 'contract_end_date',
    'label' => 'Contract End Date',
    'type' => 'date',
    'validation' => [
        'after' => 'hire_date', // Must be after hire date
        'before' => '+5 years' // Cannot be more than 5 years from now
    ]
]);
```

### Handling Validation Errors

```php
use YourVendor\DynamicProperties\Exceptions\PropertyValidationException;

try {
    $user->setDynamicProperty('employee_id', 'INVALID-FORMAT');
} catch (PropertyValidationException $e) {
    echo "Validation failed: " . $e->getMessage();
    
    // Get detailed validation errors
    $errors = $e->getValidationErrors();
    foreach ($errors as $error) {
        echo "- " . $error . "\n";
    }
}

// Validate before setting
$property = Property::where('name', 'employee_id')->first();
if ($property->validateValue('EMP-1234')) {
    $user->setDynamicProperty('employee_id', 'EMP-1234');
} else {
    echo "Invalid employee ID format";
}
```

## Search and Filtering

### Basic Search

```php
// Find users by single property
$activeUsers = User::whereProperty('is_active', true)->get();
$engineeringUsers = User::whereProperty('department', 'engineering')->get();
$seniorUsers = User::whereProperty('years_experience', '>=', 5)->get();

// Find users by multiple properties
$seniorEngineers = User::whereProperties([
    'department' => 'engineering',
    'years_experience' => 5,
    'is_active' => true
])->get();
```

### Advanced Search with Operators

```php
use YourVendor\DynamicProperties\Services\PropertyService;

$propertyService = app(PropertyService::class);

// Complex search criteria
$results = $propertyService->search('App\\Models\\User', [
    'age' => ['value' => 25, 'operator' => '>='],
    'salary' => ['value' => 50000, 'operator' => '>'],
    'department' => ['value' => ['engineering', 'marketing'], 'operator' => 'IN'],
    'is_active' => true,
    'hire_date' => ['value' => '2023-01-01', 'operator' => '>=']
]);

// Text search with LIKE operator
$results = $propertyService->search('App\\Models\\User', [
    'bio' => ['value' => '%engineer%', 'operator' => 'LIKE']
]);

// Range searches
$results = $propertyService->search('App\\Models\\User', [
    'age' => ['value' => [25, 35], 'operator' => 'BETWEEN'],
    'salary' => ['value' => 100000, 'operator' => '<']
]);
```

### Full-Text Search

```php
// Search across all text properties
$searchTerm = 'software engineer';

$users = User::whereHas('entityProperties', function($query) use ($searchTerm) {
    $query->whereRaw('MATCH(string_value) AGAINST(? IN BOOLEAN MODE)', [$searchTerm]);
})->get();

// Search specific text properties
$users = User::whereHas('entityProperties', function($query) use ($searchTerm) {
    $query->where('property_name', 'bio')
          ->whereRaw('MATCH(string_value) AGAINST(? IN BOOLEAN MODE)', [$searchTerm]);
})->get();
```

### Combining with Regular Eloquent Queries

```php
// Combine property filters with regular model attributes
$users = User::where('email_verified_at', '!=', null)
    ->whereProperty('is_active', true)
    ->whereProperty('department', 'engineering')
    ->where('created_at', '>=', now()->subYear())
    ->orderBy('created_at', 'desc')
    ->get();

// Use with pagination
$users = User::whereProperty('is_active', true)
    ->whereProperty('years_experience', '>=', 3)
    ->paginate(20);
```

## Performance Optimization

### JSON Column Caching

```php
// Add JSON column to existing table
Schema::table('users', function (Blueprint $table) {
    $table->json('dynamic_properties')->nullable();
});

// Properties are now automatically cached
$user = User::find(1);
$properties = $user->properties; // < 1ms (reads from JSON column)

// Manual cache sync if needed
use YourVendor\DynamicProperties\Services\PropertyService;

$propertyService = app(PropertyService::class);
$propertyService->syncJsonColumn($user);
```

### Bulk Operations

```php
// Bulk set properties for multiple users
$users = User::whereIn('id', [1, 2, 3, 4, 5])->get();

foreach ($users as $user) {
    $user->setProperties([
        'is_active' => true,
        'last_updated' => now()->toDateString()
    ]);
}

// More efficient: Use raw queries for bulk updates
DB::transaction(function() {
    $userIds = [1, 2, 3, 4, 5];
    
    // Update all users' active status
    EntityProperty::whereIn('entity_id', $userIds)
        ->where('entity_type', 'App\\Models\\User')
        ->where('property_name', 'is_active')
        ->update(['boolean_value' => true]);
        
    // Sync JSON cache for all affected users
    User::whereIn('id', $userIds)->each(function($user) {
        app(PropertyService::class)->syncJsonColumn($user);
    });
});
```

### Optimized Queries

```php
// Eager load properties to avoid N+1 queries
$users = User::with('entityProperties.property')->get();

foreach ($users as $user) {
    $properties = $user->properties; // No additional queries
}

// Use select to limit columns
$users = User::select(['id', 'name', 'email', 'dynamic_properties'])
    ->whereProperty('is_active', true)
    ->get();

// Index-optimized searches
$users = User::whereHas('entityProperties', function($query) {
    $query->where('property_name', 'department')
          ->where('string_value', 'engineering');
})->get();
```

## Advanced Scenarios

### Multi-Entity Properties

```php
// Use properties with different entity types
class Company extends Model
{
    use HasProperties;
}

class Contact extends Model
{
    use HasProperties;
}

// Create properties that can be used by multiple entity types
Property::create([
    'name' => 'phone',
    'label' => 'Phone Number',
    'type' => 'text'
]);

Property::create([
    'name' => 'industry',
    'label' => 'Industry',
    'type' => 'select',
    'options' => ['technology', 'healthcare', 'finance', 'retail']
]);

// Use with different entities
$company = Company::find(1);
$company->setDynamicProperty('phone', '+1-555-COMPANY');
$company->setDynamicProperty('industry', 'technology');

$contact = Contact::find(1);
$contact->setDynamicProperty('phone', '+1-555-CONTACT');

// Search across entity types
$techCompanies = Company::whereProperty('industry', 'technology')->get();
$contactsWithPhone = Contact::wherePropertyNotNull('phone')->get();
```

### Dynamic Property Creation

```php
// Create properties dynamically based on user input
class PropertyManager
{
    public function createCustomField(array $fieldData)
    {
        // Validate field data
        $validatedData = $this->validateFieldData($fieldData);
        
        // Create the property
        $property = Property::create([
            'name' => $validatedData['name'],
            'label' => $validatedData['label'],
            'type' => $validatedData['type'],
            'required' => $validatedData['required'] ?? false,
            'options' => $validatedData['options'] ?? null,
            'validation' => $validatedData['validation'] ?? null
        ]);
        
        return $property;
    }
    
    private function validateFieldData(array $data)
    {
        // Custom validation logic
        if (Property::where('name', $data['name'])->exists()) {
            throw new \Exception('Property name already exists');
        }
        
        return $data;
    }
}

// Usage
$manager = new PropertyManager();
$property = $manager->createCustomField([
    'name' => 'custom_field_' . time(),
    'label' => 'Custom Field',
    'type' => 'text',
    'required' => false
]);
```

### Property Inheritance

```php
// Create a base trait for common properties
trait HasCommonProperties
{
    public function initializeCommonProperties()
    {
        $commonProperties = [
            'created_by' => auth()->id(),
            'last_modified' => now()->toDateString(),
            'status' => 'active'
        ];
        
        $this->setProperties($commonProperties);
    }
}

// Use in models
class User extends Model
{
    use HasProperties, HasCommonProperties;
    
    protected static function booted()
    {
        static::created(function ($user) {
            $user->initializeCommonProperties();
        });
    }
}
```

### Property Versioning

```php
// Track property changes over time
class PropertyHistory extends Model
{
    protected $fillable = [
        'entity_id', 'entity_type', 'property_name',
        'old_value', 'new_value', 'changed_by', 'changed_at'
    ];
    
    protected $casts = [
        'changed_at' => 'datetime'
    ];
}

// Add to PropertyService
class PropertyService
{
    public function setPropertyWithHistory(Model $entity, string $name, mixed $value)
    {
        $oldValue = $entity->getDynamicProperty($name);
        
        // Set the new value
        $this->setDynamicProperty($entity, $name, $value);
        
        // Record the change
        PropertyHistory::create([
            'entity_id' => $entity->id,
            'entity_type' => $entity->getMorphClass(),
            'property_name' => $name,
            'old_value' => $oldValue,
            'new_value' => $value,
            'changed_by' => auth()->id(),
            'changed_at' => now()
        ]);
    }
}
```

## Integration Examples

### API Integration

```php
// API Controller for managing user properties
class UserPropertyController extends Controller
{
    public function index(User $user)
    {
        return response()->json([
            'properties' => $user->properties
        ]);
    }
    
    public function store(User $user, Request $request)
    {
        try {
            $user->setProperties($request->all());
            
            return response()->json([
                'message' => 'Properties updated successfully',
                'properties' => $user->properties
            ]);
        } catch (PropertyValidationException $e) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->getValidationErrors()
            ], 422);
        }
    }
    
    public function show(User $user, string $property)
    {
        $value = $user->getDynamicProperty($property);
        
        if ($value === null) {
            return response()->json(['message' => 'Property not found'], 404);
        }
        
        return response()->json([
            'property' => $property,
            'value' => $value
        ]);
    }
    
    public function update(User $user, string $property, Request $request)
    {
        try {
            $user->setDynamicProperty($property, $request->input('value'));
            
            return response()->json([
                'message' => 'Property updated successfully',
                'property' => $property,
                'value' => $user->getDynamicProperty($property)
            ]);
        } catch (PropertyNotFoundException $e) {
            return response()->json(['message' => 'Property not found'], 404);
        } catch (PropertyValidationException $e) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->getValidationErrors()
            ], 422);
        }
    }
}
```

### Form Integration

```php
// Blade template for property form
@foreach($properties as $property)
    <div class="form-group">
        <label for="prop_{{ $property->name }}">{{ $property->label }}</label>
        
        @if($property->type === 'text')
            <input type="text" 
                   name="prop_{{ $property->name }}" 
                   id="prop_{{ $property->name }}"
                   value="{{ old('prop_' . $property->name, $user->getDynamicProperty($property->name)) }}"
                   class="form-control"
                   @if($property->required) required @endif>
                   
        @elseif($property->type === 'number')
            <input type="number" 
                   name="prop_{{ $property->name }}" 
                   id="prop_{{ $property->name }}"
                   value="{{ old('prop_' . $property->name, $user->getDynamicProperty($property->name)) }}"
                   class="form-control"
                   @if($property->required) required @endif>
                   
        @elseif($property->type === 'date')
            <input type="date" 
                   name="prop_{{ $property->name }}" 
                   id="prop_{{ $property->name }}"
                   value="{{ old('prop_' . $property->name, $user->getDynamicProperty($property->name)) }}"
                   class="form-control"
                   @if($property->required) required @endif>
                   
        @elseif($property->type === 'boolean')
            <input type="checkbox" 
                   name="prop_{{ $property->name }}" 
                   id="prop_{{ $property->name }}"
                   value="1"
                   @if(old('prop_' . $property->name, $user->getDynamicProperty($property->name))) checked @endif
                   class="form-check-input">
                   
        @elseif($property->type === 'select')
            <select name="prop_{{ $property->name }}" 
                    id="prop_{{ $property->name }}"
                    class="form-control"
                    @if($property->required) required @endif>
                <option value="">Select...</option>
                @foreach($property->options as $option)
                    <option value="{{ $option }}" 
                            @if(old('prop_' . $property->name, $user->getDynamicProperty($property->name)) === $option) selected @endif>
                        {{ $option }}
                    </option>
                @endforeach
            </select>
        @endif
        
        @error('prop_' . $property->name)
            <div class="text-danger">{{ $message }}</div>
        @enderror
    </div>
@endforeach
```

### Event Integration

```php
// Listen for property changes
use Illuminate\Support\Facades\Event;

Event::listen('property.updated', function ($entity, $propertyName, $oldValue, $newValue) {
    // Log the change
    Log::info("Property {$propertyName} changed for {$entity->getMorphClass()} #{$entity->id}", [
        'old_value' => $oldValue,
        'new_value' => $newValue
    ]);
    
    // Send notification if important property changed
    if (in_array($propertyName, ['email', 'phone', 'department'])) {
        // Send notification to admin
        Notification::send(User::admins(), new PropertyChangedNotification($entity, $propertyName, $oldValue, $newValue));
    }
});

// Trigger events in PropertyService
class PropertyService
{
    public function setDynamicProperty(Model $entity, string $name, mixed $value): void
    {
        $oldValue = $entity->getDynamicProperty($name);
        
        // ... existing logic ...
        
        // Fire event after successful update
        Event::dispatch('property.updated', $entity, $name, $oldValue, $value);
    }
}
```

### Queue Integration

```php
// Queue job for bulk property updates
class BulkPropertyUpdateJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    
    protected $entityIds;
    protected $entityType;
    protected $properties;
    
    public function __construct(array $entityIds, string $entityType, array $properties)
    {
        $this->entityIds = $entityIds;
        $this->entityType = $entityType;
        $this->properties = $properties;
    }
    
    public function handle(PropertyService $propertyService)
    {
        $entities = $this->entityType::whereIn('id', $this->entityIds)->get();
        
        foreach ($entities as $entity) {
            try {
                $entity->setProperties($this->properties);
            } catch (\Exception $e) {
                Log::error("Failed to update properties for {$this->entityType} #{$entity->id}: " . $e->getMessage());
            }
        }
    }
}

// Dispatch the job
BulkPropertyUpdateJob::dispatch([1, 2, 3, 4, 5], 'App\\Models\\User', [
    'is_active' => true,
    'last_bulk_update' => now()->toDateString()
]);
```

These examples demonstrate the flexibility and power of the Laravel Dynamic Properties package across various use cases and integration scenarios.