<?php

declare(strict_types=1);

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use SolutionForest\LaravelDynamicProperties\Exceptions\PropertyValidationException;
use SolutionForest\LaravelDynamicProperties\Models\EntityProperty;
use SolutionForest\LaravelDynamicProperties\Models\Property;
use SolutionForest\LaravelDynamicProperties\Services\PropertyService;
use SolutionForest\LaravelDynamicProperties\Traits\HasProperties;

class PatchUser extends Model
{
    use HasProperties;

    protected $table = 'patch_users';

    protected $fillable = ['name'];
}

class PatchOtherConnectionUser extends Model
{
    use HasProperties;

    protected $connection = 'patch_other';

    protected $table = 'patch_other_users';

    protected $fillable = ['name'];
}

beforeEach(function () {
    Relation::morphMap([], false);

    Schema::create('patch_users', function ($table): void {
        $table->id();
        $table->string('name');
        $table->json('dynamic_properties')->nullable();
        $table->timestamps();
    });

    restoreTestTransaction();
});

/**
 * MySQL commits implicitly on DDL, which ends the transaction RefreshDatabase
 * opened; reopen it so later nested transactions (savepoints) behave.
 */
function restoreTestTransaction(): void
{
    $pdo = DB::connection()->getPdo();

    if (! $pdo->inTransaction()) {
        $pdo->beginTransaction();
    }
}

function patchUser(string $name = 'A'): PatchUser
{
    return PatchUser::create(['name' => $name]);
}

// --- Type change never destroys data ----------------------------------------

it('aborts a type change when a stored value cannot be converted', function () {
    $property = Property::create(['name' => 'note', 'label' => 'Note', 'type' => 'text']);
    $user = patchUser();
    $user->setDynamicProperty('note', 'hello world');

    expect(fn () => $property->update(['type' => 'date']))->toThrow(PropertyValidationException::class);

    expect(Property::find($property->id)->type)->toBe('text');
    expect(EntityProperty::where('property_id', $property->id)->first()->string_value)->toBe('hello world');
});

it('converts number and text values to booleans faithfully', function () {
    $number = Property::create(['name' => 'flag_n', 'label' => 'Flag N', 'type' => 'number']);
    $text = Property::create(['name' => 'flag_t', 'label' => 'Flag T', 'type' => 'text']);
    $user = patchUser();
    $user->setDynamicProperty('flag_n', 1);
    $user->setDynamicProperty('flag_t', 'true');

    $number->update(['type' => 'boolean']);
    $text->update(['type' => 'boolean']);

    expect(EntityProperty::where('property_id', $number->id)->first()->boolean_value)->toBeTrue();
    expect(EntityProperty::where('property_id', $text->id)->first()->boolean_value)->toBeTrue();
});

it('converts text dates and numbers across types', function () {
    $date = Property::create(['name' => 'when', 'label' => 'When', 'type' => 'text']);
    $num = Property::create(['name' => 'qty', 'label' => 'Qty', 'type' => 'number']);
    $user = patchUser();
    $user->setDynamicProperty('when', '2020-01-02');
    $user->setDynamicProperty('qty', 5);

    $date->update(['type' => 'date']);
    $num->update(['type' => 'text']);

    expect(EntityProperty::where('property_id', $date->id)->first()->date_value->toDateString())->toBe('2020-01-02');
    expect(EntityProperty::where('property_id', $num->id)->first()->string_value)->toBe('5');
});

// --- LIKE wildcards on SQLite -----------------------------------------------

