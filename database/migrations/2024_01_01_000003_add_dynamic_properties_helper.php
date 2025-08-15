<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * This is a helper migration that can be copied and customized
     * to add dynamic_properties JSON column to existing entity tables.
     *
     * Example usage:
     * - Copy this file to your Laravel app's migrations folder
     * - Rename it with a proper timestamp
     * - Uncomment and customize the table names you want to add caching to
     */
    public function up(): void
    {
        // Example: Add JSON cache column to users table
        // Schema::table('users', function (Blueprint $table) {
        //     $table->json('dynamic_properties')->nullable();
        // });

        // Example: Add JSON cache column to companies table
        // Schema::table('companies', function (Blueprint $table) {
        //     $table->json('dynamic_properties')->nullable();
        // });

        // Example: Add JSON cache column to contacts table
        // Schema::table('contacts', function (Blueprint $table) {
        //     $table->json('dynamic_properties')->nullable();
        // });
    }

    public function down(): void
    {
        // Example: Remove JSON cache column from users table
        // Schema::table('users', function (Blueprint $table) {
        //     $table->dropColumn('dynamic_properties');
        // });

        // Example: Remove JSON cache column from companies table
        // Schema::table('companies', function (Blueprint $table) {
        //     $table->dropColumn('dynamic_properties');
        // });

        // Example: Remove JSON cache column from contacts table
        // Schema::table('contacts', function (Blueprint $table) {
        //     $table->dropColumn('dynamic_properties');
        // });
    }
};
