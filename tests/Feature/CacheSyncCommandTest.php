<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use SolutionForest\LaravelDynamicProperties\Models\EntityProperty;
use SolutionForest\LaravelDynamicProperties\Models\Property;
use SolutionForest\LaravelDynamicProperties\Traits\HasProperties;

// Create a test model that uses the HasProperties trait
class CacheSyncTestUser extends Model
{
    use HasProperties;

    protected $table = 'users';

    protected $fillable = ['name', 'email', 'dynamic_properties'];

    protected $casts = ['dynamic_properties' => 'array'];
}

describe('CacheSyncCommand - Morph Name Handling', function () {
    beforeEach(function () {
        // Reset morph map to ensure clean state for each test
        Relation::morphMap([]);
        
        // Create users table for testing
        if (! Schema::hasTable('users')) {
            Schema::create('users', function ($table) {
                $table->id();
                $table->string('name');
                $table->string('email');
                $table->json('dynamic_properties')->nullable();
                $table->timestamps();
            });
        }

        // Create test properties
        Property::firstOrCreate(['name' => 'bio'], [
            'label'    => 'Biography',
            'type'     => 'text',
            'required' => false,
        ]);

        Property::firstOrCreate(['name' => 'age'], [
            'label'    => 'Age',
            'type'     => 'number',
            'required' => false,
        ]);
    });

    afterEach(function () {
        // Reset morph map after each test
        Relation::morphMap([]);
    });

    it('works with full model class name when no morph mapping is configured', function () {
        // Force reset morph map to ensure clean state for this test
        Relation::morphMap([]);
        
        // Create test user
        $user = CacheSyncTestUser::create([
            'name'  => 'Test User',
            'email' => 'test@example.com',
        ]);

        // getMorphClass() should return full class name when no morph mapping is configured
        expect($user->getMorphClass())->toBe(CacheSyncTestUser::class);

        // Set properties to create entity_properties records
        $user->setDynamicProperty('bio', 'Test biography');
        $user->setDynamicProperty('age', 25);

        // Clear the JSON column to simulate cache being out of sync
        $user->update(['dynamic_properties' => null]);

        // Verify entity_properties were stored with full class name
        $entityProperties = EntityProperty::where('entity_id', $user->id)
            ->where('entity_type', CacheSyncTestUser::class)
            ->get();
        expect($entityProperties)->toHaveCount(2);

        // Run sync command with full class name
        $result = Artisan::call('dynamic-properties:sync-cache', [
            'model' => CacheSyncTestUser::class,
        ]);

        expect($result)->toBe(0);

        // Verify JSON column was updated
        $user->refresh();
        expect($user->dynamic_properties)->not->toBeNull();
        expect($user->dynamic_properties['bio'])->toBe('Test biography');
        expect($user->dynamic_properties['age'])->toBe(25);
    });

    it('works with morph name when morph mapping is configured', function () {
        // Configure morph mapping
        Relation::morphMap([
            'users' => CacheSyncTestUser::class,
        ]);

        // Create a new user after morph mapping is set
        $userWithMorphMap = CacheSyncTestUser::create([
            'name'  => 'Morph User',
            'email' => 'morph@example.com',
        ]);

        // Verify getMorphClass() now returns the morph name
        expect($userWithMorphMap->getMorphClass())->toBe('users');

        // Set properties to create entity_properties records with morph name
        $userWithMorphMap->setDynamicProperty('bio', 'Morph biography');
        $userWithMorphMap->setDynamicProperty('age', 30);

        // Clear JSON column
        $userWithMorphMap->update(['dynamic_properties' => null]);

        // Verify entity_properties were stored with morph name
        $entityProperties = EntityProperty::where('entity_id', $userWithMorphMap->id)
            ->where('entity_type', 'users')
            ->get();
        expect($entityProperties)->toHaveCount(2);

        // Run sync command with morph name (this should work)
        $result = Artisan::call('dynamic-properties:sync-cache', [
            'model' => 'users',
        ]);

        expect($result)->toBe(0);

        // Verify JSON column was updated
        $userWithMorphMap->refresh();
        expect($userWithMorphMap->dynamic_properties)->not->toBeNull();
        expect($userWithMorphMap->dynamic_properties['bio'])->toBe('Morph biography');
        expect($userWithMorphMap->dynamic_properties['age'])->toBe(30);
    });

    it('works with full class name when morph mapping is configured', function () {
        // Configure morph mapping
        Relation::morphMap([
            'users' => CacheSyncTestUser::class,
        ]);

        // Create a new user after morph mapping is set
        $userWithMorphMap = CacheSyncTestUser::create([
            'name'  => 'Morph User',
            'email' => 'morph@example.com',
        ]);

        // Set properties to create entity_properties records with morph name
        $userWithMorphMap->setDynamicProperty('bio', 'Morph biography');
        $userWithMorphMap->setDynamicProperty('age', 30);

        // Clear JSON column
        $userWithMorphMap->update(['dynamic_properties' => null]);

        // Run sync command with full class name (this should also work)
        $result = Artisan::call('dynamic-properties:sync-cache', [
            'model' => CacheSyncTestUser::class,
        ]);

        expect($result)->toBe(0);

        // Verify JSON column was updated
        $userWithMorphMap->refresh();
        expect($userWithMorphMap->dynamic_properties)->not->toBeNull();
        expect($userWithMorphMap->dynamic_properties['bio'])->toBe('Morph biography');
        expect($userWithMorphMap->dynamic_properties['age'])->toBe(30);
    });

    it('handles non-existent morph name gracefully', function () {
        // Run sync command with non-existent morph name
        $result = Artisan::call('dynamic-properties:sync-cache', [
            'model' => 'non_existent_morph',
        ]);

        expect($result)->toBe(-1);
    });

    it('handles non-existent class name gracefully', function () {
        // Run sync command with non-existent class
        $result = Artisan::call('dynamic-properties:sync-cache', [
            'model' => 'App\\Models\\NonExistentModel',
        ]);

        expect($result)->toBe(-1);
    });
});