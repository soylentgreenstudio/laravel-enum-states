<?php

declare(strict_types=1);

namespace SoylentGreenStudio\EnumStates\Tests\Support\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @method \GuardedOrder create($attributes = [], ?Model $parent = null)
 * @method \GuardedOrder make($attributes = [], ?Model $parent = null)
 */
class GuardedOrderFactory extends Factory
{
    protected $model = \GuardedOrder::class;

    public function definition(): array
    {
        return [
            'status' => 'pending',
        ];
    }
}
