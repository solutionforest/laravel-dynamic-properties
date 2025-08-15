<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use SolutionForest\LaravelDynamicProperties\Models\EntityProperty;
use SolutionForest\LaravelDynamicProperties\Models\Property;
use SolutionForest\LaravelDynamicProperties\Services\PropertyService;

beforeEach(function () {
    // Create users table for testing
    Schema::create('users', function ($table) {
        $table->id();
        $table->string('name');
        $table->string('email');
        $table->timestamps();
    });

    $this->propertyService = new PropertyService;
    $this->dbCompat = $this->propertyService->getDatabaseCompatibilityService();

    // Create test properties
    $this->textProperty = Property::create([
        'name'     => 'description',
        'label'    => 'Description',
        'type'     => 'text',
        'required' => false,
    ]);

    $this->numberProperty = Property::create([
        'name'     => 'score',
        'label'    => 'Score',
        'type'     => 'number',
        'required' => false,
    ]);

    $this->dateProperty = Property::create([
        'name'     => 'created_date',
        'label'    => 'Created Date',
        'type'     => 'date',
        'required' => false,
    ]);

    $this->booleanProperty = Property::create([
        'name'     => 'active',
        'label'    => 'Active',
        'type'     => 'boolean',
        'required' => false,
    ]);

    // Create test entity properties
    EntityProperty::create([
        'entity_id'     => 1,
        'entity_type'   => 'App\\Models\\User',
        'property_id'   => $this->textProperty->id,
        'property_name' => 'description',
        'string_value'  => 'This is a test description with searchable content',
        'number_value'  => null,
        'date_value'    => null,
        'boolean_value' => null,
    ]);

    EntityProperty::create([
        'entity_id'     => 1,
        'entity_type'   => 'App\\Models\\User',
        'property_id'   => $this->numberProperty->id,
        'property_name' => 'score',
        'string_value'  => null,
        'number_value'  => 85.5,
        'date_value'    => null,
        'boolean_value' => null,
    ]);

    EntityProperty::create([
        'entity_id'     => 1,
        'entity_type'   => 'App\\Models\\User',
        'property_id'   => $this->dateProperty->id,
        'property_name' => 'created_date',
        'string_value'  => null,
        'number_value'  => null,
        'date_value'    => '2024-01-15',
        'boolean_value' => null,
    ]);

    EntityProperty::create([
        'entity_id'     => 1,
        'entity_type'   => 'App\\Models\\User',
        'property_id'   => $this->booleanProperty->id,
        'property_name' => 'active',
        'string_value'  => null,
        'number_value'  => null,
        'date_value'    => null,
        'boolean_value' => true,
    ]);

    // Create additional test entities
    EntityProperty::create([
        'entity_id'     => 2,
        'entity_type'   => 'App\\Models\\User',
        'property_id'   => $this->textProperty->id,
        'property_name' => 'description',
        'string_value'  => 'Another description for testing search functionality',
        'number_value'  => null,
        'date_value'    => null,
        'boolean_value' => null,
    ]);

    EntityProperty::create([
        'entity_id'     => 2,
        'entity_type'   => 'App\\Models\\User',
        'property_id'   => $this->numberProperty->id,
        'property_name' => 'score',
        'string_value'  => null,
        'number_value'  => 92.0,
        'date_value'    => null,
        'boolean_value' => null,
    ]);
});

afterEach(function () {
    EntityProperty::truncate();
    Property::truncate();
});

it('provides database information correctly', function () {
    $info = $this->propertyService->getDatabaseInfo();

    expect($info)->toBeArray();
    expect($info)->toHaveKeys(['driver', 'features', 'migration_config']);
    expect($info['driver'])->toBeString();
    expect($info['features'])->toBeArray();
    expect($info['migration_config'])->toBeArray();
});

it('performs text search with database-specific optimizations', function () {
    $results = $this->propertyService->searchText('App\\Models\\User', 'description', 'test');

    expect($results)->toBeInstanceOf(\Illuminate\Support\Collection::class);
    expect($results->count())->toBeGreaterThan(0);
    expect($results->contains(1))->toBeTrue();
});

it('performs case-insensitive text search', function () {
    $results = $this->propertyService->searchText('App\\Models\\User', 'description', 'TEST');

    expect($results)->toBeInstanceOf(\Illuminate\Support\Collection::class);
    expect($results->count())->toBeGreaterThan(0);
    expect($results->contains(1))->toBeTrue();
});

it('performs full-text search when supported', function () {
    if (! $this->dbCompat->supports('fulltext_search')) {
        $this->markTestSkipped('Full-text search not supported on this database');
    }

    $results = $this->propertyService->searchText(
        'App\\Models\\User',
        'description',
        'searchable content',
        ['full_text' => true]
    );

    expect($results)->toBeInstanceOf(\Illuminate\Support\Collection::class);
    expect($results->count())->toBeGreaterThan(0);
});

it('performs number range searches', function () {
    $results = $this->propertyService->searchNumberRange('App\\Models\\User', 'score', 80.0, 90.0);

    expect($results)->toBeInstanceOf(\Illuminate\Support\Collection::class);
    expect($results->contains(1))->toBeTrue();
    expect($results->contains(2))->toBeFalse(); // Score 92.0 is outside range
});

it('performs date range searches', function () {
    $results = $this->propertyService->searchDateRange(
        'App\\Models\\User',
        'created_date',
        '2024-01-01',
        '2024-01-31'
    );

    expect($results)->toBeInstanceOf(\Illuminate\Support\Collection::class);
    expect($results->contains(1))->toBeTrue();
});

it('performs boolean searches', function () {
    $results = $this->propertyService->searchBoolean('App\\Models\\User', 'active', true);

    expect($results)->toBeInstanceOf(\Illuminate\Support\Collection::class);
    expect($results->contains(1))->toBeTrue();
});

