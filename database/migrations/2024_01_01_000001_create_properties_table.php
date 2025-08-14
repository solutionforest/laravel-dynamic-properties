<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('properties', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('label');
            $table->enum('type', ['text', 'number', 'date', 'boolean', 'select']);
            $table->boolean('required')->default(false);
            $table->json('options')->nullable(); // For select type: ["option1", "option2"]
            $table->json('validation')->nullable(); // {"min": 0, "max": 100}
            $table->timestamps();
            
            // Indexes for performance
            $table->index('type');
            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('properties');
    }
};