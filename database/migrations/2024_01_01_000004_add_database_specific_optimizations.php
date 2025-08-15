<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use SolutionForest\LaravelDynamicProperties\Services\DatabaseCompatibilityService;

return new class extends Migration
{
    protected DatabaseCompatibilityService $dbCompat;

    public function __construct()
    {
        $this->dbCompat = new DatabaseCompatibilityService;
    }

    public function up(): void
    {
        $driver = $this->dbCompat->getDriver();

        // Apply database-specific optimizations
        match ($driver) {
            'mysql'  => $this->optimizeMySQL(),
            'sqlite' => $this->optimizeSQLite(),
            'pgsql'  => $this->optimizePostgreSQL(),
            default  => null
        };
    }

    public function down(): void
    {
        $driver = $this->dbCompat->getDriver();

        // Remove database-specific optimizations
        match ($driver) {
            'mysql'  => $this->rollbackMySQL(),
            'sqlite' => $this->rollbackSQLite(),
            'pgsql'  => $this->rollbackPostgreSQL(),
            default  => null
        };
    }

    protected function optimizeMySQL(): void
    {
        // Add full-text index if not already exists (handled in original migration conditionally)
        if (! $this->indexExists('entity_properties', 'ft_string_content')) {
            DB::statement('ALTER TABLE entity_properties ADD FULLTEXT INDEX ft_string_content (string_value)');
        }

        // Add JSON functional indexes for MySQL 8.0+ if supported
        if ($this->dbCompat->supports('generated_columns')) {
            // Add generated column for JSON searches (if we had JSON data)
            // This is a placeholder for future JSON column optimizations
        }

        // Add composite indexes for common search patterns
        if (! $this->indexExists('entity_properties', 'idx_entity_property_value')) {
            DB::statement('ALTER TABLE entity_properties ADD INDEX idx_entity_property_value (entity_type, property_name, string_value(100))');
        }

        // Optimize table for InnoDB
        DB::statement('ALTER TABLE entity_properties ENGINE=InnoDB ROW_FORMAT=DYNAMIC');
        DB::statement('ALTER TABLE properties ENGINE=InnoDB ROW_FORMAT=DYNAMIC');
    }

    protected function optimizeSQLite(): void
    {
        // Create FTS virtual table if FTS extension is available
        if ($this->dbCompat->supports('fts_extension')) {
            try {
                // Create FTS virtual table for full-text search
                DB::statement('CREATE VIRTUAL TABLE IF NOT EXISTS entity_properties_fts USING fts5(string_value, content="entity_properties", content_rowid="id")');

                // Create triggers to keep FTS table in sync
                DB::statement('
                    CREATE TRIGGER IF NOT EXISTS entity_properties_fts_insert 
                    AFTER INSERT ON entity_properties 
                    WHEN new.string_value IS NOT NULL
                    BEGIN 
                        INSERT INTO entity_properties_fts(rowid, string_value) 
                        VALUES (new.id, new.string_value); 
                    END
                ');

                DB::statement('
                    CREATE TRIGGER IF NOT EXISTS entity_properties_fts_delete 
                    AFTER DELETE ON entity_properties 
                    WHEN old.string_value IS NOT NULL
                    BEGIN 
                        INSERT INTO entity_properties_fts(entity_properties_fts, rowid, string_value) 
                        VALUES("delete", old.id, old.string_value); 
                    END
                ');

                DB::statement('
                    CREATE TRIGGER IF NOT EXISTS entity_properties_fts_update 
                    AFTER UPDATE ON entity_properties 
                    WHEN new.string_value IS NOT NULL OR old.string_value IS NOT NULL
                    BEGIN 
                        INSERT INTO entity_properties_fts(entity_properties_fts, rowid, string_value) 
                        VALUES("delete", old.id, old.string_value);
                        INSERT INTO entity_properties_fts(rowid, string_value) 
                        VALUES (new.id, new.string_value); 
                    END
                ');

                // Populate existing data
                DB::statement('INSERT INTO entity_properties_fts(rowid, string_value) SELECT id, string_value FROM entity_properties WHERE string_value IS NOT NULL');

            } catch (\Exception $e) {
                // FTS setup failed, continue without it
            }
        }

        // Add additional indexes for SQLite
        if (! $this->indexExists('entity_properties', 'idx_sqlite_text_search')) {
            DB::statement('CREATE INDEX IF NOT EXISTS idx_sqlite_text_search ON entity_properties (entity_type, property_name, string_value COLLATE NOCASE)');
        }

        // Enable JSON1 extension if available
        if ($this->dbCompat->supports('json1_extension')) {
            try {
                DB::statement('SELECT load_extension("json1")');
            } catch (\Exception $e) {
                // Extension loading failed, continue without it
            }
        }
    }

    protected function optimizePostgreSQL(): void
    {
        // Add GIN indexes for full-text search
        if (! $this->indexExists('entity_properties', 'idx_entity_properties_gin_string')) {
            DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_entity_properties_gin_string ON entity_properties USING gin(to_tsvector(\'english\', string_value)) WHERE string_value IS NOT NULL');
        }

        // Add partial indexes for better performance
        if (! $this->indexExists('entity_properties', 'idx_entity_properties_partial_string')) {
            DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_entity_properties_partial_string ON entity_properties (entity_type, property_name, string_value) WHERE string_value IS NOT NULL');
        }

        if (! $this->indexExists('entity_properties', 'idx_entity_properties_partial_number')) {
            DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_entity_properties_partial_number ON entity_properties (entity_type, property_name, number_value) WHERE number_value IS NOT NULL');
        }

        if (! $this->indexExists('entity_properties', 'idx_entity_properties_partial_date')) {
            DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_entity_properties_partial_date ON entity_properties (entity_type, property_name, date_value) WHERE date_value IS NOT NULL');
        }

        if (! $this->indexExists('entity_properties', 'idx_entity_properties_partial_boolean')) {
            DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_entity_properties_partial_boolean ON entity_properties (entity_type, property_name, boolean_value) WHERE boolean_value IS NOT NULL');
        }
    }

    protected function rollbackMySQL(): void
    {
        // Remove MySQL-specific optimizations
        if ($this->indexExists('entity_properties', 'idx_entity_property_value')) {
            DB::statement('ALTER TABLE entity_properties DROP INDEX idx_entity_property_value');
        }
    }

    protected function rollbackSQLite(): void
    {
        // Remove SQLite FTS optimizations
        try {
            DB::statement('DROP TRIGGER IF EXISTS entity_properties_fts_insert');
            DB::statement('DROP TRIGGER IF EXISTS entity_properties_fts_delete');
            DB::statement('DROP TRIGGER IF EXISTS entity_properties_fts_update');
            DB::statement('DROP TABLE IF EXISTS entity_properties_fts');
        } catch (\Exception $e) {
            // Ignore errors during rollback
        }

        if ($this->indexExists('entity_properties', 'idx_sqlite_text_search')) {
            DB::statement('DROP INDEX IF EXISTS idx_sqlite_text_search');
        }
    }

    protected function rollbackPostgreSQL(): void
    {
        // Remove PostgreSQL-specific optimizations
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_entity_properties_gin_string');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_entity_properties_partial_string');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_entity_properties_partial_number');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_entity_properties_partial_date');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_entity_properties_partial_boolean');
    }

    protected function indexExists(string $table, string $index): bool
    {
        $driver = $this->dbCompat->getDriver();

        try {
            return match ($driver) {
                'mysql'  => ! empty(DB::select("SHOW INDEX FROM {$table} WHERE Key_name = ?", [$index])),
                'sqlite' => ! empty(DB::select("SELECT name FROM sqlite_master WHERE type='index' AND name=?", [$index])),
                'pgsql'  => ! empty(DB::select('SELECT indexname FROM pg_indexes WHERE tablename = ? AND indexname = ?', [$table, $index])),
                default  => false
            };
        } catch (\Exception $e) {
            return false;
        }
    }
};
