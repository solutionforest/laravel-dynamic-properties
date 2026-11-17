<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use SolutionForest\LaravelDynamicProperties\Exceptions\PropertyNotFoundException;
use SolutionForest\LaravelDynamicProperties\Exceptions\PropertyOperationException;
use SolutionForest\LaravelDynamicProperties\Exceptions\PropertyValidationException;
use SolutionForest\LaravelDynamicProperties\Models\EntityProperty;
use SolutionForest\LaravelDynamicProperties\Models\Property;
use SolutionForest\LaravelDynamicProperties\Services\DatabaseCompatibilityService;
use SolutionForest\LaravelDynamicProperties\Services\PropertyService;
use SolutionForest\LaravelDynamicProperties\Traits\HasProperties;

class ReviewUserWithoutCacheWiring extends Model
{
    use HasProperties;

    protected $table = 'review_users';

    protected $fillable = ['name', 'email'];
}

class ReviewUserWithCast extends Model
{
    use HasProperties;

    protected $table = 'review_users';

    protected $fillable = ['name', 'email', 'dynamic_properties'];

    protected $casts = ['dynamic_properties' => 'array'];
}

class ReviewCompanyWithoutCacheWiring extends Model
{
    use HasProperties;

    protected $table = 'review_companies';

    protected $fillable = ['name'];
}

beforeEach(function () {
    Relation::morphMap([], false);

    Schema::create('review_users', function ($table): void {
        $table->id();
        $table->string('name');
        $table->string('email');
        $table->json('dynamic_properties')->nullable();
        $table->timestamps();
    });

    Schema::create('review_companies', function ($table): void {
        $table->id();
        $table->string('name');
        $table->json('dynamic_properties')->nullable();
        $table->timestamps();
    });

    Property::create(['name' => 'bio', 'label' => 'Bio', 'type' => 'text']);
    Property::create(['name' => 'age', 'label' => 'Age', 'type' => 'number']);
    Property::create(['name' => 'active', 'label' => 'Active', 'type' => 'boolean']);
});

// --- P0: SQL injection -------------------------------------------------------

it('does not let LIKE search terms alter the generated SQL', function () {
    $user = ReviewUserWithoutCacheWiring::create(['name' => 'A', 'email' => 'a@example.com']);
    $user->setDynamicProperty('bio', 'alpha');

    $ids = app(PropertyService::class)->search(ReviewUserWithoutCacheWiring::class, [
        'bio' => ['value' => "zzz%' OR 1=1 -- ", 'operator' => 'like'],
    ]);

    expect($ids->all())->toBe([]);
});

// --- P0: undefined property search fails closed -----------------------------

it('fails closed when searching an undefined property', function () {
    ReviewUserWithoutCacheWiring::create(['name' => 'A', 'email' => 'a@example.com'])
        ->setDynamicProperty('bio', 'alpha');

    $service = app(PropertyService::class);

    expect(fn () => $service->search(ReviewUserWithoutCacheWiring::class, ['nope' => 'x']))
        ->toThrow(PropertyNotFoundException::class);

    expect(fn () => $service->advancedSearch(ReviewUserWithoutCacheWiring::class, [
        'bio'  => ['value' => 'alpha', 'operator' => 'like'],
        'nope' => ['value' => 'x', 'operator' => 'like'],
    ], 'OR'))->toThrow(PropertyNotFoundException::class);

    expect(fn () => $service->searchText(ReviewUserWithoutCacheWiring::class, 'nope', 'x'))
        ->toThrow(PropertyNotFoundException::class);
});

// --- P0: orderByProperty keeps the entity primary key -----------------------

it('preserves entity ids when ordering by a property', function () {
    $first = ReviewUserWithoutCacheWiring::create(['name' => 'A', 'email' => 'a@example.com']);
    $second = ReviewUserWithoutCacheWiring::create(['name' => 'B', 'email' => 'b@example.com']);
    $third = ReviewUserWithoutCacheWiring::create(['name' => 'C', 'email' => 'c@example.com']);

    $first->setDynamicProperty('age', 40);
    $third->setDynamicProperty('age', 20);

    $ordered = ReviewUserWithoutCacheWiring::orderByProperty('age', 'desc')->get();

    // Descending: nulls last, so 40, 20, then the user without the property.
    expect($ordered->pluck('id')->all())->toBe([$first->id, $third->id, $second->id]);

    // The hydrated models must still be addressable by their real key.
    expect($ordered->first()?->getKey())->toBe($first->id)
        ->and(ReviewUserWithoutCacheWiring::find($ordered->first()?->getKey()))->not->toBeNull();
});

