# Installation Guide

This guide provides detailed instructions for installing and configuring the Laravel Dynamic Properties package.

## Table of Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Database Setup](#database-setup)
- [Performance Optimization](#performance-optimization)
- [Verification](#verification)
- [Troubleshooting](#troubleshooting)

## Requirements

### System Requirements

- **PHP**: 8.3 or higher
- **Laravel**: 12.0 or higher
- **Database**: MySQL 8.0+, PostgreSQL 12+, or SQLite 3.35+

### PHP Extensions

- `json` - For JSON property handling
- `pdo` - For database operations
- `mbstring` - For string operations

### Database-Specific Requirements

#### MySQL
- Version 8.0 or higher (for improved JSON support)
- `innodb_large_prefix` enabled (for longer indexes)

#### PostgreSQL
- Version 12 or higher (for improved JSON support)
- `pg_trgm` extension (optional, for full-text search)

#### SQLite
- Version 3.35 or higher
- JSON1 extension enabled

## Installation

### Step 1: Install via Composer

```bash
composer require solution-forest/laravel-dynamic-properties
```

### Step 2: Publish Package Assets

#### Publish Migrations
```bash
php artisan vendor:publish --provider="SolutionForest\LaravelDynamicProperties\DynamicPropertyServiceProvider" --tag="dynamic-properties-migrations"
```

#### Publish Configuration (Optional)
```bash
php artisan vendor:publish --provider="SolutionForest\LaravelDynamicProperties\DynamicPropertyServiceProvider" --tag="dynamic-properties-config"
```

#### Publish All Assets
```bash
php artisan vendor:publish --provider="SolutionForest\LaravelDynamicProperties\DynamicPropertyServiceProvider"
```

### Step 3: Run Migrations

```bash
php artisan migrate
```

This will create the following tables:
- `properties` - Property definitions
- `entity_properties` - Property values (EAV structure)

### Step 4: Add Facade (Optional)

Add the facade to `config/app.php`:

```php
'aliases' => [
    // Other aliases...
    'DynamicProperties' => SolutionForest\LaravelDynamicProperties\Facades\DynamicProperties::class,
],
```

## Configuration

### Basic Configuration

The package works out of the box with default settings. For custom configuration, publish the config file:

```bash
php artisan vendor:publish --provider="SolutionForest\LaravelDynamicProperties\DynamicPropertyServiceProvider" --tag="dynamic-properties-config"
```

This creates `config/dynamic-properties.php`:

```php
<?php

return [
    'database' => [
        // Write the optional dynamic_properties JSON column when the table has one.
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

### Environment Variables

```env
DYNAMIC_PROPERTIES_JSON_CACHE=true
DYNAMIC_PROPERTIES_CACHE_DEFINITIONS=true
DYNAMIC_PROPERTIES_CACHE_TTL=3600
DYNAMIC_PROPERTIES_BATCH_SIZE=100
```

## Database Setup

### Basic Setup

The package migrations create the necessary tables automatically:

```bash
php artisan migrate
```

### Custom Table Names

If you need custom table names, publish the migrations and modify them before running:

```bash
php artisan vendor:publish --provider="SolutionForest\LaravelDynamicProperties\DynamicPropertyServiceProvider" --tag="dynamic-properties-migrations"
```

Edit the migration files in `database/migrations/` to change table names:

```php
// In the migration file
Schema::create('custom_properties', function (Blueprint $table) {
    // Table definition...
});
```

Then update your configuration:

```php
// config/dynamic-properties.php
'table_names' => [
    'properties' => 'custom_properties',
    'entity_properties' => 'custom_entity_properties',
],
```

### Database-Specific Setup

#### MySQL Setup

1. **Ensure JSON Support**:
```sql
SELECT VERSION(); -- Should be 5.7+
```

2. **Configure for Large Indexes** (if needed):
```sql
SET GLOBAL innodb_large_prefix = 1;
SET GLOBAL innodb_file_format = 'Barracuda';
```

3. **Enable Full-Text Search** (optional):
```sql
-- This is handled automatically by migrations
-- but you can verify with:
SHOW INDEX FROM entity_properties WHERE Key_name = 'ft_string_content';
```

#### PostgreSQL Setup

1. **Install Extensions**:
```sql
-- For better text search (optional)
CREATE EXTENSION IF NOT EXISTS pg_trgm;

-- For UUID support (if using UUIDs)
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";
```

2. **Configure JSONB** (recommended):
```php
// In your migration, change:
$table->json('dynamic_properties');
// To:
$table->jsonb('dynamic_properties');
```

#### SQLite Setup

1. **Verify JSON1 Extension**:
```sql
SELECT json('{"test": "value"}'); -- Should return the JSON
```

2. **Enable WAL Mode** (recommended for performance):
```sql
PRAGMA journal_mode=WAL;
```

## Performance Optimization

### Enable JSON Caching

For optimal performance, add JSON cache columns to your existing tables:

#### Create Migration

```bash
php artisan make:migration add_dynamic_properties_to_users_table
```

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddDynamicPropertiesToUsersTable extends Migration
{
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('dynamic_properties')->nullable();
        });
    }

    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('dynamic_properties');
        });
    }
}
```

#### For Multiple Tables

```php
// Add to multiple tables at once
$tables = ['users', 'companies', 'contacts'];

foreach ($tables as $tableName) {
    Schema::table($tableName, function (Blueprint $table) {
        $table->json('dynamic_properties')->nullable();
    });
}
```

### Database Indexes

The package automatically creates optimized indexes, but you can add custom ones:

```sql
-- For frequently searched properties
CREATE INDEX idx_department_search ON entity_properties 
(entity_type, property_name, string_value) 
WHERE property_name = 'department';

-- For date range searches
CREATE INDEX idx_date_range ON entity_properties 
(entity_type, property_name, date_value) 
WHERE property_name IN ('hire_date', 'birth_date');
```

### Application-Level Caching

Configure Redis or Memcached for application caching:

```env
# .env
CACHE_DRIVER=redis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379
```

## Verification

### Test Installation

Create a simple test to verify everything is working:

```php
<?php

// In tinker or a test file
use SolutionForest\LaravelDynamicProperties\Models\Property;
use App\Models\User;

// Create a test property
$property = Property::create([
    'name' => 'test_property',
    'label' => 'Test Property',
    'type' => 'text',
    'required' => false
]);

// Add trait to User model (if not already added)
// Then test property operations
$user = User::first();
$user->setDynamicProperty('test_property', 'Hello World');
echo $user->getDynamicProperty('test_property'); // Should output: Hello World

// Clean up
$user->removeProperty('test_property');
$property->delete();
```

### Run Package Tests

If you've installed the package for development:

```bash
# Run all tests
./vendor/bin/pest

# Run specific test suites
./vendor/bin/pest tests/Unit
./vendor/bin/pest tests/Feature

# Run with coverage
./vendor/bin/pest --coverage
```

### Performance Test

Test performance with your data:

```bash
php artisan dynamic-properties:optimize-db
```

## Troubleshooting

### Common Issues

#### Migration Errors

**Error**: `SQLSTATE[42000]: Syntax error or access violation: 1071 Specified key was too long`

**Solution**: This occurs with older MySQL versions. Update your configuration:

```php
// config/dynamic-properties.php
'database_optimizations' => [
    'mysql' => [
        'index_length' => 191, // Reduced from default 255
    ],
],
```

Or upgrade to MySQL 5.7+ with `innodb_large_prefix` enabled.

#### JSON Support Issues

**Error**: `SQLSTATE[42000]: This version of MySQL doesn't yet support 'JSON'`

**Solution**: Upgrade to MySQL 5.7+ or use TEXT columns:

```php
// In migration
$table->text('dynamic_properties'); // Instead of json()
```

#### Memory Issues

**Error**: `Fatal error: Allowed memory size exhausted`

**Solution**: Optimize queries and enable JSON caching:

```php
// Use chunking for large datasets
User::chunk(100, function ($users) {
    foreach ($users as $user) {
        // Process user properties
    }
});

// Enable JSON caching
Schema::table('users', function (Blueprint $table) {
    $table->json('dynamic_properties')->nullable();
});
```

#### Performance Issues

**Problem**: Slow property queries

**Solutions**:

1. **Enable JSON caching**:
```bash
php artisan make:migration add_json_cache_to_users
```

2. **Check indexes**:
```sql
SHOW INDEX FROM entity_properties;
```

3. **Optimize queries**:
```php
// Use eager loading
$users = User::with('entityProperties')->get();

// Use select to limit columns
$users = User::select(['id', 'name', 'dynamic_properties'])->get();
```

### Debug Mode

Enable debug mode to see detailed query information:

```php
// In AppServiceProvider boot method
if (config('app.debug')) {
    DB::listen(function ($query) {
        if (str_contains($query->sql, 'entity_properties')) {
            Log::debug('Property Query', [
                'sql' => $query->sql,
                'bindings' => $query->bindings,
                'time' => $query->time
            ]);
        }
    });
}
```

### Getting Help

If you encounter issues not covered here:

1. **Check the logs**: `storage/logs/laravel.log`
2. **Enable debug mode**: Set `APP_DEBUG=true` in `.env`
3. **Check the schema**: `php artisan dynamic-properties:optimize-db --check`
4. **Check GitHub issues**: [Package Issues](https://github.com/solutionforest/laravel-dynamic-properties/issues)

### Uninstallation

To completely remove the package:

```bash
# Remove package
composer remove solution-forest/laravel-dynamic-properties

# Drop tables (WARNING: This will delete all data)
php artisan migrate:rollback --path=database/migrations/xxxx_create_properties_table.php
php artisan migrate:rollback --path=database/migrations/xxxx_create_entity_properties_table.php

# Remove published files
rm config/dynamic-properties.php
rm -rf database/migrations/*_create_properties_table.php
rm -rf database/migrations/*_create_entity_properties_table.php
```

## Next Steps

After successful installation:

> **⚠️ IMPORTANT**: Before using dynamic properties, you must create property definitions first!

### Quick Start

1. **Create your first properties**:
```bash
# Interactive property creation
php artisan dynamic-properties:create
```

Or programmatically:
```php
use SolutionForest\LaravelDynamicProperties\Models\Property;

Property::create([
    'name' => 'phone',
    'label' => 'Phone Number', 
    'type' => 'text'
]);
```

2. **Add the trait to your models**:
```php
use SolutionForest\LaravelDynamicProperties\Traits\HasProperties;

class User extends Model 
{
    use HasProperties;
}
```

3. **Start using properties**:
```php
$user = User::find(1);
$user->setDynamicProperty('phone', '+1234567890'); // ✅ Works
```

### Additional Resources

1. **Read the [Usage Examples](docs/EXAMPLES.md)** for common use cases
2. **Review the [API Documentation](docs/API.md)** for detailed method references  
3. **Check the [Performance Guide](docs/PERFORMANCE.md)** for optimization strategies
4. **Set up monitoring** for production environments

> **💡 Remember**: Always create Property definitions before setting values to avoid `PropertyNotFoundException`!