it('performs advanced searches with multiple criteria', function () {
    $results = $this->propertyService->advancedSearch('App\\Models\\User', [
        'description' => ['value' => 'test', 'operator' => 'like'],
        'score'       => ['value' => 80, 'operator' => '>='],
        'active'      => true,
    ]);

    expect($results)->toBeInstanceOf(\Illuminate\Support\Collection::class);
    expect($results->contains(1))->toBeTrue();
});

it('performs OR logic searches', function () {
    $results = $this->propertyService->advancedSearch('App\\Models\\User', [
        'score' => ['value' => 85.5, 'operator' => '='],
        'score' => ['value' => 92.0, 'operator' => '='],
    ], 'OR');

    expect($results)->toBeInstanceOf(\Illuminate\Support\Collection::class);
    expect($results->count())->toBeGreaterThan(0);
});

it('handles BETWEEN operator correctly', function () {
    $results = $this->propertyService->advancedSearch('App\\Models\\User', [
        'score' => ['operator' => 'between', 'min' => 80, 'max' => 90],
    ]);

    expect($results)->toBeInstanceOf(\Illuminate\Support\Collection::class);
    expect($results->contains(1))->toBeTrue();
    expect($results->contains(2))->toBeFalse();
});

it('handles IN operator correctly', function () {
    $results = $this->propertyService->advancedSearch('App\\Models\\User', [
        'score' => ['operator' => 'in', 'value' => [85.5, 92.0]],
    ]);

    expect($results)->toBeInstanceOf(\Illuminate\Support\Collection::class);
    expect($results->contains(1))->toBeTrue();
    expect($results->contains(2))->toBeTrue();
});

it('handles NULL searches correctly', function () {
    // Create an entity without a score
    EntityProperty::create([
        'entity_id'     => 3,
        'entity_type'   => 'App\\Models\\User',
        'property_id'   => $this->textProperty->id,
        'property_name' => 'description',
        'string_value'  => 'Entity without score',
        'number_value'  => null,
        'date_value'    => null,
        'boolean_value' => null,
    ]);

    // Search for entities with null scores
    $results = $this->propertyService->advancedSearch('App\\Models\\User', [
        'score' => ['operator' => 'null'],
    ]);

    expect($results)->toBeInstanceOf(\Illuminate\Support\Collection::class);
    expect($results->contains(3))->toBeTrue();
    expect($results->contains(1))->toBeFalse();
    expect($results->contains(2))->toBeFalse();
});

it('applies database optimizations without errors', function () {
    $optimizations = $this->propertyService->optimizeDatabase();

    expect($optimizations)->toBeArray();

    // Verify that optimizations were applied (at least attempted)
    // The actual success depends on database permissions and features
    foreach ($optimizations as $query) {
        expect($query)->toBeString();
        expect(strlen($query))->toBeGreaterThan(0);
    }
});

// Database-specific compatibility tests
describe('MySQL compatibility', function () {
    beforeEach(function () {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL-specific test');
        }
    });

    it('uses mysql json functions when available', function () {
        expect($this->dbCompat->supports('json_functions'))->toBeTrue();

        $query = $this->dbCompat->buildJsonExtractQuery('data', 'name');
        expect($query)->toContain('JSON_EXTRACT');
    });

    it('uses mysql fulltext search', function () {
        expect($this->dbCompat->supports('fulltext_search'))->toBeTrue();

        $query = $this->dbCompat->buildFullTextSearchQuery('content', 'search');
        expect($query)->toContain('MATCH');
        expect($query)->toContain('AGAINST');
    });

    it('supports case sensitive searches', function () {
        expect($this->dbCompat->supports('case_sensitive_like'))->toBeTrue();

        $query = $this->dbCompat->buildLikeSearchQuery('column', 'value', true);
        expect($query)->toContain('LIKE BINARY');
    });
});

describe('SQLite compatibility', function () {
    beforeEach(function () {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('SQLite-specific test');
        }
    });

    it('handles json functions based on extension availability', function () {
        $jsonSupport = $this->dbCompat->supports('json_functions');
        $query = $this->dbCompat->buildJsonExtractQuery('data', 'name');

        if ($jsonSupport) {
            expect($query)->toContain('json_extract');
        } else {
            expect($query)->toBe('NULL');
        }
    });

    it('falls back to like search when fulltext is not available', function () {
        $query = $this->dbCompat->buildFullTextSearchQuery('content', 'search');
        expect($query)->toBeString();

        if (! $this->dbCompat->supports('fulltext_search')) {
            expect($query)->toContain('LIKE');
        }
    });

    it('uses correct migration config for sqlite', function () {
        $config = $this->dbCompat->getMigrationConfig();
        expect($config['json_column_type'])->toBe('text');
        expect($config['supports_generated_columns'])->toBeFalse();
    });
});

describe('PostgreSQL compatibility', function () {
    beforeEach(function () {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL-specific test');
        }
    });

    it('uses postgresql json operators', function () {
        expect($this->dbCompat->supports('json_functions'))->toBeTrue();

        $query = $this->dbCompat->buildJsonExtractQuery('data', 'name');
        expect($query)->toContain('->');
    });

    it('uses postgresql fulltext search', function () {
        expect($this->dbCompat->supports('fulltext_search'))->toBeTrue();

        $query = $this->dbCompat->buildFullTextSearchQuery('content', 'search');
        expect($query)->toContain('to_tsvector');
        expect($query)->toContain('plainto_tsquery');
    });

    it('supports ilike for case insensitive searches', function () {
        $query = $this->dbCompat->buildLikeSearchQuery('column', 'value', false);
        expect($query)->toContain('ILIKE');
    });
});
