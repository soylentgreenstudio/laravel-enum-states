<?php

declare(strict_types=1);

use SoylentGreenStudio\EnumStates\Events\TransitionCompleted;
use SoylentGreenStudio\EnumStates\Events\TransitionStarted;
use SoylentGreenStudio\EnumStates\Tests\Support\TestOrder;
use SoylentGreenStudio\EnumStates\Tests\Support\TestOrderStatus;
use Illuminate\Support\Facades\Event;

it('fires TransitionStarted and TransitionCompleted events', function () {
    Event::fake([TransitionStarted::class, TransitionCompleted::class]);

    $order = TestOrder::create(['status' => 'pending']);
    $order->transitionTo(TestOrderStatus::Processing);

    Event::assertDispatched(TransitionStarted::class, function ($e) {
        return $e->field === 'status'
            && $e->from === TestOrderStatus::Pending
            && $e->to === TestOrderStatus::Processing;
    });

    Event::assertDispatched(TransitionCompleted::class, function ($e) {
        return $e->field === 'status'
            && $e->from === TestOrderStatus::Pending
            && $e->to === TestOrderStatus::Processing;
    });
});

it('includes metadata in events', function () {
    Event::fake([TransitionStarted::class, TransitionCompleted::class]);

    $order = TestOrder::create(['status' => 'pending']);
    $order->transitionTo(TestOrderStatus::Processing, ['user_id' => 42]);

    Event::assertDispatched(TransitionStarted::class, function ($e) {
        return $e->metadata === ['user_id' => 42];
    });

    Event::assertDispatched(TransitionCompleted::class, function ($e) {
        return $e->metadata === ['user_id' => 42];
    });
});