// --- P0: boolean validation and casting agree -------------------------------

it('rejects boolean strings that cannot be stored faithfully', function () {
    $user = ReviewUserWithoutCacheWiring::create(['name' => 'A', 'email' => 'a@example.com']);

    expect(fn () => $user->setDynamicProperty('active', 'true'))
        ->toThrow(PropertyValidationException::class);

    $user->setDynamicProperty('active', '1');
    expect($user->getDynamicProperty('active'))->toBeTrue();

    $user->setDynamicProperty('active', '0');
    expect($user->getDynamicProperty('active'))->toBeFalse();
});

// --- P0: JSON cache works without $fillable / array cast --------------------

it('writes the JSON cache without requiring fillable entries', function () {
    $user = ReviewUserWithoutCacheWiring::create(['name' => 'A', 'email' => 'a@example.com']);
    $user->setDynamicProperty('bio', 'hello');

    $raw = DB::table('review_users')->where('id', $user->id)->value('dynamic_properties');

    expect($raw)->toBeString()
        ->and(json_decode($raw, true))->toBe(['bio' => 'hello'])
        ->and($user->fresh()?->properties)->toBe(['bio' => 'hello'])
        ->and($user->fresh()?->getDynamicProperty('bio'))->toBe('hello');
});

it('reads the JSON cache without an array cast', function () {
    $user = ReviewUserWithoutCacheWiring::create(['name' => 'A', 'email' => 'a@example.com']);
    DB::table('review_users')->where('id', $user->id)->update([
        'dynamic_properties' => json_encode(['bio' => 'cached']),
    ]);

    $fresh = ReviewUserWithoutCacheWiring::find($user->id);

    expect($fresh?->properties)->toBe(['bio' => 'cached'])
        ->and($fresh?->getDynamicProperty('bio'))->toBe('cached');
});

it('does not persist unrelated dirty attributes when syncing the cache', function () {
    $user = ReviewUserWithoutCacheWiring::create(['name' => 'Original', 'email' => 'a@example.com']);
    $user->name = 'Unsaved change';

    $user->setDynamicProperty('bio', 'hello');

    expect(DB::table('review_users')->where('id', $user->id)->value('name'))->toBe('Original');
});

it('honours the enable_json_cache configuration flag', function () {
    $service = new PropertyService(['database' => ['enable_json_cache' => false]]);

    $user = ReviewUserWithoutCacheWiring::create(['name' => 'A', 'email' => 'a@example.com']);
    $service->setDynamicProperty($user, 'bio', 'hello');

    expect(DB::table('review_users')->where('id', $user->id)->value('dynamic_properties'))->toBeNull()
        ->and($user->fresh()?->getDynamicProperty('bio'))->toBe('hello');
});

// --- P1: definition lifecycle keeps values and cache in sync ----------------

it('renames stored values and JSON cache keys with the property', function () {
    $user = ReviewUserWithoutCacheWiring::create(['name' => 'A', 'email' => 'a@example.com']);
    $user->setDynamicProperty('bio', 'hello');

    Property::query()->where('name', 'bio')->firstOrFail()->update(['name' => 'biography']);

    expect($user->fresh()?->getDynamicProperty('biography'))->toBe('hello')
        ->and($user->fresh()?->getDynamicProperty('bio'))->toBeNull();

    $stored = EntityProperty::query()->where('property_id', Property::query()->where('name', 'biography')->value('id'))->firstOrFail();
    expect($stored->property_name)->toBe('biography');
});

it('removes cached keys when a property is deleted', function () {
    $user = ReviewUserWithoutCacheWiring::create(['name' => 'A', 'email' => 'a@example.com']);
    $user->setDynamicProperty('bio', 'hello');
    $user->setDynamicProperty('age', 30);

    Property::query()->where('name', 'bio')->firstOrFail()->delete();

    expect($user->fresh()?->properties)->toEqual(['age' => 30.0])
        ->and($user->fresh()?->getDynamicProperty('bio'))->toBeNull();
});

