<?php

namespace DynamicProperties\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Cache;

class DatabaseCompatibilityService
{
    protected string $driver;
    protected array $features;
    protected array $config;

    public function __construct(array $config = [])
    {
        $this->driver = DB::connection()->getDriverName();
        $this->config = $config;
        $this->features = $this->detectFeatures();
    }

    /**
     * Detect available database features
     */
    protected function detectFeatures(): array
    {
        $cacheKey = 'dynamic_properties.db_features.' . $this->driver;
        
        return Cache::remember($cacheKey, 3600, function () {
            return match($this->driver) {
                'mysql' => $this->detectMySQLFeatures(),
                'sqlite' => $this->detectSQLiteFeatures(),
                'pgsql' => $this->detectPostgreSQLFeatures(),
                default => $this->getDefaultFeatures()
            };
        });
    }

    /**
     * Detect MySQL-specific features
     */
    protected function detectMySQLFeatures(): array
    {
        $features = [
            'json_functions' => true,
            'fulltext_search' => true,
            'generated_columns' => false,
            'json_extract' => true,
            'json_search' => true,
            'case_sensitive_like' => true,
        ];

        try {
            // Check MySQL version for generated columns support (5.7+)
            $version = DB::select("SELECT VERSION() as version")[0]->version;
            if (version_compare($version, '5.7.0', '>=')) {
                $features['generated_columns'] = true;
            }
        } catch (\Exception $e) {
            // Fallback if version detection fails
        }

        return $features;
    }

    /**
     * Detect SQLite-specific features
     */
    protected function detectSQLiteFeatures(): array
    {
        $features = [
            'json_functions' => false,
            'fulltext_search' => false,
            'generated_columns' => false,
            'json_extract' => false,
            'json_search' => false,
            'case_sensitive_like' => false,
            'json1_extension' => false,
            'fts_extension' => false,
        ];

        try {
            // Check for JSON1 extension
            $result = DB::select("SELECT json('{}') as test");
            if ($result) {
                $features['json_functions'] = true;
                $features['json_extract'] = true;
                $features['json1_extension'] = true;
            }
        } catch (\Exception $e) {
            // JSON1 extension not available
        }

        try {
            // Check for FTS extension
            DB::select("CREATE VIRTUAL TABLE IF NOT EXISTS test_fts USING fts5(content)");
            DB::select("DROP TABLE IF EXISTS test_fts");
            $features['fts_extension'] = true;
            $features['fulltext_search'] = true;
        } catch (\Exception $e) {
            // FTS extension not available
        }

        return $features;
    }

    /**
     * Detect PostgreSQL-specific features
     */
    protected function detectPostgreSQLFeatures(): array
    {
        return [
            'json_functions' => true,
            'fulltext_search' => true,
            'generated_columns' => true,
            'json_extract' => true,
            'json_search' => true,
            'case_sensitive_like' => true,
            'jsonb_support' => true,
        ];
    }

    /**
     * Get default features for unknown databases
     */
    protected function getDefaultFeatures(): array
    {
        return [
            'json_functions' => false,
            'fulltext_search' => false,
            'generated_columns' => false,
            'json_extract' => false,
            'json_search' => false,
            'case_sensitive_like' => false,
        ];
    }

    /**
     * Check if a specific feature is supported
     */
    public function supports(string $feature): bool
    {
        return $this->features[$feature] ?? false;
    }

    /**
     * Get the database driver name
     */
    public function getDriver(): string
    {
        return $this->driver;
    }

    /**
     * Get all supported features
     */
    public function getFeatures(): array
    {
        return $this->features;
    }

    /**
     * Build a JSON extract query for the current database
     */
    public function buildJsonExtractQuery(string $column, string $path): string
    {
        return match($this->driver) {
            'mysql' => "JSON_EXTRACT({$column}, '$.{$path}')",
            'sqlite' => $this->supports('json_extract') 
                ? "json_extract({$column}, '$.{$path}')"
                : "NULL", // Fallback for SQLite without JSON1
            'pgsql' => "{$column}->'{$path}'",
            default => "NULL"
        };
    }

    /**
     * Build a JSON search query for the current database
     */
    public function buildJsonSearchQuery(string $column, string $searchTerm): string
    {
        return match($this->driver) {
            'mysql' => "JSON_SEARCH({$column}, 'one', '%{$searchTerm}%') IS NOT NULL",
            'sqlite' => $this->supports('json_extract')
                ? "{$column} LIKE '%{$searchTerm}%'"
                : "{$column} LIKE '%{$searchTerm}%'",
            'pgsql' => "{$column}::text ILIKE '%{$searchTerm}%'",
            default => "{$column} LIKE '%{$searchTerm}%'"
        };
    }

    /**
     * Build a full-text search query for the current database
     */
    public function buildFullTextSearchQuery(string $column, string $searchTerm): string
    {
        if (!$this->supports('fulltext_search')) {
            return $this->buildLikeSearchQuery($column, $searchTerm);
        }

        return match($this->driver) {
            'mysql' => "MATCH({$column}) AGAINST('{$searchTerm}' IN BOOLEAN MODE)",
            'sqlite' => $this->supports('fts_extension')
                ? "{$column} MATCH '{$searchTerm}'"
                : $this->buildLikeSearchQuery($column, $searchTerm),
            'pgsql' => "to_tsvector({$column}) @@ plainto_tsquery('{$searchTerm}')",
            default => $this->buildLikeSearchQuery($column, $searchTerm)
        };
    }

