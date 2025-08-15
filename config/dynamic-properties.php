<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default Property Settings
    |--------------------------------------------------------------------------
    |
    | These settings define the default behavior for dynamic properties.
    |
    */

    'defaults' => [
        'required' => false,
        'searchable' => true,
        'cacheable' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Database Configuration
    |--------------------------------------------------------------------------
    |
    | Configure database-specific optimizations and features.
    |
    */

    'database' => [
        // Enable JSON column caching for better performance
        'enable_json_cache' => env('DYNAMIC_PROPERTIES_JSON_CACHE', true),

        // Database-specific optimizations
        'mysql' => [
            'use_json_functions' => true,
            'enable_fulltext_search' => true,
        ],

        'sqlite' => [
            'use_json1_extension' => true,
            'fallback_to_like_search' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Performance Settings
    |--------------------------------------------------------------------------
    |
    | Configure performance-related settings.
    |
    */

    'performance' => [
        // Cache property definitions to reduce database queries
        'cache_property_definitions' => env('DYNAMIC_PROPERTIES_CACHE_DEFINITIONS', true),

        // Cache TTL in seconds (default: 1 hour)
        'cache_ttl' => env('DYNAMIC_PROPERTIES_CACHE_TTL', 3600),

        // Batch size for bulk operations
        'batch_size' => env('DYNAMIC_PROPERTIES_BATCH_SIZE', 100),
    ],

    /*
    |--------------------------------------------------------------------------
    | Validation Settings
    |--------------------------------------------------------------------------
    |
    | Configure validation behavior for properties.
    |
    */

    'validation' => [
        // Strict mode enforces all validation rules
        'strict_mode' => env('DYNAMIC_PROPERTIES_STRICT_MODE', true),

        // Maximum length for text properties (if not specified)
        'default_text_max_length' => 255,

        // Default number precision
        'default_number_precision' => 4,
    ],

    /*
    |--------------------------------------------------------------------------
    | API Settings
    |--------------------------------------------------------------------------
    |
    | Configure API behavior and responses.
    |
    */

    'api' => [
        // Include property metadata in API responses
        'include_metadata' => env('DYNAMIC_PROPERTIES_API_METADATA', false),

        // Default pagination size for property searches
        'default_page_size' => 50,

        // Maximum page size allowed
        'max_page_size' => 1000,
    ],
];
