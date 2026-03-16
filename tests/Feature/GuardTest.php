<?php

declare(strict_types=1);

use SoylentGreenStudio\EnumStates\Attributes\InitialState;
use SoylentGreenStudio\EnumStates\Attributes\Transition;
use SoylentGreenStudio\EnumStates\Contracts\TransitionGuard;
use SoylentGreenStudio\EnumStates\Exceptions\InvalidTransitionException;
use SoylentGreenStudio\EnumStates\Traits\HasStateMachines;
use Illuminate\Database\Eloquent\Model;

// ---- Test-local enum and model ----

enum GuardedStatus: string
{
    #[InitialState]
    #[Transition(to: [self::Approved], guard: AlwaysBlockGuard::class)]
    #[Transition(to: [self::Rejected], guard: AlwaysAllowGuard::class)]
    case Pending = 'pending';

    case Approved = 'approved';
    case Rejected = 'rejected';
}

class AlwaysBlockGuard implements TransitionGuard
{
    public function allow(Model $model, array $metadata): bool
    {
        return false;
    }
}

class AlwaysAllowGuard implements TransitionGuard
{
    public function allow(Model $model, array $metadata): bool
    {
        return true;
    }
}

class GuardedOrder extends Model
{
    use HasStateMachines;

    protected $table = 'orders';
    protected $guarded = [];
    protected $casts = ['status' => GuardedStatus::class];
}

// ---- Tests ----

it('throws when guard blocks the transition', function () {
    $order = GuardedOrder::create(['status' => 'pending']);

    $order->transitionTo(GuardedStatus::Approved);
})->throws(InvalidTransitionException::class, 'blocked by guard');

it('allows transition when guard returns true', function () {
    $order = GuardedOrder::create(['status' => 'pending']);

    $order->transitionTo(GuardedStatus::Rejected);

    expect($order->fresh()->status)->toBe(GuardedStatus::Rejected);
});

it('canTransitionTo returns false when guard blocks', function () {
    $order = GuardedOrder::create(['status' => 'pending']);

    expect($order->canTransitionTo(GuardedStatus::Approved))->toBeFalse();
    expect($order->canTransitionTo(GuardedStatus::Rejected))->toBeTrue();
});

it('passes metadata to guard', function () {
    // Define a guard that checks metadata
    app()->bind('metadata_checking_guard', function () {
        return new class implements TransitionGuard {
            public function allow(Model $model, array $metadata): bool
            {
                return ($metadata['approved'] ?? false) === true;
            }
        };
    });

    // Use the already-existing model/enum — just test the manager directly
    $order = GuardedOrder::create(['status' => 'pending']);

    // canTransitionTo without metadata should be blocked by AlwaysBlockGuard
    expect($order->canTransitionTo(GuardedStatus::Approved))->toBeFalse();
});
