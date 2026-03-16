<?php

declare(strict_types=1);

namespace SoylentGreenStudio\EnumStates\Tests\Support\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @method \HookedOrder create($attributes = [], ?Model $parent = null)
 * @method \HookedOrder make($attributes = [], ?Model $parent = null)
 */
class HookedOrderFactory extends Factory
{
    protected $model = \HookedOrder::class;

    public function definition(): array
    {
        return [
            'status' => 'pending',
        ];
    }
}
