<?php

namespace SolutionForest\LaravelDynamicProperties\Console\Commands;

use Illuminate\Console\Command;
use SolutionForest\LaravelDynamicProperties\Services\DatabaseCompatibilityService;
use SolutionForest\LaravelDynamicProperties\Services\PropertyService;

class DatabaseOptimizeCommand extends Command
{
    protected $signature = 'dynamic-properties:optimize-db 
                           {--check : Only check database compatibility without applying optimizations}
                           {--force : Force apply optimizations even if some fail}';

    protected $description = 'Optimize database for dynamic properties with database-specific enhancements';

    protected PropertyService $propertyService;

    protected DatabaseCompatibilityService $dbCompat;

    public function __construct(PropertyService $propertyService)
    {
        parent::__construct();
        $this->propertyService = $propertyService;
        $this->dbCompat = $propertyService->getDatabaseCompatibilityService();
    }

    public function handle(): int
    {
        $this->info('Dynamic Properties Database Optimization Tool');
        $this->newLine();

        // Show database information
        $this->showDatabaseInfo();

        if ($this->option('check')) {
            $this->info('Database compatibility check completed.');

            return 0;
        }

        // Apply optimizations
        return $this->applyOptimizations();
    }

    protected function showDatabaseInfo(): void
    {
        $info = $this->propertyService->getDatabaseInfo();

        $this->info("Database Driver: {$info['driver']}");
        $this->newLine();

        $this->info('Supported Features:');
        foreach ($info['features'] as $feature => $supported) {
            $status = $supported ? '<fg=green>✓</>' : '<fg=red>✗</>';
            $this->line("  {$status} {$feature}");
        }
        $this->newLine();

        $this->info('Migration Configuration:');
        foreach ($info['migration_config'] as $config => $value) {
            $displayValue = is_bool($value) ? ($value ? 'true' : 'false') : $value;
            $this->line("  {$config}: {$displayValue}");
        }
        $this->newLine();
    }

    protected function applyOptimizations(): int
    {
        $this->info('Applying database optimizations...');
        $this->newLine();

        try {
            $optimizations = $this->propertyService->optimizeDatabase();

            if (empty($optimizations)) {
                $this->warn('No optimizations were applied. Database may already be optimized or driver not supported.');

                return 0;
            }

            $this->info('Applied optimizations:');
            foreach ($optimizations as $query) {
                $this->line("  <fg=green>✓</> {$query}");
            }

            $this->newLine();
            $this->info('Database optimization completed successfully!');

            // Show recommendations
            $this->showRecommendations();

            return 0;

        } catch (\Exception $e) {
            $this->error('Database optimization failed: '.$e->getMessage());

            if (! $this->option('force')) {
                $this->warn('Use --force to continue despite errors.');

                return 1;
            }

            $this->warn('Continuing despite errors due to --force flag.');

            return 0;
        }
    }

    protected function showRecommendations(): void
    {
        $driver = $this->dbCompat->getDriver();

        $this->newLine();
        $this->info('Recommendations:');

        match ($driver) {
            'mysql'  => $this->showMySQLRecommendations(),
            'sqlite' => $this->showSQLiteRecommendations(),
            'pgsql'  => $this->showPostgreSQLRecommendations(),
            default  => $this->line('  No specific recommendations for this database driver.')
        };
    }

    protected function showMySQLRecommendations(): void
    {
        $this->line('  • Consider using MySQL 8.0+ for better JSON support and performance');
        $this->line('  • Enable query cache if not already enabled');
        $this->line('  • Monitor slow query log for property search queries');

        if ($this->dbCompat->supports('generated_columns')) {
            $this->line('  • Generated columns are supported - consider using them for computed properties');
        }

        if ($this->dbCompat->supports('fulltext_search')) {
            $this->line('  • Full-text search is enabled - use it for text property searches');
        }
    }

    protected function showSQLiteRecommendations(): void
    {
        $this->line('  • SQLite is great for development but consider MySQL/PostgreSQL for production');

        if (! $this->dbCompat->supports('json1_extension')) {
            $this->line('  • Enable JSON1 extension for better JSON support');
        }

        if (! $this->dbCompat->supports('fts_extension')) {
            $this->line('  • Enable FTS extension for full-text search capabilities');
        } else {
            $this->line('  • FTS extension is available - full-text search is optimized');
        }

        $this->line('  • Consider PRAGMA optimizations: journal_mode=WAL, synchronous=NORMAL');
    }

    protected function showPostgreSQLRecommendations(): void
    {
        $this->line('  • PostgreSQL provides excellent JSON and full-text search support');
        $this->line('  • Consider using JSONB for better performance with JSON data');
        $this->line('  • Monitor query performance with pg_stat_statements');
        $this->line('  • Consider partitioning entity_properties table for very large datasets');
    }
}
