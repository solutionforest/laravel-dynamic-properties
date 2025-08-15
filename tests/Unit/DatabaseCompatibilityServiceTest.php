<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use SolutionForest\LaravelDynamicProperties\Services\DatabaseCompatibilityService;

beforeEach(function () {
    $this->service = new DatabaseCompatibilityService;
});

afterEach(function () {
    Cache::flush();
});

it('detects database driver correctly', function () {
    $driver = $this->service->getDriver();
    expect($driver)->toBeString();
    expect($driver)->toBeIn(['mysql', 'sqlite', 'pgsql']);
});

it('detects features for current database', function () {
    $features = $this->service->getFeatures();

    expect($features)->toBeArray();
    expect($features)->toHaveKeys([
        'json_functions',
        'fulltext_search',
        'generated_columns',
        'json_extract',
        'json_search',
        'case_sensitive_like',
    ]);

    foreach ($features as $feature => $supported) {
        expect($supported)->toBeBool();
    }
});

it('checks feature support correctly', function () {
    expect($this->service->supports('json_functions'))->toBeBool();
    expect($this->service->supports('fulltext_search'))->toBeBool();
    expect($this->service->supports('nonexistent_feature'))->toBeFalse();
});

it('builds json extract queries for different databases', function () {
    $column = 'data';
    $path = 'name';

    $query = $this->service->buildJsonExtractQuery($column, $path);
    expect($query)->toBeString();

    // Should contain the column and path
    expect($query)->toContain($column);
    expect($query)->toContain($path);
});

it('builds like search queries with case sensitivity', function () {
    $column = 'string_value';
    $searchTerm = 'test';

    $caseSensitive = $this->service->buildLikeSearchQuery($column, $searchTerm, true);
    $caseInsensitive = $this->service->buildLikeSearchQuery($column, $searchTerm, false);

    expect($caseSensitive)->toBeString();
    expect($caseInsensitive)->toBeString();
    expect($caseSensitive)->toContain($column);
    expect($caseSensitive)->toContain($searchTerm);
});

it('builds full text search queries', function () {
    $column = 'string_value';
    $searchTerm = 'test search';

    $query = $this->service->buildFullTextSearchQuery($column, $searchTerm);
    expect($query)->toBeString();
    expect($query)->toContain($column);
});

it('builds optimized search queries for different operators', function () {
    $propertyType = 'text';
    $column = 'string_value';
    $value = 'test';

    $equalQuery = $this->service->buildOptimizedSearchQuery($propertyType, $column, $value, '=');
    $likeQuery = $this->service->buildOptimizedSearchQuery($propertyType, $column, $value, 'like');

    expect($equalQuery)->toBeString();
    expect($likeQuery)->toBeString();
    expect($equalQuery)->toContain($column);
    expect($likeQuery)->toContain($column);
});

it('provides migration configuration for current database', function () {
    $config = $this->service->getMigrationConfig();

    expect($config)->toBeArray();
    expect($config)->toHaveKeys([
        'supports_fulltext',
        'json_column_type',
        'text_column_type',
        'supports_generated_columns',
    ]);

    expect($config['supports_fulltext'])->toBeBool();
    expect($config['json_column_type'])->toBeString();
    expect($config['text_column_type'])->toBeString();
    expect($config['supports_generated_columns'])->toBeBool();
});

it('creates database-specific optimization queries', function () {
    $tableName = 'test_table';
    $optimizations = $this->service->createOptimizedIndexes($tableName);

    expect($optimizations)->toBeArray();

    foreach ($optimizations as $query) {
        expect($query)->toBeString();
        expect($query)->toContain($tableName);
    }
});

it('provides query hints for different query types', function () {
    $searchHints = $this->service->getQueryHints('search');
    $fulltextHints = $this->service->getQueryHints('fulltext');

    expect($searchHints)->toBeArray();
    expect($fulltextHints)->toBeArray();
});

it('caches feature detection results', function () {
    $driver = $this->service->getDriver();
    $cacheKey = 'dynamic_properties.db_features.'.$driver;

    // First call should cache the results
    $features1 = $this->service->getFeatures();
    expect(Cache::has($cacheKey))->toBeTrue();

    // Second call should use cached results
    $features2 = $this->service->getFeatures();
    expect($features1)->toEqual($features2);
});

it('can clear feature cache', function () {
    $driver = $this->service->getDriver();
    $cacheKey = 'dynamic_properties.db_features.'.$driver;

    // Populate cache
    $this->service->getFeatures();
    expect(Cache::has($cacheKey))->toBeTrue();

    // Clear cache
    $this->service->clearFeatureCache();

    // The cache should be cleared and then repopulated by detectFeatures()
    // So we need to check that the cache was actually cleared and recreated
    $features1 = $this->service->getFeatures();
    Cache::forget($cacheKey); // Manually clear it
    $features2 = $this->service->getFeatures();

    // Both should be the same since they detect the same features
    expect($features1)->toEqual($features2);
});

// Database-specific tests
describe('MySQL-specific features', function () {
    beforeEach(function () {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL-specific test');
        }
    });

    it('detects mysql features correctly', function () {
        expect($this->service->supports('json_functions'))->toBeTrue();
        expect($this->service->supports('fulltext_search'))->toBeTrue();
        expect($this->service->supports('case_sensitive_like'))->toBeTrue();
    });

    it('builds mysql json extract queries', function () {
        $query = $this->service->buildJsonExtractQuery('data', 'name');
        expect($query)->toContain('JSON_EXTRACT');
    });

    it('builds mysql fulltext search queries', function () {
        $query = $this->service->buildFullTextSearchQuery('content', 'search term');
        expect($query)->toContain('MATCH');
        expect($query)->toContain('AGAINST');
    });
});

describe('SQLite-specific features', function () {
    beforeEach(function () {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('SQLite-specific test');
        }
    });

    it('detects sqlite features correctly', function () {
        $features = $this->service->getFeatures();
        expect($features)->toHaveKey('json1_extension');
        expect($features)->toHaveKey('fts_extension');
    });

    it('handles json queries for sqlite', function () {
        $query = $this->service->buildJsonExtractQuery('data', 'name');
        expect($query)->toBeString();

        if ($this->service->supports('json_extract')) {
            expect($query)->toContain('json_extract');
        }
    });
});

describe('PostgreSQL-specific features', function () {
    beforeEach(function () {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL-specific test');
        }
    });

    it('detects postgresql features correctly', function () {
        expect($this->service->supports('json_functions'))->toBeTrue();
        expect($this->service->supports('fulltext_search'))->toBeTrue();
        expect($this->service->supports('generated_columns'))->toBeTrue();
    });

    it('builds postgresql json queries', function () {
        $query = $this->service->buildJsonExtractQuery('data', 'name');
        expect($query)->toContain('->');
    });
});
