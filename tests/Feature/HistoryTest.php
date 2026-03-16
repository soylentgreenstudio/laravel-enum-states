<?php

declare(strict_types=1);

use SoylentGreenStudio\EnumStates\Attributes\FinalState;
use SoylentGreenStudio\EnumStates\Attributes\InitialState;
use SoylentGreenStudio\EnumStates\Attributes\Transition;
use SoylentGreenStudio\EnumStates\Tests\Support\Factories\HistoryOrderFactory;
use SoylentGreenStudio\EnumStates\Traits\HasStateMachines;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

// ---- Multi-field enum and model ----

enum HistoryPaymentStatus: string
{
    #[InitialState]
    #[Transition(to: [self::Paid])]
    case Unpaid = 'unpaid';

    #[FinalState]
    case Paid = 'paid';
}

enum HistoryOrderStatus: string
{
    #[InitialState]
    #[Transition(to: [self::Processing])]
    case Pending = 'pending';

    #[Transition(to: [self::Shipped])]
    case Processing = 'processing';

    #[FinalState]
    case Shipped = 'shipped';
}

/**
 * @method static HistoryOrderFactory factory($count = null, $state = [])
 */
class HistoryOrder extends Model
{
    use HasFactory;
    use HasStateMachines;

    protected $table = 'orders';
    protected $guarded = [];
    protected $casts = [
        'status' => HistoryOrderStatus::class,
        'payment_status' => HistoryPaymentStatus::class,
    ];

    protected static function newFactory(): HistoryOrderFactory
    {
        return HistoryOrderFactory::new();
    }
}

// ---- Tests ----

it('records transition history', function () {
    $order = HistoryOrder::factory()->create(['status' => 'pending', 'payment_status' => 'unpaid']);

    $order->transitionTo(HistoryOrderStatus::Processing, ['reason' => 'payment ok']);

    $history = $order->stateHistory('status');

    expect($history)->toHaveCount(1)
        ->and($history->first()->from)->toBe('pending')
        ->and($history->first()->to)->toBe('processing')
        ->and($history->first()->field)->toBe('status')
        ->and($history->first()->metadata)->toBe(['reason' => 'payment ok'])
        ->and($history->first()->transitioned_at)->not->toBeNull();
});

it('stores null metadata when none provided', function () {
    $order = HistoryOrder::factory()->create(['status' => 'pending']);

    $order->transitionTo(HistoryOrderStatus::Processing);

    $history = $order->stateHistory('status');
    expect($history->first()->metadata)->toBeNull();
});

it('returns history for all fields when no field specified', function () {
    $order = HistoryOrder::factory()->create(['status' => 'pending', 'payment_status' => 'unpaid']);

    $order->transitionTo(HistoryOrderStatus::Processing);
    $order->transitionTo(HistoryPaymentStatus::Paid);

    $allHistory = $order->stateHistory();

    expect($allHistory)->toHaveCount(2)
        ->and($allHistory->pluck('field')->unique()->sort()->values()->all())->toBe(['payment_status', 'status']);
});

it('supports multiple state machine fields independently', function () {
    $order = HistoryOrder::factory()->create(['status' => 'pending', 'payment_status' => 'unpaid']);

    $order->transitionTo(HistoryOrderStatus::Processing);
    $order->transitionTo('payment_status', HistoryPaymentStatus::Paid);

    expect($order->fresh()->status)->toBe(HistoryOrderStatus::Processing)
        ->and($order->fresh()->payment_status)->toBe(HistoryPaymentStatus::Paid)
        ->and($order->stateHistory('status'))->toHaveCount(1)
        ->and($order->stateHistory('payment_status'))->toHaveCount(1);
});

it('records multiple transitions in order', function () {
    $order = HistoryOrder::factory()->create(['status' => 'pending']);

    $order->transitionTo(HistoryOrderStatus::Processing);
    $order->transitionTo(HistoryOrderStatus::Shipped);

    $history = $order->stateHistory('status');

    expect($history)->toHaveCount(2)
        ->and($history[0]->from)->toBe('pending')
        ->and($history[0]->to)->toBe('processing')
        ->and($history[1]->from)->toBe('processing')
        ->and($history[1]->to)->toBe('shipped');
});
