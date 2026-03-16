<?php

declare(strict_types=1);

namespace SoylentGreenStudio\EnumStates\Tests\Support;

use SoylentGreenStudio\EnumStates\Traits\HasStateMachines;
use Illuminate\Database\Eloquent\Model;

class TestOrder extends Model
{
    use HasStateMachines;

    protected $table = 'orders';

    protected $guarded = [];

    protected $casts = [
        'status' => TestOrderStatus::class,
    ];
}
