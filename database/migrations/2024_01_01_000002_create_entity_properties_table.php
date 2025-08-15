<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entity_properties', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('entity_id');
            $table->string('entity_type');
            $table->foreignId('property_id')->constrained('properties')->onDelete('cascade');
            $table->string('property_name'); // Denormalized for performance

            // Type-specific value columns (only one will be populated based on property type)
            $table->text('string_value')->nullable();
            $table->decimal('number_value', 15, 4)->nullable();
            $table->date('date_value')->nullable();
            $table->boolean('boolean_value')->nullable();

            $table->timestamps();

            // Unique constraint: one property per entity
            $table->unique(['entity_id', 'entity_type', 'property_id'], 'unique_entity_property');

            // Indexes for fast search and retrieval
            $table->index(['entity_id', 'entity_type'], 'idx_entity');
            $table->index(['entity_type', 'property_name', 'string_value'], 'idx_string_search');
            $table->index(['entity_type', 'property_name', 'number_value'], 'idx_number_search');
            $table->index(['entity_type', 'property_name', 'date_value'], 'idx_date_search');
            $table->index(['entity_type', 'property_name', 'boolean_value'], 'idx_boolean_search');

            // Full-text search for string content (MySQL only)
            if (Schema::getConnection()->getDriverName() === 'mysql') {
                $table->fullText('string_value', 'ft_string_content');
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entity_properties');
    }
};