it('migrates stored values when a property type changes', function () {
    $user = ReviewUserWithoutCacheWiring::create(['name' => 'A', 'email' => 'a@example.com']);
    $user->setDynamicProperty('bio', '42');

    Property::query()->where('name', 'bio')->firstOrFail()->update(['type' => 'number']);

    $stored = EntityProperty::query()->where('property_name', 'bio')->firstOrFail();

    expect($stored->number_value)->toBe(42.0)
        ->and($stored->string_value)->toBeNull();
});

// --- P1: null semantics ------------------------------------------------------

it('stores nulls distinctly from removal', function () {
    $user = ReviewUserWithoutCacheWiring::create(['name' => 'A', 'email' => 'a@example.com']);

    $user->setDynamicProperty('age', null);

    expect($user->fresh()?->properties)->toHaveKey('age')
        ->and($user->fresh()?->getDynamicProperty('age'))->toBeNull();

    $user->removeProperty('age');

    expect($user->fresh()?->properties)->not->toHaveKey('age');
});

// --- P1: batch validation ----------------------------------------------------

it('validates the whole batch before writing anything', function () {
    $user = ReviewUserWithoutCacheWiring::create(['name' => 'A', 'email' => 'a@example.com']);

    expect(fn () => $user->setProperties(['bio' => 'ok', 'age' => 'not-a-number']))
        ->toThrow(PropertyValidationException::class);

    expect($user->fresh()?->getDynamicProperty('bio'))->toBeNull();
});

it('rejects batch writes on an unsaved entity', function () {
    $user = new ReviewUserWithoutCacheWiring(['name' => 'A', 'email' => 'a@example.com']);

    expect(fn () => $user->setProperties(['bio' => 'hello']))
        ->toThrow(PropertyOperationException::class);

    expect(fn () => $user->setDynamicProperty('bio', 'hello'))
        ->toThrow(PropertyOperationException::class);
});

// --- P2: no N+1 when loading properties -------------------------------------

it('loads properties for a collection with a single query', function () {
    foreach (range(1, 5) as $i) {
        $user = ReviewUserWithoutCacheWiring::create(['name' => "U{$i}", 'email' => "u{$i}@example.com"]);
        $user->setDynamicProperty('bio', "bio {$i}");
    }

    DB::flushQueryLog();
    DB::enableQueryLog();

    $users = ReviewUserWithoutCacheWiring::withProperties()->get();
    $properties = $users->map(fn (ReviewUserWithoutCacheWiring $user): array => $user->properties);

    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($queries)->toHaveCount(2)
        ->and($properties->first())->toBe(['bio' => 'bio 1']);
});

it('returns all properties for many entities in one query', function () {
    $users = collect();

    foreach (range(1, 4) as $i) {
        $user = ReviewUserWithoutCacheWiring::create(['name' => "U{$i}", 'email' => "u{$i}@example.com"]);
        $user->setDynamicProperty('age', $i);
        $users->push($user->fresh());
    }

    DB::table('review_users')->update(['dynamic_properties' => null]);
    $users = $users->map(fn (ReviewUserWithoutCacheWiring $user): ReviewUserWithoutCacheWiring => $user->refresh());

    DB::flushQueryLog();
    DB::enableQueryLog();

    $result = app(PropertyService::class)->getPropertiesFor($users);

    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($queries)->toHaveCount(1)
        ->and($result[(string) $users->first()->getKey()])->toBe(['age' => 1.0]);
});

// --- P2: definition cache ----------------------------------------------------

it('reuses cached property definitions', function () {
    $service = new PropertyService(['performance' => ['cache_property_definitions' => true]]);

    DB::flushQueryLog();
    DB::enableQueryLog();

    $service->findProperty('bio');
    $service->findProperty('bio');

    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($queries)->toHaveCount(1);
});

// --- P2: optimization failures are reported ---------------------------------

it('reports optimization failures instead of hiding them', function () {
    $dbCompat = Mockery::mock(DatabaseCompatibilityService::class);
    $dbCompat->shouldReceive('createOptimizedIndexes')->andReturn(['ALTER TABLE missing_table ADD FULLTEXT INDEX x (y)']);

    $service = new PropertyService([], null, $dbCompat);
    $applied = $service->optimizeDatabase();

    expect($applied)->toBe([])
        ->and($service->getLastOptimizationFailures())->toHaveCount(1);
});

