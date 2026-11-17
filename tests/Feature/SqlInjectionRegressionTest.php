<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use SolutionForest\LaravelDynamicProperties\DynamicPropertyServiceProvider;
use SolutionForest\LaravelDynamicProperties\Models\Property;
use SolutionForest\LaravelDynamicProperties\Services\DatabaseCompatibilityService;
use SolutionForest\LaravelDynamicProperties\Services\PropertyService;
use SolutionForest\LaravelDynamicProperties\Traits\HasProperties;

class InjectionTestUser extends Model
{
    use HasProperties;

    protected $table = 'injection_users';

    protected $fillable = ['name'];
}

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->app->register(DynamicPropertyServiceProvider::class);
    config(['database.default' => 'testbench']);
    config(['database.connections.testbench' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
    $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

    Schema::create('injection_users', function ($table) {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });

    Property::create(['name' => 'bio', 'label' => 'Bio', 'type' => 'text']);
});

it('does not let LIKE search terms alter the SQL', function () {
    $user = InjectionTestUser::create(['name' => 'A']);
    $user->setDynamicProperty('bio', 'alpha');

    $ids = app(PropertyService::class)->search(InjectionTestUser::class, [
        'bio' => ['value' => "zzz%' OR 1=1 -- ", 'operator' => 'like'],
    ]);

    expect($ids)->toBeEmpty();
});

it('quotes terms in every raw search builder', function () {
    $compat = new DatabaseCompatibilityService;
    $payload = "x' OR '1'='1";

    foreach ([
        $compat->buildLikeSearchQuery('string_value', $payload),
        $compat->buildFullTextSearchQuery('string_value', $payload),
        $compat->buildJsonSearchQuery('dynamic_properties', $payload),
        $compat->buildOptimizedSearchQuery('text', 'string_value', $payload, '='),
    ] as $sql) {
        expect($sql)->not->toContain("x' OR");
    }
});

it('rejects unsupported operators in the optimized query builder', function () {
    expect(fn () => (new DatabaseCompatibilityService)->buildOptimizedSearchQuery('text', 'string_value', 'a', '= 1 OR 1'))
        ->toThrow(InvalidArgumentException::class);
});
