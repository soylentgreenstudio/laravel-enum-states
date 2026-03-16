<?php

declare(strict_types=1);

namespace SoylentGreenStudio\EnumStates\Tests\Support\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @method \HistoryOrder create($attributes = [], ?Model $parent = null)
 * @method \HistoryOrder make($attributes = [], ?Model $parent = null)
 */
class HistoryOrderFactory extends Factory
{
    protected $model = \HistoryOrder::class;

    public function definition(): array
    {
        return [
            'status' => 'pending',
            'payment_status' => 'unpaid',
        ];
    }
}
