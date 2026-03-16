<?php

declare(strict_types=1);

namespace SoylentGreenStudio\EnumStates\Tests\Support;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use SoylentGreenStudio\EnumStates\Tests\Support\Factories\TestOrderFactory;
use SoylentGreenStudio\EnumStates\Traits\HasStateMachines;
use Illuminate\Database\Eloquent\Model;

/**
 * @method static TestOrderFactory factory($count = null, $state = [])
 */
class TestOrder extends Model
{
    use HasFactory;
    use HasStateMachines;

    protected $table = 'orders';

    protected $guarded = [];

    protected $casts = [
        'status' => TestOrderStatus::class,
    ];

    protected static function newFactory(): TestOrderFactory
    {
        return TestOrderFactory::new();
    }
}