// --- Property definition validation ----------------------------------------

it('validates definitions on the model, not only in the service', function () {
    expect(fn () => Property::create(['name' => '123bad', 'label' => 'Bad', 'type' => 'text']))
        ->toThrow(PropertyValidationException::class);

    expect(fn () => Property::create(['name' => 'no_options', 'label' => 'Bad', 'type' => 'select']))
        ->toThrow(PropertyValidationException::class);
});

it('accepts the documented validation rules', function () {
    $service = app(PropertyService::class);

    $salary = $service->createProperty([
        'name'       => 'salary',
        'label'      => 'Salary',
        'type'       => 'number',
        'validation' => ['min' => 0, 'max' => 1000000, 'decimal_places' => 2],
    ]);

    expect($salary->validateValue(100.25))->toBeTrue()
        ->and($salary->validateValue(100.256))->toBeFalse();

    $email = $service->createProperty([
        'name'       => 'email_address',
        'label'      => 'Email',
        'type'       => 'text',
        'validation' => ['email', 'required'],
    ]);

    expect($email->validateValue('a@example.com'))->toBeTrue()
        ->and($email->validateValue('not-an-email'))->toBeFalse()
        ->and($email->validateValue(''))->toBeFalse();

    $bio = $service->createProperty([
        'name'       => 'tagline',
        'label'      => 'Tagline',
        'type'       => 'text',
        'validation' => ['min' => 10, 'max' => 500, 'required' => true],
    ]);

    expect($bio->validateValue(''))->toBeFalse()
        ->and($bio->validateValue('long enough tagline'))->toBeTrue();
});

// --- EntityProperty morph handling -----------------------------------------

it('uses the morph class in the forEntity scope', function () {
    Relation::morphMap(['review_users' => ReviewUserWithoutCacheWiring::class]);

    $user = ReviewUserWithoutCacheWiring::create(['name' => 'Morphed', 'email' => 'm@example.com']);
    $user->setDynamicProperty('bio', 'hello');

    expect(EntityProperty::query()->forEntity($user)->count())->toBe(1);
});

it('offers null and not-null scopes', function () {
    $withValue = ReviewUserWithoutCacheWiring::create(['name' => 'A', 'email' => 'a@example.com']);
    $withNull = ReviewUserWithoutCacheWiring::create(['name' => 'B', 'email' => 'b@example.com']);

    $withValue->setDynamicProperty('age', 30);
    $withNull->setDynamicProperty('age', null);

    expect(ReviewUserWithoutCacheWiring::wherePropertyNull('age')->pluck('id')->all())->toBe([$withNull->id])
        ->and(ReviewUserWithoutCacheWiring::wherePropertyNotNull('age')->pluck('id')->all())->toBe([$withValue->id]);
});

it('accepts both whereProperty argument orders under strict types', function () {
    $user = ReviewUserWithoutCacheWiring::create(['name' => 'A', 'email' => 'a@example.com']);
    $user->setDynamicProperty('age', 30);

    // Canonical: (name, operator, value)
    expect(ReviewUserWithoutCacheWiring::whereProperty('age', '>', 20)->count())->toBe(1)
        // Value-first: (name, value, operator)
        ->and(ReviewUserWithoutCacheWiring::whereProperty('age', 30, '>')->count())->toBe(0)
        ->and(ReviewUserWithoutCacheWiring::whereProperty('age', 30, '>=')->count())->toBe(1)
        // Shorthand equality with a boolean value
        ->and(ReviewUserWithoutCacheWiring::whereProperty('age', 30)->count())->toBe(1);
});

it('accepts the value-first order with a string value and operator', function () {
    $user = ReviewUserWithoutCacheWiring::create(['name' => 'A', 'email' => 'a@example.com']);
    $user->setDynamicProperty('bio', 'Hello World');

    expect(ReviewUserWithoutCacheWiring::whereProperty('bio', 'Hello World', 'LIKE')->count())->toBe(1)
        ->and(ReviewUserWithoutCacheWiring::whereProperty('bio', 'nope', 'LIKE')->count())->toBe(0);
});
