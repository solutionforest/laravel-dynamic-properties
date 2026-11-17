<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Database Configuration
    |--------------------------------------------------------------------------
    |
    | Enable the JSON cache column. When enabled, the package writes a
    | `dynamic_properties` JSON column on an entity table (if that table has
    | one) on every write, so reads can skip the entity_properties query.
    |
    */

    'database' => [
        'enable_json_cache' => env('DYNAMIC_PROPERTIES_JSON_CACHE', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Performance Settings
    |--------------------------------------------------------------------------
    |
    | Property definitions are read on every write and search. They can be
    | cached per request/process for the configured TTL.
    |
    */

    'performance' => [
        'cache_property_definitions' => env('DYNAMIC_PROPERTIES_CACHE_DEFINITIONS', true),

        // Cache TTL in seconds (default: 1 hour).
        'cache_ttl' => env('DYNAMIC_PROPERTIES_CACHE_TTL', 3600),

        // Batch size for bulk cache syncing.
        'batch_size' => env('DYNAMIC_PROPERTIES_BATCH_SIZE', 100),
    ],
];
