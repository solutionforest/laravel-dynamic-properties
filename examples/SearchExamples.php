<?php

/**
 * Examples of how to use the search functionality in the Dynamic Properties library
 */

use App\Models\User;
use DynamicProperties\Models\Property;
use DynamicProperties\Services\PropertyService; // Assuming User model uses HasProperties trait

// Initialize the service
$propertyService = new PropertyService;

// Example 1: Simple property search using query scopes
$activeUsers = User::whereProperty('active', true)->get();
$youngUsers = User::whereProperty('age', 25, '<')->get();
$usersInNY = User::whereProperty('city', 'New York', 'LIKE')->get();

// Example 2: Multiple property search using query scopes
$targetUsers = User::whereProperties([
    'active' => true,
    'age' => 21,
    'city' => 'New York',
])->get();

// Example 3: Advanced search using PropertyService
$entityIds = $propertyService->search(User::class, [
    'age' => ['value' => 30, 'operator' => '>'],
    'city' => 'New York',
    'active' => true,
]);

// Get the actual User models
$users = User::whereIn('id', $entityIds)->get();

// Example 4: Text search with options
$userIds = $propertyService->searchText(User::class, 'bio', 'developer', [
    'full_text' => true,
    'case_sensitive' => false,
]);

// Example 5: Number range search
$userIds = $propertyService->searchNumberRange(User::class, 'salary', 50000, 100000);

// Example 6: Date range search
$userIds = $propertyService->searchDateRange(
    User::class,
    'birth_date',
    '1990-01-01',
    '2000-12-31'
);

// Example 7: Boolean search
$activeUserIds = $propertyService->searchBoolean(User::class, 'active', true);

// Example 8: Advanced search with OR logic
$userIds = $propertyService->advancedSearch(User::class, [
    'city' => 'New York',
    'city' => 'Los Angeles',
], 'OR');

// Example 9: Complex search with multiple criteria
$userIds = $propertyService->search(User::class, [
    'age' => ['value' => 25, 'operator' => 'between', 'min' => 25, 'max' => 35],
    'skills' => ['value' => ['PHP', 'JavaScript'], 'operator' => 'in'],
    'bio' => ['value' => 'developer', 'operator' => 'like', 'options' => ['full_text' => true]],
]);

// Example 10: Using PropertyService for advanced searches
$usersWithPhone = $propertyService->search(User::class, [
    'phone' => ['operator' => 'not null'],
]);

// Example 11: Range searches using PropertyService
$youngAdults = $propertyService->searchNumberRange(User::class, 'age', 18, 25);
$recentSignups = $propertyService->searchDateRange(User::class, 'signup_date', '2024-01-01', '2024-12-31');

// Example 12: Multiple value searches
$techUsers = $propertyService->search(User::class, [
    'skills' => ['operator' => 'in', 'value' => ['PHP', 'JavaScript', 'Python']],
]);

// Example 13: Text search with different options
$developerUsers = $propertyService->searchText(User::class, 'job_title', 'developer');
$fullTextSearch = $propertyService->searchText(User::class, 'bio', 'full-stack developer', ['full_text' => true]);

echo "Search functionality examples completed!\n";
