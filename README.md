# Laravel Dynamic Properties

[![Tests](https://github.com/solutionforest/laravel-dynamic-properties/workflows/Tests/badge.svg)](https://github.com/solutionforest/laravel-dynamic-properties/actions)
[![Code Style](https://github.com/solutionforest/laravel-dynamic-properties/workflows/Code%20Style%20(Pint)/badge.svg)](https://github.com/solutionforest/laravel-dynamic-properties/actions)
[![Latest Stable Version](https://poser.pugx.org/solution-forest/laravel-dynamic-properties/v/stable)](https://packagist.org/packages/solution-forest/laravel-dynamic-properties)
[![License](https://poser.pugx.org/solution-forest/laravel-dynamic-properties/license)](https://packagist.org/packages/solution-forest/laravel-dynamic-properties)

A dynamic property system for Laravel that allows any entity (users, companies, contacts, etc.) to have custom properties with validation, search capabilities, and optimal performance.

## Requirements

- **PHP**: 8.3 or higher
- **Laravel**: 12.0 or higher
- **Database**: MySQL 8.0+, PostgreSQL 12+, or SQLite 3.35+ (with JSON support)

See [Version Compatibility](#version-compatibility) for the support matrix.

## Features

- **Simple Architecture**: Clean 2-table design with optional JSON caching
- **Type Safety**: Support for text, number, date, boolean, and select properties
- **Fast Performance**: one query (or zero, with the JSON cache) to load a model's properties
- **Flexible Search**: Property-based filtering with multiple operators
- **Easy Integration**: Simple trait-based implementation
- **Database Agnostic**: Works with MySQL, PostgreSQL, and SQLite
- **Validation**: Built-in property validation with custom rules

## Version Compatibility

| Version | Released | PHP | Laravel | Testbench | Status |
|---------|----------|-----|---------|-----------|--------|
| **2.x** (`v2.0.1`) | 2026-11-17 | 8.3+ | 12.x, 13.x | `^10.0 \| ^11.0` | **Supported** |
| 1.0.x (`v1.0.4`) | 2026-11-17 | 8.3+ | 11.x, 12.x, 13.x | `^9.0 \| ^10.0 \| ^11.0` | Security fixes only (Laravel 11) |
| 1.0.x (`v1.0.0`–`v1.0.3`) | 2025-08 – 2026-09 | 8.3+ | 11.x, 12.x, 13.x | `^9.0 \| ^10.0 \| ^11.0` | **Vulnerable** — SQL injection, upgrade |

Use `^2.0` in new projects. `2.x` is the current major line and receives bug
fixes and security fixes. `1.0.0`–`1.0.3` contain a SQL injection in the raw
search builders: upgrade to `2.x` (Laravel 12+) or, if you must stay on
Laravel 11, to `1.0.4`, which only backports that security fix.

### What changed in 2.0

| Area | 1.0.x | 2.0.0 |
|------|-------|-------|
| Search safety | LIKE/full-text values interpolated into raw SQL | Bound values only; wildcards escaped |
| Undefined property search | Silently matches every entity | `search()` / `advancedSearch()` throw `PropertyNotFoundException` (query scope keeps best-effort fallback) |
| `orderByProperty()` | Joined columns could overwrite the hydrated primary key | Selects the model table explicitly |
| Boolean values | `'true'` validated but stored as `false` | One accepted set: `true`/`false`/`1`/`0`/`'1'`/`'0'` |
| JSON cache | Required `$fillable` + `array` cast; flag ignored; saved unrelated attributes | Targeted write: no wiring needed, `enable_json_cache` honoured, timestamps untouched |
| Renames / type changes / deletes | Value rows and caches could go stale | Cascaded and resynchronised transactionally |
| Definition validation | Service-only | Enforced on the model too |
| Writes | Select-then-insert race on first write | `upsert()` on the unique entity/property key |
| Batch reads | One query per model | `withProperties()` and `getPropertiesFor()` |
| Migrations (PostgreSQL) | `CREATE INDEX CONCURRENTLY` fails inside transactions | Plain `CREATE INDEX` |
| Quality gates | Pest only | Pest matrix, PHPStan level 10, Pint check in CI |

**Breaking changes in 2.0.0**

- Laravel 11 is no longer supported (minimum is Laravel 12).
- `PropertyService::search()` and `advancedSearch()` fail closed for undefined
  properties instead of returning every entity.
- The raw SQL builders on `DatabaseCompatibilityService`
  (`buildLikeSearchQuery()`, `buildFullTextSearchQuery()`,
  `buildOptimizedSearchQuery()`, `buildJsonExtractQuery()`,
  `buildJsonSearchQuery()`, `getQueryHints()`) were replaced by
  `likeCondition()` / `fullTextCondition()`.

See [CHANGELOG.md](CHANGELOG.md) for the full list.

## Installation

Install the package via Composer:

```bash
composer require solution-forest/laravel-dynamic-properties
```

Publish and run the migrations:

```bash
php artisan vendor:publish --provider="SolutionForest\LaravelDynamicProperties\DynamicPropertyServiceProvider" --tag="dynamic-properties-migrations"
php artisan migrate
```

Optionally, publish the configuration file:

```bash
php artisan vendor:publish --provider="SolutionForest\LaravelDynamicProperties\DynamicPropertyServiceProvider" --tag="dynamic-properties-config"
```

## Quick Start

> **⚠️ IMPORTANT**: You must create Property definitions before setting property values. Attempting to set a property that doesn't have a definition will throw a `PropertyNotFoundException`.

### 1. Add the Trait to Your Models

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use SolutionForest\LaravelDynamicProperties\Traits\HasProperties;

class User extends Model
{
    use HasProperties;
    
    // Your existing model code...
}
```

### 2. Create Properties

**⚠️ REQUIRED STEP**: Before setting any property values, you must first create the property definitions. This ensures type safety, validation, and optimal performance.

```php
use SolutionForest\LaravelDynamicProperties\Models\Property;

// Create a text property
Property::create([
    'name' => 'phone',
    'label' => 'Phone Number',
    'type' => 'text',
    'required' => false,
    'validation' => ['min' => 10, 'max' => 15]
]);

// Create a number property
Property::create([
    'name' => 'age',
    'label' => 'Age',
    'type' => 'number',
    'required' => true,
    'validation' => ['min' => 0, 'max' => 120]
]);

// Create a select property
Property::create([
    'name' => 'status',
    'label' => 'User Status',
    'type' => 'select',
    'required' => true,
    'options' => ['active', 'inactive', 'pending']
]);
```

### 3. Set and Get Properties

**✅ Only after creating property definitions can you set values:**

```php
$user = User::find(1);

// ✅ This works - property 'phone' was defined above
$user->setDynamicProperty('phone', '+1234567890');
$user->setDynamicProperty('age', 25);
$user->setDynamicProperty('status', 'active');

// ❌ This will throw PropertyNotFoundException
$user->setDynamicProperty('undefined_property', 'value');

// Or use magic methods
$user->prop_phone = '+1234567890';
$user->prop_age = 25;

// Get properties
$phone = $user->getDynamicProperty('phone');
$age = $user->prop_age; // Magic method
$allProperties = $user->properties; // All properties as array

// Set multiple properties at once
$user->setProperties([
    'phone' => '+1234567890',
    'age' => 25,
    'status' => 'active'
]);
```

> **💡 Pro Tip**: Use the Artisan command to create properties interactively:
> ```bash
> php artisan dynamic-properties:create
> ```

### 4. Search by Properties

**🔍 Define properties first — it gives you the correct column types and indexes.**

```php
// ✅ RECOMMENDED: Search with defined properties (uses correct column types)
$activeUsers = User::whereProperty('status', 'active')->get();
$youngUsers = User::whereProperty('age', '<', 30)->get();

// Find users by multiple properties
$users = User::whereProperties([
    'status' => 'active',
    'age' => 25
])->get();

// The query scope still accepts undefined properties with a best-effort
// type guess, but PropertyService::search() throws PropertyNotFoundException
// for them instead of silently matching every entity.
$results = User::whereProperty('undefined_prop', 'some_value')->get();
```

## ⚠️ Common Pitfalls and Warnings

### 1. Property Definition Required for Setting Values
```php
// ❌ WRONG - Will throw PropertyNotFoundException
$user->setDynamicProperty('new_field', 'value'); // Property 'new_field' doesn't exist

// ✅ CORRECT - Create property definition first
Property::create([
    'name' => 'new_field',
    'label' => 'New Field',
    'type' => 'text'
]);
$user->setDynamicProperty('new_field', 'value'); // Now it works
```

### 2. Type Safety Depends on Property Definitions
```php
// ✅ With property definition - Type safe
Property::create(['name' => 'score', 'type' => 'number']);
$users = User::whereProperty('score', '>', 80); // Uses number_value column correctly

// ⚠️ Without property definition - best-effort type detection (query scope only)
$users = User::whereProperty('undefined_score', '>', 80);

// ❌ PropertyService::search() fails closed for undefined properties
$propertyService->search(User::class, ['undefined_score' => ['value' => 80, 'operator' => '>']]);
// throws PropertyNotFoundException
```

### 3. Validation Only Works with Property Definitions
```php
// ✅ With validation rules
Property::create([
    'name' => 'email',
    'type' => 'text',
    'validation' => ['email', 'required']
]);
$user->setDynamicProperty('email', 'invalid-email'); // Throws PropertyValidationException

// ❌ Without property definition - No validation possible
// (Would throw PropertyNotFoundException before validation could occur)
```

### 4. Performance Impact
```php
// ✅ FAST - Uses the correct column and indexes, with a bound LIKE value
Property::create(['name' => 'department', 'type' => 'text']);
$users = User::whereProperty('department', 'engineering'); // Optimized query

// ⚠️ SLOWER - Uses fallback type detection
$users = User::whereProperty('undefined_dept', 'engineering'); // Less optimal
```

## Advanced Usage

### Property Types and Validation

#### Text Properties
```php
Property::create([
    'name' => 'bio',
    'label' => 'Biography',
    'type' => 'text',
    'validation' => [
        'min' => 10,        // Minimum length
        'max' => 500,       // Maximum length
        'required' => true  // Required field
    ]
]);
```

#### Number Properties
```php
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
```

#### Date Properties
```php
Property::create([
    'name' => 'hire_date',
    'label' => 'Hire Date',
    'type' => 'date',
    'validation' => [
        'after' => '2020-01-01',
        'before' => 'today'
    ]
]);
```

#### Boolean Properties
```php
Property::create([
    'name' => 'newsletter_subscribed',
    'label' => 'Newsletter Subscription',
    'type' => 'boolean',
    'required' => false
]);
```

#### Select Properties
```php
Property::create([
    'name' => 'department',
    'label' => 'Department',
    'type' => 'select',
    'options' => ['engineering', 'marketing', 'sales', 'hr'],
    'required' => true
]);
```

### Performance Optimization

#### JSON Column Caching

For maximum performance, add a JSON column to your existing tables:

```php
// In a migration
Schema::table('users', function (Blueprint $table) {
    $table->json('dynamic_properties')->nullable();
});

Schema::table('companies', function (Blueprint $table) {
    $table->json('dynamic_properties')->nullable();
});
```

The package writes that column directly on every property write, so you do
**not** need to add `dynamic_properties` to `$fillable`. Adding an `array` cast
is optional but recommended for convenient `$user->dynamic_properties` access:

```php
protected $casts = ['dynamic_properties' => 'array'];
```

This provides:
- Property reads without touching `entity_properties` (no second query)
- Automatic synchronization when properties change, including renames and
  type changes
- Transparent fallback to the EAV table when the column is absent, or when
  `database.enable_json_cache` is `false`

> **Note:** the JSON cache is a read optimisation, not the source of truth. If
> you backfill or repair it, use `php artisan dynamic-properties:sync-cache --all`.

#### Search Performance

The package automatically creates optimized indexes:

```sql
-- Indexes for fast property search
INDEX idx_string_search (entity_type, property_name, string_value)
INDEX idx_number_search (entity_type, property_name, number_value)
INDEX idx_date_search (entity_type, property_name, date_value)
INDEX idx_boolean_search (entity_type, property_name, boolean_value)
FULLTEXT INDEX ft_string_content (string_value)  -- MySQL only
```

#### Avoiding N+1 Reads

Reading `$user->properties` loads one property query per model. Eager-load the
relation instead:

```php
$users = User::withProperties()->get();

foreach ($users as $user) {
    // No additional query: the entityProperties relation is already loaded.
    $properties = $user->properties;
}

// Or batch-load an arbitrary collection in a single query:
$properties = app(PropertyService::class)->getPropertiesFor($users);
```

### Advanced Search

#### Complex Queries
```php
use SolutionForest\LaravelDynamicProperties\Services\PropertyService;

$propertyService = app(PropertyService::class);

// Advanced search with operators
$results = $propertyService->search('App\\Models\\User', [
    'age' => ['value' => 25, 'operator' => '>='],
    'salary' => ['value' => 50000, 'operator' => '>'],
    'status' => 'active'
]);
```

#### Text Search
```php
// Full-text search on text properties
$users = User::whereRaw(
    "EXISTS (SELECT 1 FROM entity_properties ep WHERE ep.entity_id = users.id 
     AND ep.entity_type = ? AND MATCH(ep.string_value) AGAINST(? IN BOOLEAN MODE))",
    ['App\\Models\\User', '+marketing +manager']
)->get();
```

### Error Handling

The package provides comprehensive error handling:

```php
use SolutionForest\LaravelDynamicProperties\Exceptions\PropertyNotFoundException;
use SolutionForest\LaravelDynamicProperties\Exceptions\PropertyValidationException;

try {
    $user->setDynamicProperty('nonexistent_property', 'value');
} catch (PropertyNotFoundException $e) {
    // Handle property not found
    echo "Property not found: " . $e->getMessage();
}

try {
    $user->setDynamicProperty('age', 'invalid_number');
} catch (PropertyValidationException $e) {
    // Handle validation error
    echo "Validation failed: " . $e->getMessage();
}
```

### Artisan Commands

The package includes helpful Artisan commands:

```bash
  dynamic-properties:create       Create a new dynamic property
  dynamic-properties:delete       Delete a dynamic property and all its values
  dynamic-properties:list         List all dynamic properties
  dynamic-properties:optimize-db  Optimize database for dynamic properties with database-specific enhancements
  dynamic-properties:sync-cache   Synchronize JSON cache columns with entity properties
```

## API Reference

### HasProperties Trait

#### Methods

**setDynamicProperty(string $name, mixed $value): void**
- Sets a single property value
- Validates the value against property rules
- Updates JSON cache if available

**getDynamicProperty(string $name): mixed**
- Retrieves a single property value
- Returns null if property doesn't exist

**setProperties(array $properties): void**
- Sets multiple properties at once
- More efficient than multiple setDynamicProperty calls

**getPropertiesAttribute(): array**
- Returns all properties as an associative array
- Uses JSON cache when available, falls back to EAV queries

#### Magic Methods

**__get($key): mixed**
- Access properties with `prop_` prefix
- Example: `$user->prop_phone` gets the 'phone' property

**__set($key, mixed $value): void**
- Set properties with `prop_` prefix  
- Example: `$user->prop_phone = '+1234567890'` sets the 'phone' property

### Query Scopes

**whereProperty(string $name, string $operator = '=', mixed $value = null): Builder**
- Filter entities by a single property
- The two-argument shorthand `whereProperty($name, $value)` compares for equality
- Supports operators: =, !=, <>, <, >, <=, >=, LIKE, ILIKE
- `whereProperty($name, '=', null)` matches `NULL` values

**whereProperties(array $properties): Builder**
- Filter entities by multiple properties
- Uses AND logic between properties

**withProperties(): Builder**
- Eager-loads the `entityProperties` relation to avoid N+1 reads

**wherePropertyNull(string $name): Builder** / **wherePropertyNotNull(string $name): Builder**
- Filter entities whose property value is, or is not, `NULL`

### PropertyService

**setDynamicProperty(Model $entity, string $name, mixed $value): void**
- Core method for setting property values
- Handles validation and storage

**setProperties(Model $entity, array $properties): void**
- Set multiple properties efficiently

**findProperty(string $name): ?Property**
- Resolve a definition, using the configured definition cache

**getPropertiesFor(Collection $entities): array**
- Load the properties of many entities in a single query

**search(string $entityType, array $filters): Collection**
- Advanced search with complex criteria
- Supports multiple operators and property types
- Throws `PropertyNotFoundException` for undefined properties

## Performance Characteristics

### Single Entity Property Retrieval

| Method | Queries per entity | Use Case |
|--------|-------------------|----------|
| JSON column cache | 0 (already on the row) | Entities with many properties (50+) |
| EAV fallback | 1 | Entities with few properties, or no cache column |
| Eager loaded (`withProperties()`) | 1 total for the whole collection | Listing pages |
| Mixed access | Automatic | Transparent performance optimisation |

### Search Performance

| Dataset Size | Single Property | Multiple Properties | Full-Text Search |
|--------------|----------------|-------------------|------------------|
| 1K entities | < 10ms | < 50ms | < 100ms |
| 10K entities | < 50ms | < 200ms | < 500ms |
| 100K entities | < 200ms | < 1s | < 2s |

### Memory Usage

- **Property definitions**: ~1KB per property
- **Entity properties**: ~100 bytes per property value
- **JSON cache**: ~50% reduction in query overhead

## Database Compatibility

### MySQL (Recommended)
- Full JSON support with native functions
- Full-text search capabilities
- Optimal performance with all features

### PostgreSQL
- JSON/JSONB support and GIN indexes
- Full-text search through `whereFullText()`
- All core functionality supported

### SQLite
- JSON stored as TEXT with JSON1 extension
- Text search with LIKE queries
- All core functionality supported

## Configuration

Publish the config file to customise behaviour:

```php
// config/dynamic-properties.php
return [
    'database' => [
        // Write the optional dynamic_properties JSON column when it exists.
        'enable_json_cache' => env('DYNAMIC_PROPERTIES_JSON_CACHE', true),
    ],

    'performance' => [
        // Cache property definitions between operations.
        'cache_property_definitions' => env('DYNAMIC_PROPERTIES_CACHE_DEFINITIONS', true),

        // Definition cache TTL in seconds.
        'cache_ttl' => env('DYNAMIC_PROPERTIES_CACHE_TTL', 3600),

        // Batch size for bulk JSON cache syncing.
        'batch_size' => env('DYNAMIC_PROPERTIES_BATCH_SIZE', 100),
    ],
];
```

All keys are optional: the package uses these defaults when the config file is
not published.

## Validation Rules

Definitions are validated on both `Property::create()` and
`PropertyService::createProperty()`. Supported rules:

| Type | Rules |
|------|-------|
| All | `required` (boolean, alias for the `required` column), `email`, `url` (text only) |
| text, select | `min`, `max`, `min_length`, `max_length`, `email`, `url` |
| number | `min`, `max`, `decimal_places`, `integer` |
| date | `after`, `before` (`today` or a date string) |

Rules can be declared as a map (`['min' => 3]`) or a list (`['required', 'email']`).

## Value Semantics

- Supported boolean inputs are `true`, `false`, `1`, `0`, `'1'`, and `'0'`.
  Strings like `'true'` are rejected rather than coerced.
- Setting an optional property to `null` stores an explicit `NULL` row; the key
  remains in `$model->properties`. Use `removeProperty($name)` to delete the
  value entirely.
- Renaming a property cascades to existing value rows and JSON caches. Changing
  its type re-casts every stored value, and invalid values abort the change.
- `number` values are stored in a `decimal(15, 4)` column; values outside that
  range/precision are lossy.

## Troubleshooting

### Common Errors and Solutions

#### `PropertyNotFoundException`
```php
// Error: "Property 'phone' not found"
$user->setDynamicProperty('phone', '+1234567890');
```
**Solution**: Create the property definition first:
```php
Property::create(['name' => 'phone', 'label' => 'Phone', 'type' => 'text']);
$user->setDynamicProperty('phone', '+1234567890'); // Now works
```

#### `PropertyValidationException`
```php
// Error: "Validation failed for property 'age'"
$user->setDynamicProperty('age', -5);
```
**Solution**: Check property validation rules:
```php
$property = Property::where('name', 'age')->first();
var_dump($property->validation); // See what rules are defined
$user->setDynamicProperty('age', 25); // Use valid value
```

#### Inconsistent Search Results
```php
// Getting different results for the same logical query
$users1 = User::whereProperty('level', '>', 5)->get();   // 10 results
$users2 = User::whereProperty('level', '>', '5')->get(); // 3 results
```
**Solution**: This happens when the property definition is missing. Create it:
```php
Property::create(['name' => 'level', 'type' => 'number']);
// Now both queries will return the same results
```
`PropertyService::search()` never falls back: an undefined property throws
`PropertyNotFoundException` instead of matching every entity.

## Testing

The package includes comprehensive tests. Run them with:

```bash
# Run all tests
./vendor/bin/pest

# Run specific test suites
./vendor/bin/pest tests/Unit
./vendor/bin/pest tests/Feature

# Run with coverage
./vendor/bin/pest --coverage
```

## Contributing

Please see [CONTRIBUTING.md](CONTRIBUTING.md) for details on how to contribute to this project.

## License

This package is open-sourced software licensed under the [MIT license](LICENSE).

## Changelog

Please see [CHANGELOG.md](CHANGELOG.md) for recent changes.

## Documentation

- **[Installation Guide](INSTALLATION.md)** - Detailed installation and setup instructions
- **[API Documentation](docs/API.md)** - Complete API reference for all classes and methods
- **[Usage Examples](docs/EXAMPLES.md)** - Comprehensive examples for common and advanced scenarios
- **[Performance Guide](docs/PERFORMANCE.md)** - Optimization strategies and performance benchmarks
- **[Contributing Guide](CONTRIBUTING.md)** - How to contribute to the project
- **[Changelog](CHANGELOG.md)** - Version history and changes

## Credits
- [All Contributors](../../contributors)
