<?php

declare(strict_types=1);

namespace SolutionForest\LaravelDynamicProperties\Services;

use Illuminate\Contracts\Database\Eloquent\Builder as EloquentBuilderContract;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class DatabaseCompatibilityService
{
    protected string $driver;

    /**
     * @var array<string, bool>
     */
    protected array $features;

    public function __construct()
    {
        $this->driver = DB::connection()->getDriverName();
        $this->features = $this->detectFeatures();
    }

    /**
     * Detect available database features.
     *
     * Only read-only probes are used: feature detection must be safe to run on
     * a request path and against read replicas.
     *
     * @return array<string, bool>
     */
    protected function detectFeatures(): array
    {
        $cacheKey = 'dynamic_properties.db_features.'.$this->driver;

        try {
            /** @var array<string, bool> $features */
            $features = Cache::remember($cacheKey, 3600, fn (): array => $this->detectFeaturesDirectly());

            return $features;
        } catch (\Throwable) {
            return $this->detectFeaturesDirectly();
        }
    }

    /**
     * @return array<string, bool>
     */
    protected function detectFeaturesDirectly(): array
    {
        return match ($this->driver) {
            'mysql'  => $this->detectMySQLFeatures(),
            'sqlite' => $this->detectSQLiteFeatures(),
            'pgsql'  => $this->detectPostgreSQLFeatures(),
            default  => $this->getDefaultFeatures(),
        };
    }

    /**
     * @return array<string, bool>
     */
    protected function detectMySQLFeatures(): array
    {
        $features = [
            'json_functions'      => true,
            'fulltext_search'     => true,
            'generated_columns'   => false,
            'json_extract'        => true,
            'json_search'         => true,
            'case_sensitive_like' => true,
        ];

        try {
            $rows = DB::select('SELECT VERSION() as version');
            $row = $rows[0] ?? null;
            $version = is_object($row) && property_exists($row, 'version') ? $row->version : null;

            if (is_string($version) && version_compare($version, '5.7.0', '>=')) {
                $features['generated_columns'] = true;
            }
        } catch (\Throwable) {
            // Version detection is best-effort.
        }

        return $features;
    }

    /**
     * @return array<string, bool>
     */
    protected function detectSQLiteFeatures(): array
    {
        $features = [
            'json_functions'      => false,
            'fulltext_search'     => false,
            'generated_columns'   => false,
            'json_extract'        => false,
            'json_search'         => false,
            'case_sensitive_like' => false,
            'json1_extension'     => false,
            'fts_extension'       => false,
        ];

        try {
            DB::select("SELECT json('{}') as test");
            $features['json_functions'] = true;
            $features['json_extract'] = true;
            $features['json1_extension'] = true;
        } catch (\Throwable) {
            // JSON1 extension not available.
        }

        try {
            $names = [];

            foreach (DB::select('PRAGMA compile_options') as $option) {
                if (is_object($option) && property_exists($option, 'compile_options') && is_string($option->compile_options)) {
                    $names[] = $option->compile_options;
                }
            }

            $features['fts_extension'] = in_array('ENABLE_FTS5', $names, true);
        } catch (\Throwable) {
            $features['fts_extension'] = false;
        }

        return $features;
    }

    /**
     * @return array<string, bool>
     */
    protected function detectPostgreSQLFeatures(): array
    {
        return [
            'json_functions'      => true,
            'fulltext_search'     => true,
            'generated_columns'   => true,
            'json_extract'        => true,
            'json_search'         => true,
            'case_sensitive_like' => true,
            'jsonb_support'       => true,
        ];
    }

    /**
     * @return array<string, bool>
     */
    protected function getDefaultFeatures(): array
    {
        return [
            'json_functions'      => false,
            'fulltext_search'     => false,
            'generated_columns'   => false,
            'json_extract'        => false,
            'json_search'         => false,
            'case_sensitive_like' => false,
        ];
    }

    public function supports(string $feature): bool
    {
        return $this->features[$feature] ?? false;
    }

    public function getDriver(): string
    {
        return $this->driver;
    }

    /**
     * @return array<string, bool>
     */
    public function getFeatures(): array
    {
        return $this->features;
    }

    /**
     * The SQL LIKE operator for this database.
     */
    public function likeOperator(bool $caseSensitive = false): string
    {
        if ($caseSensitive) {
            return $this->driver === 'mysql' ? 'LIKE BINARY' : 'LIKE';
        }

        return $this->driver === 'pgsql' ? 'ILIKE' : 'LIKE';
    }

    /**
     * Escape character used in every LIKE predicate built by this package.
     *
     * A non-backslash character is used because SQLite has no default LIKE
     * escape character, and `ESCAPE '!'` is portable across MySQL, PostgreSQL
     * and SQLite without depending on backslash-escaping server modes.
     */
    public const LIKE_ESCAPE = '!';

    /**
     * Fixed LIKE predicates, one per operator and value column.
     *
     * Predicates are static literals (identifiers are plain and need no
     * quoting) so no SQL is ever assembled from runtime input; the search term
     * is always passed as a binding.
     */
    private const LIKE_PREDICATES = [
        'LIKE' => [
            'string_value'  => "string_value LIKE ? ESCAPE '!'",
            'number_value'  => "number_value LIKE ? ESCAPE '!'",
            'date_value'    => "date_value LIKE ? ESCAPE '!'",
            'boolean_value' => "boolean_value LIKE ? ESCAPE '!'",
        ],
        'LIKE BINARY' => [
            'string_value'  => "string_value LIKE BINARY ? ESCAPE '!'",
            'number_value'  => "number_value LIKE BINARY ? ESCAPE '!'",
            'date_value'    => "date_value LIKE BINARY ? ESCAPE '!'",
            'boolean_value' => "boolean_value LIKE BINARY ? ESCAPE '!'",
        ],
        'ILIKE' => [
            'string_value'  => "string_value ILIKE ? ESCAPE '!'",
            'number_value'  => "number_value ILIKE ? ESCAPE '!'",
            'date_value'    => "date_value ILIKE ? ESCAPE '!'",
            'boolean_value' => "boolean_value ILIKE ? ESCAPE '!'",
        ],
    ];

    /**
     * Build a parameterised LIKE condition for the current database.
     *
     * The term is escaped for LIKE wildcards with {@see self::LIKE_ESCAPE}; the
     * value is always bound by the query builder, never interpolated into SQL.
     * Use {@see self::applyLike()} to add the matching `ESCAPE` clause.
     *
     * @return array{operator: string, value: string, escape: string}
     */
    public function likeCondition(string $searchTerm, bool $caseSensitive = false): array
    {
        $escape = self::LIKE_ESCAPE;
        $escaped = str_replace([$escape, '%', '_'], [$escape.$escape, $escape.'%', $escape.'_'], $searchTerm);

        return [
            'operator' => $this->likeOperator($caseSensitive),
            'value'    => '%'.$escaped.'%',
            'escape'   => $escape,
        ];
    }

    /**
     * Add a "contains" LIKE predicate with an explicit ESCAPE clause.
     *
     * @param  EloquentBuilder<*>|EloquentBuilderContract|QueryBuilder  $query
     */
    public function applyLike(mixed $query, string $column, string $searchTerm, bool $caseSensitive = false): void
    {
        $like = $this->likeCondition($searchTerm, $caseSensitive);
        $sql = self::LIKE_PREDICATES[$like['operator']][$column] ?? null;

        if ($sql === null) {
            throw new \InvalidArgumentException("LIKE is not supported on column [{$column}].");
        }

        $query->whereRaw($sql, [$like['value']]);
    }

    /**
     * Build a full-text condition for the current database, or null when the
     * driver cannot match on a regular table.
     *
     * @return array{column: string, value: string}|null
     */
    public function fullTextCondition(string $column, string $searchTerm): ?array
    {
        if (! $this->supports('fulltext_search')) {
            return null;
        }

        return [
            'column' => $column,
            'value'  => $searchTerm,
        ];
    }

    /**
     * Get database-specific configuration for migrations.
     *
     * @return array<string, bool|string>
     */
    public function getMigrationConfig(): array
    {
        return match ($this->driver) {
            'mysql' => [
                'supports_fulltext'          => true,
                'json_column_type'           => 'json',
                'text_column_type'           => 'text',
                'supports_generated_columns' => $this->supports('generated_columns'),
            ],
            'sqlite' => [
                'supports_fulltext'          => false,
                'json_column_type'           => 'text',
                'text_column_type'           => 'text',
                'supports_generated_columns' => false,
            ],
            'pgsql' => [
                'supports_fulltext'          => true,
                'json_column_type'           => 'jsonb',
                'text_column_type'           => 'text',
                'supports_generated_columns' => true,
            ],
            default => [
                'supports_fulltext'          => false,
                'json_column_type'           => 'text',
                'text_column_type'           => 'text',
                'supports_generated_columns' => false,
            ],
        };
    }

    /**
     * Create database-specific indexes for optimal performance.
     *
     * Statements are plain SQL (no CONCURRENTLY) so they can run inside a
     * migration transaction.
     *
     * @return list<string>
     */
    public function createOptimizedIndexes(string $tableName): array
    {
        $indexes = [];

        // MySQL has no ADD ... IF NOT EXISTS for indexes: skip ones the migration
        // already created so re-running optimize-db is idempotent.
        if ($this->driver === 'mysql'
            && $this->supports('fulltext_search')
            && ! $this->mysqlIndexExists($tableName, 'ft_string_content')) {
            $indexes[] = "ALTER TABLE {$tableName} ADD FULLTEXT INDEX ft_string_content (string_value)";
        }

        if ($this->driver === 'pgsql') {
            $indexes[] = "CREATE INDEX IF NOT EXISTS idx_{$tableName}_gin_string ON {$tableName} USING gin(to_tsvector('english', string_value))";
            $indexes[] = "CREATE INDEX IF NOT EXISTS idx_{$tableName}_partial_number ON {$tableName} (entity_type, property_name, number_value) WHERE number_value IS NOT NULL";
        }

        return $indexes;
    }

    protected function mysqlIndexExists(string $tableName, string $index): bool
    {
        try {
            return DB::select(
                'SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ? LIMIT 1',
                [$tableName, $index]
            ) !== [];
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Clear the feature detection cache.
     */
    public function clearFeatureCache(): void
    {
        $cacheKey = 'dynamic_properties.db_features.'.$this->driver;

        try {
            Cache::forget($cacheKey);
        } catch (\Throwable) {
            // Cache might not be available in test environments.
        }

        $this->features = $this->detectFeatures();
    }
}
