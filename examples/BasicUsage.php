<?php

/**
 * Basic Usage Examples for Dynamic Properties Package
 *
 * This file demonstrates common usage patterns for the Dynamic Properties package.
 * These examples assume you have installed the package and run the migrations.
 */

use App\Models\User;
use SolutionForest\LaravelDynamicProperties\Facades\DynamicProperties;
use SolutionForest\LaravelDynamicProperties\Models\Property;
use SolutionForest\LaravelDynamicProperties\Services\PropertyService; // Assuming User model uses HasProperties trait

// Example 1: Creating Properties Programmatically
function createProperties()
{
    // Create different types of properties
    Property::create([
        'name'       => 'phone',
        'type'       => 'text',
        'label'      => 'Phone Number',
        'required'   => true,
        'validation' => ['min_length' => 10, 'max_length' => 15],
    ]);

    Property::create([
        'name'       => 'age',
        'type'       => 'number',
        'label'      => 'Age',
        'required'   => false,
        'validation' => ['min' => 0, 'max' => 120],
    ]);

    Property::create([
        'name'     => 'status',
        'type'     => 'select',
        'label'    => 'User Status',
        'required' => true,
        'options'  => ['active', 'inactive', 'pending', 'suspended'],
    ]);

    Property::create([
        'name'     => 'verified',
        'type'     => 'boolean',
        'label'    => 'Email Verified',
        'required' => false,
    ]);

    Property::create([
        'name'     => 'birth_date',
        'type'     => 'date',
        'label'    => 'Birth Date',
        'required' => false,
    ]);
}

// Example 2: Setting Properties on Models
function setUserProperties()
{
    $user = User::find(1);

    // Method 1: Using trait methods
    $user->setDynamicProperty('phone', '+1234567890');
    $user->setDynamicProperty('age', 25);
    $user->setDynamicProperty('status', 'active');
    $user->setDynamicProperty('verified', true);
    $user->setDynamicProperty('birth_date', '1999-01-15');

    // Method 2: Using magic methods (prop_ prefix)
    $user->prop_phone = '+1234567890';
    $user->prop_age = 25;
    $user->prop_status = 'active';
    $user->prop_verified = true;
    $user->prop_birth_date = '1999-01-15';

    // Method 3: Setting multiple properties at once
    $user->setProperties([
        'phone'      => '+1234567890',
        'age'        => 25,
        'status'     => 'active',
        'verified'   => true,
        'birth_date' => '1999-01-15',
    ]);

    // Method 4: Using the facade
    DynamicProperties::setProperties($user, [
        'phone'      => '+1234567890',
        'age'        => 25,
        'status'     => 'active',
        'verified'   => true,
        'birth_date' => '1999-01-15',
    ]);
}

// Example 3: Getting Properties from Models
function getUserProperties()
{
    $user = User::find(1);

    // Method 1: Get single property
    $phone = $user->getDynamicProperty('phone');
    $age = $user->prop_age; // Magic method

    // Method 2: Get all properties
    $allProperties = $user->properties;

    // Method 3: Using the facade
    $phone = DynamicProperties::getDynamicProperty($user, 'phone');
    $allProperties = DynamicProperties::getProperties($user);

    return [
        'phone' => $phone,
        'age'   => $age,
        'all'   => $allProperties,
    ];
}

// Example 4: Searching Users by Properties
function searchUsersByProperties()
{
    // Simple property search using query scopes
    $activeUsers = User::whereProperty('status', 'active')->get();
    $verifiedUsers = User::whereProperty('verified', true)->get();
    $youngUsers = User::whereProperty('age', '<', 30)->get();

    // Multiple property search (AND logic)
    $activeVerifiedUsers = User::whereProperties([
        'status'   => 'active',
        'verified' => true,
    ])->get();

    // Advanced search using PropertyService
    $propertyService = app(PropertyService::class);

    // Search with operators
    $results = $propertyService->search('App\\Models\\User', [
        'age'      => ['value' => 25, 'operator' => '>='],
        'status'   => 'active',
        'verified' => true,
    ]);

    // Get the actual User models
    $users = User::whereIn('id', $results)->get();

    // Text search with options
    $textResults = $propertyService->searchText(
        'App\\Models\\User',
        'bio', // Assuming you have a bio text property
        'developer',
        ['full_text' => true, 'case_sensitive' => false]
    );

    // Number range search
    $ageRangeResults = $propertyService->searchNumberRange(
        'App\\Models\\User',
        'age',
        25, // min age
        35  // max age
    );

    // Date range search
    $dateRangeResults = $propertyService->searchDateRange(
        'App\\Models\\User',
        'birth_date',
        '1990-01-01',
        '2000-12-31'
    );

    return [
        'active'          => $activeUsers,
        'verified'        => $verifiedUsers,
        'young'           => $youngUsers,
        'active_verified' => $activeVerifiedUsers,
        'advanced_search' => $users,
        'text_search'     => User::whereIn('id', $textResults)->get(),
        'age_range'       => User::whereIn('id', $ageRangeResults)->get(),
        'date_range'      => User::whereIn('id', $dateRangeResults)->get(),
    ];
}

