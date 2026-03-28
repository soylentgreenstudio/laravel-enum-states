<?php

declare(strict_types=1);

namespace SoylentGreenStudio\EnumStates\Tests\Support\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @method \AsyncHookedOrder create($attributes = [], ?Model $parent = null)
 * @method \AsyncHookedOrder make($attributes = [], ?Model $parent = null)
 */
class AsyncHookedOrderFactory extends Factory
{
    protected $model = \AsyncHookedOrder::class;

    public function definition(): array
    {
        return [
            'status' => 'pending',
        ];
    }
}
