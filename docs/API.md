# API Documentation

## Table of Contents

- [Models](#models)
  - [Property](#property-model)
  - [EntityProperty](#entityproperty-model)
- [Traits](#traits)
  - [HasProperties](#hasproperties-trait)
- [Services](#services)
  - [PropertyService](#propertyservice)
  - [PropertyValidationService](#propertyvalidationservice)
- [Exceptions](#exceptions)
- [Facades](#facades)
- [Artisan Commands](#artisan-commands)

## Models

### Property Model

The `Property` model represents a property definition in the system.

#### Properties

| Property | Type | Description |
|----------|------|-------------|
| `id` | `int` | Primary key |
| `name` | `string` | Unique property name (used as identifier) |
| `label` | `string` | Human-readable label |
| `type` | `string` | Property type: `text`, `number`, `date`, `boolean`, `select` |
| `required` | `boolean` | Whether the property is required |
| `options` | `array\|null` | Available options for select type properties |
| `validation` | `array\|null` | Validation rules for the property |

#### Methods

**create(array $attributes): Property**
```php
Property::create([
    'name' => 'phone',
    'label' => 'Phone Number',
    'type' => 'text',
    'required' => false,
    'validation' => ['min' => 10, 'max' => 15]
]);
```

**validateValue(mixed $value): bool**
```php
$property = Property::find(1);
$isValid = $property->validateValue('+1234567890');
```

**getValidationRules(): array**
```php
$property = Property::find(1);
$rules = $property->getValidationRules();
// Returns Laravel validation rules array
```

#### Relationships

**entityProperties(): HasMany**
```php
$property = Property::find(1);
$entityProperties = $property->entityProperties;
```

#### Scopes

**ofType(string $type): Builder**
```php
$textProperties = Property::ofType('text')->get();
```

**required(): Builder**
```php
$requiredProperties = Property::required()->get();
```

### EntityProperty Model

The `EntityProperty` model represents a property value for a specific entity.

#### Properties

| Property | Type | Description |
|----------|------|-------------|
| `id` | `int` | Primary key |
| `entity_id` | `int` | ID of the entity that owns this property |
| `entity_type` | `string` | Class name of the entity |
| `property_id` | `int` | Foreign key to properties table |
| `property_name` | `string` | Denormalized property name for performance |
| `string_value` | `string\|null` | Value for text/select properties |
| `number_value` | `decimal\|null` | Value for number properties |
| `date_value` | `date\|null` | Value for date properties |
| `boolean_value` | `boolean\|null` | Value for boolean properties |

#### Methods

**getValueAttribute(): mixed**
```php
$entityProperty = EntityProperty::find(1);
$value = $entityProperty->value; // Returns the appropriate typed value
```

**setValueAttribute(mixed $value): void**
```php
$entityProperty = new EntityProperty();
$entityProperty->value = 'some value'; // Sets the appropriate column based on property type
```

#### Relationships

**entity(): MorphTo**
```php
$entityProperty = EntityProperty::find(1);
$entity = $entityProperty->entity; // Returns the owning entity (User, Company, etc.)
```

**property(): BelongsTo**
```php
$entityProperty = EntityProperty::find(1);
$property = $entityProperty->property; // Returns the Property definition
```

#### Scopes

**forEntity(Model $entity): Builder**
```php
$properties = EntityProperty::forEntity($user)->get();
```

**ofProperty(string $propertyName): Builder**
```php
$phoneProperties = EntityProperty::ofProperty('phone')->get();
```

## Traits

### HasProperties Trait

Add dynamic property functionality to any Eloquent model.

#### Usage

```php
use YourVendor\DynamicProperties\Traits\HasProperties;

class User extends Model
{
    use HasProperties;
}
```

#### Methods

**setProperty(string $name, mixed $value): void**

Sets a single property value with validation.

```php
$user->setDynamicProperty('phone', '+1234567890');
```

**Parameters:**
- `$name` (string): Property name
- `$value` (mixed): Property value

**Throws:**
- `PropertyNotFoundException`: If property doesn't exist
- `PropertyValidationException`: If value fails validation

---

**getDynamicProperty(string $name): mixed**

Retrieves a single property value.

```php
$phone = $user->getDynamicProperty('phone');
```

**Parameters:**
- `$name` (string): Property name

**Returns:** Property value or `null` if not set

---

**setProperties(array $properties): void**

Sets multiple properties at once.

```php
$user->setProperties([
    'phone' => '+1234567890',
    'age' => 25,
    'active' => true
]);
```

**Parameters:**
- `$properties` (array): Associative array of property names and values

---

**getPropertiesAttribute(): array**

Returns all properties as an associative array.

```php
$allProperties = $user->properties;
```

**Returns:** Array of property name => value pairs

---

**hasProperty(string $name): bool**

Checks if a property is set for the entity.

```php
$hasPhone = $user->hasProperty('phone');
```

**Parameters:**
- `$name` (string): Property name

**Returns:** `true` if property is set, `false` otherwise

---

**removeProperty(string $name): void**

Removes a property value from the entity.

```php
$user->removeProperty('phone');
```

**Parameters:**
- `$name` (string): Property name

#### Magic Methods

**__get(string $key): mixed**

Access properties using the `prop_` prefix.

```php
$phone = $user->prop_phone; // Equivalent to $user->getDynamicProperty('phone')
```

**__set(string $key, mixed $value): void**

Set properties using the `prop_` prefix.

```php
$user->prop_phone = '+1234567890'; // Equivalent to $user->setDynamicProperty('phone', '+1234567890')
```

**__isset(string $key): bool**

Check if a property is set using the `prop_` prefix.

```php
$hasPhone = isset($user->prop_phone); // Equivalent to $user->hasProperty('phone')
```

**__unset(string $key): void**

Remove a property using the `prop_` prefix.

```php
unset($user->prop_phone); // Equivalent to $user->removeProperty('phone')
```

#### Relationships

**entityProperties(): MorphMany**

Returns all EntityProperty records for this entity.

```php
$entityProperties = $user->entityProperties;
```

#### Query Scopes

**whereProperty(string $name, mixed $value, string $operator = '='): Builder**

Filter entities by a single property value.

```php
$activeUsers = User::whereProperty('active', true)->get();
$youngUsers = User::whereProperty('age', '<', 30)->get();
```

**Parameters:**
- `$name` (string): Property name
- `$value` (mixed): Property value to compare
- `$operator` (string): Comparison operator (=, !=, <, >, <=, >=, LIKE)

---

**whereProperties(array $properties): Builder**

Filter entities by multiple property values (AND logic).

```php
$users = User::whereProperties([
    'active' => true,
    'age' => 25,
    'department' => 'engineering'
])->get();
```

**Parameters:**
- `$properties` (array): Associative array of property names and values

---

**wherePropertyIn(string $name, array $values): Builder**

Filter entities where property value is in the given array.

```php
$users = User::wherePropertyIn('department', ['engineering', 'marketing'])->get();
```

**Parameters:**
- `$name` (string): Property name
- `$values` (array): Array of values to match

---

**wherePropertyNull(string $name): Builder**

Filter entities where property is not set.

```php
$usersWithoutPhone = User::wherePropertyNull('phone')->get();
```

**Parameters:**
- `$name` (string): Property name

---

**wherePropertyNotNull(string $name): Builder**

Filter entities where property is set.

```php
$usersWithPhone = User::wherePropertyNotNull('phone')->get();
```

**Parameters:**
- `$name` (string): Property name

## Services

### PropertyService

Core service for managing dynamic properties.

#### Methods

**setProperty(Model $entity, string $name, mixed $value): void**

Sets a property value for an entity.

```php
$propertyService = app(PropertyService::class);
$propertyService->setDynamicProperty($user, 'phone', '+1234567890');
```

**Parameters:**
- `$entity` (Model): The entity to set the property on
- `$name` (string): Property name
- `$value` (mixed): Property value

**Throws:**
- `PropertyNotFoundException`: If property doesn't exist
- `PropertyValidationException`: If value fails validation

---

**getDynamicProperty(Model $entity, string $name): mixed**

Gets a property value for an entity.

```php
$phone = $propertyService->getDynamicProperty($user, 'phone');
```

**Parameters:**
- `$entity` (Model): The entity to get the property from
- `$name` (string): Property name

**Returns:** Property value or `null` if not set

---

**setProperties(Model $entity, array $properties): void**

Sets multiple properties for an entity.

```php
$propertyService->setProperties($user, [
    'phone' => '+1234567890',
    'age' => 25
]);
```

**Parameters:**
- `$entity` (Model): The entity to set properties on
- `$properties` (array): Associative array of property names and values

---

**getProperties(Model $entity): array**

Gets all properties for an entity.

```php
$properties = $propertyService->getProperties($user);
```

**Parameters:**
- `$entity` (Model): The entity to get properties from

**Returns:** Array of property name => value pairs

---

**search(string $entityType, array $filters): Collection**

Search entities by property values.

```php
$results = $propertyService->search('App\\Models\\User', [
    'age' => ['value' => 25, 'operator' => '>='],
    'active' => true,
    'department' => ['value' => ['engineering', 'marketing'], 'operator' => 'IN']
]);
```

**Parameters:**
- `$entityType` (string): Fully qualified class name of the entity
- `$filters` (array): Search criteria

**Filter Format:**
```php
[
    'property_name' => 'simple_value',
    'property_name' => [
        'value' => 'comparison_value',
        'operator' => '>=', // =, !=, <, >, <=, >=, LIKE, IN, NOT IN
    ]
]
```

**Returns:** Collection of matching entities

---

**syncJsonColumn(Model $entity): void**

Synchronizes the JSON cache column for an entity.

```php
$propertyService->syncJsonColumn($user);
```

**Parameters:**
- `$entity` (Model): The entity to sync

### PropertyValidationService

Service for validating property values.

#### Methods

**validate(Property $property, mixed $value): bool**

Validates a value against a property's rules.

```php
$validationService = app(PropertyValidationService::class);
$property = Property::find(1);
$isValid = $validationService->validate($property, '+1234567890');
```

**Parameters:**
- `$property` (Property): The property definition
- `$value` (mixed): The value to validate

**Returns:** `true` if valid, `false` otherwise

---

**validateWithMessages(Property $property, mixed $value): array**

Validates a value and returns validation messages.

```php
$result = $validationService->validateWithMessages($property, 'invalid_value');
// Returns: ['valid' => false, 'messages' => ['Phone number must be at least 10 characters']]
```

**Parameters:**
- `$property` (Property): The property definition
- `$value` (mixed): The value to validate

**Returns:** Array with 'valid' boolean and 'messages' array

## Exceptions

### PropertyException

Base exception class for all property-related errors.

```php
use YourVendor\DynamicProperties\Exceptions\PropertyException;

try {
    // Property operations
} catch (PropertyException $e) {
    // Handle any property-related error
}
```

### PropertyNotFoundException

Thrown when trying to access a property that doesn't exist.

```php
use YourVendor\DynamicProperties\Exceptions\PropertyNotFoundException;

try {
    $user->setDynamicProperty('nonexistent', 'value');
} catch (PropertyNotFoundException $e) {
    echo "Property not found: " . $e->getPropertyName();
}
```

**Methods:**
- `getPropertyName(): string` - Returns the name of the property that wasn't found

### PropertyValidationException

Thrown when a property value fails validation.

```php
use YourVendor\DynamicProperties\Exceptions\PropertyValidationException;

try {
    $user->setDynamicProperty('age', 'not_a_number');
} catch (PropertyValidationException $e) {
    echo "Validation failed: " . $e->getMessage();
    $errors = $e->getValidationErrors(); // Array of validation messages
}
```

**Methods:**
- `getValidationErrors(): array` - Returns array of validation error messages
- `getPropertyName(): string` - Returns the name of the property that failed validation

### InvalidPropertyTypeException

Thrown when an invalid property type is specified.

```php
use YourVendor\DynamicProperties\Exceptions\InvalidPropertyTypeException;

try {
    Property::create(['name' => 'test', 'type' => 'invalid_type']);
} catch (InvalidPropertyTypeException $e) {
    echo "Invalid type: " . $e->getInvalidType();
    $validTypes = $e->getValidTypes(); // Array of valid types
}
```

**Methods:**
- `getInvalidType(): string` - Returns the invalid type that was specified
- `getValidTypes(): array` - Returns array of valid property types

### PropertyOperationException

Thrown when a property operation fails for system reasons.

```php
use YourVendor\DynamicProperties\Exceptions\PropertyOperationException;

try {
    // Some property operation
} catch (PropertyOperationException $e) {
    echo "Operation failed: " . $e->getMessage();
}
```

## Facades

### DynamicProperties Facade

Provides convenient access to PropertyService methods.

```php
use YourVendor\DynamicProperties\Facades\DynamicProperties;

// Set property
DynamicProperties::setDynamicProperty($user, 'phone', '+1234567890');

// Get property
$phone = DynamicProperties::getDynamicProperty($user, 'phone');

// Set multiple properties
DynamicProperties::setProperties($user, [
    'phone' => '+1234567890',
    'age' => 25
]);

// Get all properties
$properties = DynamicProperties::getProperties($user);

// Search
$results = DynamicProperties::search('App\\Models\\User', [
    'active' => true,
    'age' => ['value' => 25, 'operator' => '>=']
]);
```

## Artisan Commands

### properties:list

Lists all defined properties.

```bash
php artisan properties:list

# Options:
--type=text          # Filter by property type
--required           # Show only required properties
--format=table       # Output format: table, json
```

### properties:create

Interactive command to create a new property.

```bash
php artisan properties:create

# Or with options:
php artisan properties:create --name=phone --type=text --label="Phone Number"
```

**Options:**
- `--name`: Property name
- `--type`: Property type (text, number, date, boolean, select)
- `--label`: Display label
- `--required`: Make property required
- `--options`: JSON array of options for select type

### properties:delete

Delete a property and all its values.

```bash
php artisan properties:delete phone

# Options:
--force              # Skip confirmation prompt
```

### properties:cache-sync

Synchronize JSON cache columns for all entities.

```bash
php artisan properties:cache-sync

# Options:
--model=User         # Sync only specific model
--chunk=1000         # Process in chunks of specified size
--force              # Force sync even if cache is up to date
```

### properties:validate

Validate all property values against their definitions.

```bash
php artisan properties:validate

# Options:
--fix                # Attempt to fix invalid values
--model=User         # Validate only specific model
--property=phone     # Validate only specific property
```

### properties:export

Export property definitions and values.

```bash
php artisan properties:export

# Options:
--format=json        # Export format: json, csv, xlsx
--output=file.json   # Output file path
--model=User         # Export only specific model
--properties-only    # Export only property definitions, not values
```

### properties:import

Import property definitions and values.

```bash
php artisan properties:import file.json

# Options:
--format=json        # Import format: json, csv, xlsx
--merge              # Merge with existing properties
--validate           # Validate before importing
```