// Example 5: Advanced Search with OR Logic
function advancedSearchExamples()
{
    $propertyService = app(PropertyService::class);

    // OR logic search - users who are either young OR verified
    $results = $propertyService->advancedSearch('App\\Models\\User', [
        'age'      => ['value' => 25, 'operator' => '<'],
        'verified' => true,
    ], 'OR');

    // Complex search with BETWEEN operator
    $complexResults = $propertyService->search('App\\Models\\User', [
        'age' => [
            'operator' => 'between',
            'min'      => 25,
            'max'      => 35,
        ],
        'status' => [
            'operator' => 'in',
            'value'    => ['active', 'pending'],
        ],
    ]);

    // Text search with LIKE operator
    $likeResults = $propertyService->search('App\\Models\\User', [
        'bio' => [
            'operator' => 'like',
            'value'    => 'developer',
            'options'  => ['case_sensitive' => false],
        ],
    ]);

    return [
        'or_logic'    => User::whereIn('id', $results)->get(),
        'complex'     => User::whereIn('id', $complexResults)->get(),
        'like_search' => User::whereIn('id', $likeResults)->get(),
    ];
}

// Example 6: Working with JSON Cache
function jsonCacheExamples()
{
    $user = User::find(1);

    // If your users table has a 'dynamic_properties' JSON column,
    // properties will be cached there automatically for fast access

    // Set properties (automatically syncs to JSON cache if column exists)
    $user->setProperties([
        'phone'  => '+1234567890',
        'age'    => 25,
        'status' => 'active',
    ]);

    // Get properties (reads from JSON cache if available, falls back to entity_properties table)
    $properties = $user->properties; // Very fast if JSON cache exists

    // Manually sync JSON cache
    $propertyService = app(PropertyService::class);
    $propertyService->syncJsonColumn($user);

    // Sync all users' JSON cache
    $syncedCount = $propertyService->syncAllJsonColumns('App\\Models\\User', 100);

    return [
        'properties'   => $properties,
        'synced_count' => $syncedCount,
    ];
}

// Example 7: Property Validation
function propertyValidationExamples()
{
    $user = User::find(1);

    try {
        // This will validate against property definition
        $user->setDynamicProperty('age', 150); // Will fail if max validation is 120
    } catch (InvalidArgumentException $e) {
        echo 'Validation failed: '.$e->getMessage();
    }

    try {
        // This will fail if property doesn't exist
        $user->setDynamicProperty('nonexistent_property', 'value');
    } catch (InvalidArgumentException $e) {
        echo 'Property not found: '.$e->getMessage();
    }

    try {
        // This will fail for select properties with invalid options
        $user->setDynamicProperty('status', 'invalid_status');
    } catch (InvalidArgumentException $e) {
        echo 'Invalid option: '.$e->getMessage();
    }
}

// Example 8: Removing Properties
function removePropertyExamples()
{
    $user = User::find(1);

    // Remove a single property
    $propertyService = app(PropertyService::class);
    $propertyService->removeProperty($user, 'phone');

    // Or using facade
    DynamicProperties::removeProperty($user, 'age');

    // The property value is removed from entity_properties table
    // and JSON cache is automatically updated if it exists
}

// Example 9: Bulk Operations
function bulkOperationExamples()
{
    // Set properties for multiple users
    $users = User::limit(100)->get();

    foreach ($users as $user) {
        $user->setProperties([
            'status'       => 'active',
            'verified'     => true,
            'last_updated' => now()->toDateString(),
        ]);
    }

    // Search and update
    $propertyService = app(PropertyService::class);
    $inactiveUserIds = $propertyService->search('App\\Models\\User', [
        'status' => 'inactive',
    ]);

    $inactiveUsers = User::whereIn('id', $inactiveUserIds)->get();
    foreach ($inactiveUsers as $user) {
        $user->setDynamicProperty('status', 'pending');
    }
}

// Example 10: Performance Monitoring
function performanceExamples()
{
    $user = User::find(1);

    // Measure property access performance
    $start = microtime(true);
    $properties = $user->properties; // Should be <1ms with JSON cache, ~20ms without
    $end = microtime(true);

    $accessTime = ($end - $start) * 1000; // Convert to milliseconds

    echo "Property access took: {$accessTime}ms\n";

    // Measure search performance
    $propertyService = app(PropertyService::class);

    $start = microtime(true);
    $results = $propertyService->search('App\\Models\\User', [
        'status'   => 'active',
        'verified' => true,
    ]);
    $end = microtime(true);

    $searchTime = ($end - $start) * 1000;

    echo "Search took: {$searchTime}ms for ".$results->count()." results\n";
}