it('matches literal %, _ and ! in LIKE searches', function () {
    Property::create(['name' => 'bio', 'label' => 'Bio', 'type' => 'text']);
    $discount = patchUser('A');
    $discount->setDynamicProperty('bio', '50% off');
    $other = patchUser('B');
    $other->setDynamicProperty('bio', '500 off');
    $under = patchUser('C');
    $under->setDynamicProperty('bio', 'a_b!c');
    $plain = patchUser('D');
    $plain->setDynamicProperty('bio', 'axb!c');

    $service = app(PropertyService::class);

    expect($service->search(PatchUser::class, ['bio' => ['value' => '50%', 'operator' => 'like']])->all())
        ->toBe([$discount->id]);
    expect($service->search(PatchUser::class, ['bio' => ['value' => 'a_b!', 'operator' => 'like']])->all())
        ->toBe([$under->id]);
    expect(PatchUser::wherePropertyText('bio', '50%')->pluck('id')->all())->toBe([$discount->id]);
    expect(PatchUser::wherePropertyText('bio', 'a_b')->pluck('id')->all())->toBe([$under->id]);
});

it('matches literal backslashes in LIKE searches', function () {
    Property::create(['name' => 'bio', 'label' => 'Bio', 'type' => 'text']);
    $path = patchUser();
    $path->setDynamicProperty('bio', 'C:\\temp');

    expect(PatchUser::wherePropertyText('bio', 'C:\\')->pluck('id')->all())->toBe([$path->id]);
});

// --- whereProperty never silently matches everything ------------------------

it('rejects whereProperty calls that have an operator but no value', function () {
    Property::create(['name' => 'op', 'label' => 'Op', 'type' => 'number']);
    Property::create(['name' => 'status', 'label' => 'Status', 'type' => 'text']);
    patchUser()->setDynamicProperty('op', 3);

    expect(fn () => PatchUser::whereProperty('op', '>')->get())->toThrow(InvalidArgumentException::class);
    expect(fn () => PatchUser::whereProperty('status', 'like')->get())->toThrow(InvalidArgumentException::class);
});

it('still supports explicit null comparisons', function () {
    Property::create(['name' => 'age', 'label' => 'Age', 'type' => 'number']);
    $with = patchUser('A');
    $with->setDynamicProperty('age', 4);

    expect(PatchUser::whereProperty('age', '!=', null)->pluck('id')->all())->toBe([$with->id]);
});

// --- JSON cache honours the model connection --------------------------------

it('syncs the JSON cache through the entity model connection', function () {
    config()->set('database.connections.patch_other', [
        'driver'   => 'sqlite',
        'database' => ':memory:',
        'prefix'   => '',
    ]);

    Schema::connection('patch_other')->create('patch_other_users', function ($table): void {
        $table->id();
        $table->string('name');
        $table->json('dynamic_properties')->nullable();
        $table->timestamps();
    });

    Property::create(['name' => 'age', 'label' => 'Age', 'type' => 'number']);
    $user = PatchOtherConnectionUser::create(['name' => 'A']);

    expect($user->hasJsonPropertiesColumn())->toBeTrue();

    $user->setDynamicProperty('age', 7);

    $stored = $user->getConnection()->table('patch_other_users')->where('id', $user->id)->value('dynamic_properties');

    expect(json_decode((string) $stored, true))->toEqual(['age' => 7]);
});

// --- Cached reads return the same types as table reads ----------------------

it('returns identical types from the JSON cache and the table', function () {
    Property::create(['name' => 'dob', 'label' => 'DOB', 'type' => 'date']);
    Property::create(['name' => 'age', 'label' => 'Age', 'type' => 'number']);
    Property::create(['name' => 'active', 'label' => 'Active', 'type' => 'boolean']);

    $user = patchUser();
    $user->setDynamicProperty('dob', '2020-01-02');
    $user->setDynamicProperty('age', 5);
    $user->setDynamicProperty('active', true);

    $cached = PatchUser::find($user->id);
    expect($cached->hasJsonPropertiesColumn())->toBeTrue();

    $fromCache = $cached->getDynamicProperty('dob');
    expect($fromCache)->toBeInstanceOf(Carbon::class)
        ->and($fromCache->toDateString())->toBe('2020-01-02');
    expect($cached->getDynamicProperty('age'))->toBeFloat()->toBe(5.0);
    expect($cached->getDynamicProperty('active'))->toBeTrue();

    $all = $cached->properties;
    expect($all['dob'])->toBeInstanceOf(Carbon::class);
    expect($all['age'])->toBeFloat();
});
