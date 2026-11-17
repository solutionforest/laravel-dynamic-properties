<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        match (DB::connection()->getDriverName()) {
            'mysql'  => $this->optimizeMySQL(),
            'pgsql'  => $this->optimizePostgreSQL(),
            default  => null,
        };
    }

    public function down(): void
    {
        match (DB::connection()->getDriverName()) {
            'mysql'  => $this->rollbackMySQL(),
            'pgsql'  => $this->rollbackPostgreSQL(),
            default  => null,
        };
    }

    private function optimizeMySQL(): void
    {
        if (! $this->indexExists('entity_properties', 'ft_string_content')) {
            DB::statement('ALTER TABLE entity_properties ADD FULLTEXT INDEX ft_string_content (string_value)');
        }

        if (! $this->indexExists('entity_properties', 'idx_string_search')) {
            DB::statement('ALTER TABLE entity_properties ADD INDEX idx_string_search (entity_type, property_name, string_value(100))');
        }
    }

    private function optimizePostgreSQL(): void
    {
        // Plain (non-concurrent) indexes: Laravel wraps migrations in a
        // transaction on PostgreSQL, where CONCURRENTLY is illegal.
        if (! $this->indexExists('entity_properties', 'idx_entity_properties_plain_string')) {
            DB::statement('CREATE INDEX IF NOT EXISTS idx_entity_properties_plain_string ON entity_properties (entity_type, property_name, string_value) WHERE string_value IS NOT NULL');
        }

        if (! $this->indexExists('entity_properties', 'idx_entity_properties_partial_number')) {
            DB::statement('CREATE INDEX IF NOT EXISTS idx_entity_properties_partial_number ON entity_properties (entity_type, property_name, number_value) WHERE number_value IS NOT NULL');
        }
    }

    private function rollbackMySQL(): void
    {
        if ($this->indexExists('entity_properties', 'idx_string_search')) {
            DB::statement('ALTER TABLE entity_properties DROP INDEX idx_string_search');
        }
    }

    private function rollbackPostgreSQL(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_entity_properties_plain_string');
        DB::statement('DROP INDEX IF EXISTS idx_entity_properties_partial_number');
    }

    private function indexExists(string $table, string $index): bool
    {
        try {
            return match (DB::connection()->getDriverName()) {
                'mysql'  => DB::select('SHOW INDEX FROM entity_properties WHERE Key_name = ?', [$index]) !== [],
                'sqlite' => DB::select("SELECT name FROM sqlite_master WHERE type = 'index' AND name = ?", [$index]) !== [],
                'pgsql'  => DB::select('SELECT indexname FROM pg_indexes WHERE tablename = ? AND indexname = ?', [$table, $index]) !== [],
                default  => false,
            };
        } catch (Throwable) {
            return false;
        }
    }
};
