<?php

declare(strict_types=1);

use SoylentGreenStudio\EnumStates\Attributes\FinalState;
use SoylentGreenStudio\EnumStates\Attributes\InitialState;
use SoylentGreenStudio\EnumStates\Attributes\Transition;
use SoylentGreenStudio\EnumStates\Traits\HasStateMachines;
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

class HistoryOrder extends Model
{
    use HasStateMachines;

    protected $table = 'orders';
    protected $guarded = [];
    protected $casts = [
        'status' => HistoryOrderStatus::class,
        'payment_status' => HistoryPaymentStatus::class,
    ];
}

// ---- Tests ----

it('records transition history', function () {
    $order = HistoryOrder::create(['status' => 'pending', 'payment_status' => 'unpaid']);

    $order->transitionTo(HistoryOrderStatus::Processing, ['reason' => 'payment ok']);

    $history = $order->stateHistory('status');

    expect($history)->toHaveCount(1);
    expect($history->first()->from)->toBe('pending');
    expect($history->first()->to)->toBe('processing');
    expect($history->first()->field)->toBe('status');
    expect($history->first()->metadata)->toBe(['reason' => 'payment ok']);
    expect($history->first()->transitioned_at)->not->toBeNull();
});

it('stores null metadata when none provided', function () {
    $order = HistoryOrder::create(['status' => 'pending']);

    $order->transitionTo(HistoryOrderStatus::Processing);

    $history = $order->stateHistory('status');
    expect($history->first()->metadata)->toBeNull();
});

it('returns history for all fields when no field specified', function () {
    $order = HistoryOrder::create(['status' => 'pending', 'payment_status' => 'unpaid']);

    $order->transitionTo(HistoryOrderStatus::Processing);
    $order->transitionTo(HistoryPaymentStatus::Paid);

    $allHistory = $order->stateHistory();

    expect($allHistory)->toHaveCount(2);
    expect($allHistory->pluck('field')->unique()->sort()->values()->all())->toBe(['payment_status', 'status']);
});

it('supports multiple state machine fields independently', function () {
    $order = HistoryOrder::create(['status' => 'pending', 'payment_status' => 'unpaid']);

    $order->transitionTo(HistoryOrderStatus::Processing);
    $order->transitionTo('payment_status', HistoryPaymentStatus::Paid);

    expect($order->fresh()->status)->toBe(HistoryOrderStatus::Processing);
    expect($order->fresh()->payment_status)->toBe(HistoryPaymentStatus::Paid);

    expect($order->stateHistory('status'))->toHaveCount(1);
    expect($order->stateHistory('payment_status'))->toHaveCount(1);
});

it('records multiple transitions in order', function () {
    $order = HistoryOrder::create(['status' => 'pending']);

    $order->transitionTo(HistoryOrderStatus::Processing);
    $order->transitionTo(HistoryOrderStatus::Shipped);

    $history = $order->stateHistory('status');

    expect($history)->toHaveCount(2);
    expect($history[0]->from)->toBe('pending');
    expect($history[0]->to)->toBe('processing');
    expect($history[1]->from)->toBe('processing');
    expect($history[1]->to)->toBe('shipped');
});
