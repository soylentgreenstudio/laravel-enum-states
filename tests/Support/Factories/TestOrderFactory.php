<?php

declare(strict_types=1);

namespace SoylentGreenStudio\EnumStates\Tests\Support\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use SoylentGreenStudio\EnumStates\Tests\Support\TestOrder;

/**
 * @method TestOrder create($attributes = [], ?Model $parent = null)
 * @method TestOrder make($attributes = [], ?Model $parent = null)
 */
class TestOrderFactory extends Factory
{
    protected $model = TestOrder::class;

    public function definition(): array
    {
        return [
            'status' => 'pending',
        ];
    }
}
