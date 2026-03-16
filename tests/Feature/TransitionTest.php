<?php

declare(strict_types=1);

use SoylentGreenStudio\EnumStates\Exceptions\FinalStateException;
use SoylentGreenStudio\EnumStates\Exceptions\InvalidTransitionException;
use SoylentGreenStudio\EnumStates\Tests\Support\TestOrder;
use SoylentGreenStudio\EnumStates\Tests\Support\TestOrderStatus;

it('transitions from pending to processing', function () {
    $order = TestOrder::create(['status' => 'pending']);

    $order->transitionTo(TestOrderStatus::Processing);

    expect($order->fresh()->status)->toBe(TestOrderStatus::Processing);
});

it('transitions from pending to cancelled', function () {
    $order = TestOrder::create(['status' => 'pending']);

    $order->transitionTo(TestOrderStatus::Cancelled);

    expect($order->fresh()->status)->toBe(TestOrderStatus::Cancelled);
});

it('transitions from processing to shipped', function () {
    $order = TestOrder::create(['status' => 'processing']);

    $order->transitionTo(TestOrderStatus::Shipped);

    expect($order->fresh()->status)->toBe(TestOrderStatus::Shipped);
});

it('throws when transition is not allowed', function () {
    $order = TestOrder::create(['status' => 'pending']);

    $order->transitionTo(TestOrderStatus::Shipped);
})->throws(InvalidTransitionException::class);

it('throws when transitioning from a final state', function () {
    $order = TestOrder::create(['status' => 'shipped']);

    $order->transitionTo(TestOrderStatus::Pending);
})->throws(FinalStateException::class);

it('canTransitionTo returns true for valid transitions', function () {
    $order = TestOrder::create(['status' => 'pending']);

    expect($order->canTransitionTo(TestOrderStatus::Processing))->toBeTrue();
    expect($order->canTransitionTo(TestOrderStatus::Cancelled))->toBeTrue();
});

it('canTransitionTo returns false for invalid transitions', function () {
    $order = TestOrder::create(['status' => 'pending']);

    expect($order->canTransitionTo(TestOrderStatus::Shipped))->toBeFalse();
});

it('canTransitionTo returns false for final states', function () {
    $order = TestOrder::create(['status' => 'shipped']);

    expect($order->canTransitionTo(TestOrderStatus::Pending))->toBeFalse();
});

it('supports field-name transition syntax', function () {
    $order = TestOrder::create(['status' => 'pending']);

    $order->transitionTo('status', TestOrderStatus::Processing);

    expect($order->fresh()->status)->toBe(TestOrderStatus::Processing);
});

it('auto-detects state machine fields', function () {
    $order = new TestOrder();
    $fields = $order->getStateMachineFields();

    expect($fields)->toHaveKey('status');
    expect($fields['status'])->toBe(TestOrderStatus::class);
});
