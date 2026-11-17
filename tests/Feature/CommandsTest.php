<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use SolutionForest\LaravelDynamicProperties\Models\Property;
use SolutionForest\LaravelDynamicProperties\Traits\HasProperties;

class CommandTestUser extends Model
{
    use HasProperties;

    protected $table = 'command_users';

    protected $fillable = ['name'];

    protected $casts = ['dynamic_properties' => 'array'];
}

beforeEach(function () {
    Schema::create('command_users', function ($table): void {
        $table->id();
        $table->string('name');
        $table->json('dynamic_properties')->nullable();
        $table->timestamps();
    });
});

afterEach(function () {
    Schema::dropIfExists('command_users');
});

it('creates a property through the artisan command', function () {
    $exit = Artisan::call('dynamic-properties:create', [
        'name'         => 'nickname',
        'type'         => 'text',
        'label'        => 'Nickname',
        '--validation' => ['min=2', 'max=20'],
    ]);

    expect($exit)->toBe(0);

    $property = Property::query()->where('name', 'nickname')->firstOrFail();
    expect($property->label)->toBe('Nickname')
        ->and($property->validation)->toBe(['min' => 2, 'max' => 20]);
});

it('rejects an invalid select property through the artisan command', function () {
    $exit = Artisan::call('dynamic-properties:create', [
        'name'  => 'status',
        'type'  => 'select',
    ]);

    expect($exit)->toBe(1)
        ->and(Property::query()->where('name', 'status')->exists())->toBeFalse();
});

it('deletes a property, its values, and clears the cache', function () {
    Artisan::call('dynamic-properties:create', ['name' => 'bio', 'type' => 'text', 'label' => 'Bio']);

    $user = CommandTestUser::create(['name' => 'A']);
    $user->setDynamicProperty('bio', 'hello');

    $exit = Artisan::call('dynamic-properties:delete', ['name' => 'bio', '--force' => true]);

    expect($exit)->toBe(0)
        ->and(Property::query()->where('name', 'bio')->exists())->toBeFalse()
        ->and(DB::table('command_users')->where('id', $user->id)->value('dynamic_properties'))->toBe('[]');
});

it('lists properties as json', function () {
    Artisan::call('dynamic-properties:create', ['name' => 'bio', 'type' => 'text', 'label' => 'Bio']);

    expect(Artisan::call('dynamic-properties:list', ['--format' => 'json']))->toBe(0);
    expect(Artisan::output())->toContain('"bio"');
});
