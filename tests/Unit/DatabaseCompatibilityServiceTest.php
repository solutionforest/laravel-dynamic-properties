<?php

declare(strict_types=1);

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

it('builds a like condition for the query builder', function () {
    $condition = $this->service->likeCondition('test');

    expect($condition)->toHaveKeys(['operator', 'value']);
    expect(strtolower($condition['operator']))->toBe('like');
    expect($condition['value'])->toBe('%test%');
});

it('escapes like wildcards', function () {
    $condition = $this->service->likeCondition('100% _done!_');

    expect($condition['value'])->toBe('%100\\% \\_done!\\_%');
});

it('does not interpolate SQL in like conditions', function () {
    $payload = "zzz%' OR 1=1 -- ";
    $condition = $this->service->likeCondition($payload);

    expect(strtolower($condition['operator']))->toBe('like')
        // The value is escaped for LIKE and always passed as a query binding;
        // no SQL string is assembled from user input.
        ->and($condition['value'])->toContain('\\%')
        ->and($condition)->not->toHaveKey('sql');
});

it('only offers a full text condition when the driver supports it', function () {
    $condition = $this->service->fullTextCondition('string_value', 'test search');

    if ($this->service->supports('fulltext_search')) {
        expect($condition)->toBe(['column' => 'string_value', 'value' => 'test search']);
    } else {
        expect($condition)->toBeNull();
    }
});

it('provides migration configuration for current database', function () {
    $config = $this->service->getMigrationConfig();

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
    $optimizations = $this->service->createOptimizedIndexes('test_table');

    expect($optimizations)->toBeArray();

    foreach ($optimizations as $query) {
        expect($query)->toBeString();
        expect($query)->toContain('test_table');
        // Migrations run inside a transaction on PostgreSQL.
        expect($query)->not->toContain('CONCURRENTLY');
    }
});

it('caches feature detection results', function () {
    $driver = $this->service->getDriver();
    $cacheKey = 'dynamic_properties.db_features.'.$driver;

    $features1 = $this->service->getFeatures();
    expect(Cache::has($cacheKey))->toBeTrue();

    $features2 = $this->service->getFeatures();
    expect($features1)->toEqual($features2);
});

it('can clear feature cache', function () {
    $driver = $this->service->getDriver();
    $cacheKey = 'dynamic_properties.db_features.'.$driver;

    $this->service->getFeatures();
    expect(Cache::has($cacheKey))->toBeTrue();

    $this->service->clearFeatureCache();

    $features1 = $this->service->getFeatures();
    Cache::forget($cacheKey);
    $features2 = $this->service->getFeatures();

    expect($features1)->toEqual($features2);
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

    it('never reports full-text search on regular sqlite tables', function () {
        expect($this->service->supports('fulltext_search'))->toBeFalse();
    });
});

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

    it('offers a full text condition', function () {
        expect($this->service->fullTextCondition('content', 'search term'))
            ->toBe(['column' => 'content', 'value' => 'search term']);
    });

    it('uses a binary like operator for case sensitive searches', function () {
        expect($this->service->likeCondition('value', true)['operator'])->toBe('LIKE BINARY');
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

    it('supports ilike for case insensitive searches', function () {
        expect($this->service->likeCondition('value')['operator'])->toBe('ILIKE');
    });
});