    /**
     * Build a LIKE search query with case sensitivity support
     */
    public function buildLikeSearchQuery(string $column, string $searchTerm, bool $caseSensitive = false): string
    {
        $operator = 'LIKE';
        $searchValue = "'%{$searchTerm}%'";

        if ($caseSensitive && $this->supports('case_sensitive_like')) {
            $operator = match($this->driver) {
                'mysql' => 'LIKE BINARY',
                'pgsql' => 'LIKE',
                default => 'LIKE'
            };
        } elseif (!$caseSensitive) {
            $operator = match($this->driver) {
                'pgsql' => 'ILIKE',
                default => 'LIKE'
            };
        }

        return "{$column} {$operator} {$searchValue}";
    }

    /**
     * Build an optimized search query based on property type and database capabilities
     */
    public function buildOptimizedSearchQuery(string $propertyType, string $column, mixed $value, string $operator = '='): string
    {
        // Handle special operators first
        if (in_array(strtolower($operator), ['like', 'ilike'])) {
            return $this->buildLikeSearchQuery($column, $value, strtolower($operator) === 'like');
        }

        if (strtolower($operator) === 'fulltext') {
            return $this->buildFullTextSearchQuery($column, $value);
        }

        // Standard comparison operators
        $escapedValue = $this->escapeValue($value, $propertyType);
        return "{$column} {$operator} {$escapedValue}";
    }

    /**
     * Escape a value for safe SQL usage based on type
     */
    protected function escapeValue(mixed $value, string $propertyType): string
    {
        return match($propertyType) {
            'text', 'select' => "'" . addslashes($value) . "'",
            'number' => (string) $value,
            'date' => "'" . $value . "'",
            'boolean' => $value ? '1' : '0',
            default => "'" . addslashes($value) . "'",
        };
    }

    /**
     * Get database-specific configuration for migrations
     */
    public function getMigrationConfig(): array
    {
        return match($this->driver) {
            'mysql' => [
                'supports_fulltext' => true,
                'json_column_type' => 'json',
                'text_column_type' => 'text',
                'supports_generated_columns' => $this->supports('generated_columns'),
            ],
            'sqlite' => [
                'supports_fulltext' => $this->supports('fts_extension'),
                'json_column_type' => 'text', // SQLite stores JSON as TEXT
                'text_column_type' => 'text',
                'supports_generated_columns' => false,
            ],
            'pgsql' => [
                'supports_fulltext' => true,
                'json_column_type' => 'jsonb',
                'text_column_type' => 'text',
                'supports_generated_columns' => true,
            ],
            default => [
                'supports_fulltext' => false,
                'json_column_type' => 'text',
                'text_column_type' => 'text',
                'supports_generated_columns' => false,
            ],
        };
    }

    /**
     * Create database-specific indexes for optimal performance
     */
    public function createOptimizedIndexes(string $tableName): array
    {
        $indexes = [];

        if ($this->driver === 'mysql') {
            // MySQL-specific optimizations
            if ($this->supports('fulltext_search')) {
                $indexes[] = "ALTER TABLE {$tableName} ADD FULLTEXT INDEX ft_string_content (string_value)";
            }
            
            // JSON functional indexes (MySQL 8.0+)
            if ($this->supports('generated_columns')) {
                $indexes[] = "ALTER TABLE {$tableName} ADD INDEX idx_json_search ((CAST(JSON_EXTRACT(string_value, '$') AS CHAR(255))))";
            }
        }

        if ($this->driver === 'sqlite' && $this->supports('fts_extension')) {
            // SQLite FTS virtual table
            $indexes[] = "CREATE VIRTUAL TABLE IF NOT EXISTS {$tableName}_fts USING fts5(string_value, content='{$tableName}', content_rowid='id')";
            $indexes[] = "CREATE TRIGGER IF NOT EXISTS {$tableName}_fts_insert AFTER INSERT ON {$tableName} BEGIN INSERT INTO {$tableName}_fts(rowid, string_value) VALUES (new.id, new.string_value); END";
            $indexes[] = "CREATE TRIGGER IF NOT EXISTS {$tableName}_fts_delete AFTER DELETE ON {$tableName} BEGIN INSERT INTO {$tableName}_fts({$tableName}_fts, rowid, string_value) VALUES('delete', old.id, old.string_value); END";
            $indexes[] = "CREATE TRIGGER IF NOT EXISTS {$tableName}_fts_update AFTER UPDATE ON {$tableName} BEGIN INSERT INTO {$tableName}_fts({$tableName}_fts, rowid, string_value) VALUES('delete', old.id, old.string_value); INSERT INTO {$tableName}_fts(rowid, string_value) VALUES (new.id, new.string_value); END";
        }

        if ($this->driver === 'pgsql') {
            // PostgreSQL-specific optimizations
            $indexes[] = "CREATE INDEX IF NOT EXISTS idx_{$tableName}_gin_string ON {$tableName} USING gin(to_tsvector('english', string_value))";
            $indexes[] = "CREATE INDEX IF NOT EXISTS idx_{$tableName}_jsonb ON {$tableName} USING gin(string_value jsonb_path_ops) WHERE string_value IS NOT NULL";
        }

        return $indexes;
    }

    /**
     * Get database-specific query hints for performance optimization
     */
    public function getQueryHints(string $queryType): array
    {
        return match($this->driver) {
            'mysql' => match($queryType) {
                'search' => ['USE INDEX (idx_string_search, idx_number_search, idx_date_search)'],
                'fulltext' => ['USE INDEX (ft_string_content)'],
                default => []
            },
            'pgsql' => match($queryType) {
                'search' => ['/*+ IndexScan */'],
                'fulltext' => ['/*+ BitmapScan */'],
                default => []
            },
            default => []
        };
    }

    /**
     * Clear the feature detection cache
     */
    public function clearFeatureCache(): void
    {
        $cacheKey = 'dynamic_properties.db_features.' . $this->driver;
        Cache::forget($cacheKey);
        $this->features = $this->detectFeatures();
    }
}