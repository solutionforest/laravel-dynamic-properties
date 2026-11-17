<?php

declare(strict_types=1);

namespace SolutionForest\LaravelDynamicProperties\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use SolutionForest\LaravelDynamicProperties\Traits\HasProperties;

/**
 * Minimal model used by static analysis to exercise the HasProperties trait.
 */
class PropertyUser extends Model
{
    use HasProperties;

    protected $table = 'users';

    protected $fillable = ['name', 'email', 'dynamic_properties'];

    /**
     * @var array<string, string>
     */
    protected $casts = ['dynamic_properties' => 'array'];
}
