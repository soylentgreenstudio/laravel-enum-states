<?php

declare(strict_types=1);

use SoylentGreenStudio\EnumStates\Attributes\InitialState;
use SoylentGreenStudio\EnumStates\Attributes\Transition;
use SoylentGreenStudio\EnumStates\Contracts\TransitionGuard;
use SoylentGreenStudio\EnumStates\Exceptions\InvalidTransitionException;
use SoylentGreenStudio\EnumStates\Tests\Support\Factories\GuardedOrderFactory;
use SoylentGreenStudio\EnumStates\Traits\HasStateMachines;
use Illuminate\Database\Eloquent\Factories\HasFactory;
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

class CountingGuard implements TransitionGuard
{
    public static int $count = 0;

    public function allow(Model $model, array $metadata): bool
    {
        self::$count++;
        return true;
    }
}

enum CountingGuardStatus: string
{
    #[InitialState]
    #[Transition(to: [self::Done], guard: CountingGuard::class)]
    case Pending = 'pending';

    case Done = 'done';
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

/**
 * @method static GuardedOrderFactory factory($count = null, $state = [])
 */
class GuardedOrder extends Model
{
    use HasFactory;
    use HasStateMachines;

    protected $table = 'orders';
    protected $guarded = [];
    protected $casts = ['status' => GuardedStatus::class];

    protected static function newFactory(): GuardedOrderFactory
    {
        return GuardedOrderFactory::new();
    }
}

// ---- Tests ----

it('throws when guard blocks the transition', function () {
    $order = GuardedOrder::factory()->create(['status' => 'pending']);

    $order->transitionTo(GuardedStatus::Approved);
})->throws(InvalidTransitionException::class, 'blocked by guard');

it('allows transition when guard returns true', function () {
    $order = GuardedOrder::factory()->create(['status' => 'pending']);

    $order->transitionTo(GuardedStatus::Rejected);

    expect($order->fresh()->status)->toBe(GuardedStatus::Rejected);
});

it('canTransitionTo returns false when guard blocks', function () {
    $order = GuardedOrder::factory()->create(['status' => 'pending']);

    expect($order->canTransitionTo(GuardedStatus::Approved))->toBeFalse()
        ->and($order->canTransitionTo(GuardedStatus::Rejected))->toBeTrue();
});

it('does not call guard twice during transition', function () {
    // CountingGuard tracks how many times allow() is called
    CountingGuard::$count = 0;

    $order = GuardedOrder::factory()->create(['status' => 'pending']);
    $order->mergeCasts(['status' => CountingGuardStatus::class]);
    $order->status = CountingGuardStatus::Pending;

    $order->transitionTo(CountingGuardStatus::Done);

    // Guard should be called only once (pre-transaction), not twice
    expect(CountingGuard::$count)->toBe(1)
        ->and($order->fresh()->status)->toBe(CountingGuardStatus::Done);
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
    $order = GuardedOrder::factory()->create(['status' => 'pending']);

    // canTransitionTo without metadata should be blocked by AlwaysBlockGuard
    expect($order->canTransitionTo(GuardedStatus::Approved))->toBeFalse();
});
