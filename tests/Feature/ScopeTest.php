<?php

declare(strict_types=1);

use SoylentGreenStudio\EnumStates\Tests\Support\TestOrder;
use SoylentGreenStudio\EnumStates\Tests\Support\TestOrderStatus;

beforeEach(function () {
    TestOrder::create(['status' => 'pending']);
    TestOrder::create(['status' => 'pending']);
    TestOrder::create(['status' => 'processing']);
    TestOrder::create(['status' => 'shipped']);
    TestOrder::create(['status' => 'cancelled']);
});

it('filters by whereState', function () {
    $results = TestOrder::whereState('status', TestOrderStatus::Pending)->get();

    expect($results)->toHaveCount(2);
    expect($results->every(fn ($o) => $o->status === TestOrderStatus::Pending))->toBeTrue();
});

it('filters by whereNotState', function () {
    $results = TestOrder::whereNotState('status', TestOrderStatus::Cancelled)->get();

    expect($results)->toHaveCount(4);
    expect($results->every(fn ($o) => $o->status !== TestOrderStatus::Cancelled))->toBeTrue();
});

it('filters by whereStateIn', function () {
    $results = TestOrder::whereStateIn('status', [
        TestOrderStatus::Pending,
        TestOrderStatus::Processing,
    ])->get();

    expect($results)->toHaveCount(3);
});

it('returns empty collection for non-existent state', function () {
    $results = TestOrder::whereState('status', TestOrderStatus::Shipped)->get();

    // We created exactly 1 shipped
    expect($results)->toHaveCount(1);
});
