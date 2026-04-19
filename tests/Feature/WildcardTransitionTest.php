<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;
use SoylentGreenStudio\EnumStates\Attributes\FinalState;
use SoylentGreenStudio\EnumStates\Attributes\InitialState;
use SoylentGreenStudio\EnumStates\Attributes\Transition;
use SoylentGreenStudio\EnumStates\Attributes\TransitionFrom;
use SoylentGreenStudio\EnumStates\Contracts\TransitionGuard;
use SoylentGreenStudio\EnumStates\Exceptions\FinalStateException;
use SoylentGreenStudio\EnumStates\Exceptions\InvalidTransitionException;
use SoylentGreenStudio\EnumStates\StateMachineManager;
use SoylentGreenStudio\EnumStates\Traits\HasStateMachines;

// ---- Guards ----

class WildcardAllowGuard implements TransitionGuard
{
    public function allow(Model $model, array $metadata): bool
    {
        return true;
    }
}

class WildcardBlockGuard implements TransitionGuard
{
    public function allow(Model $model, array $metadata): bool
    {
        return false;
    }
}

// ---- Enum with wildcard target (Cancelled reachable from any non-final) ----

enum WildcardStatus: string
{
    #[InitialState]
    #[Transition(to: [self::Processing])]
    case Pending = 'pending';

    #[Transition(to: [self::Shipped])]
    case Processing = 'processing';

    #[FinalState]
    case Shipped = 'shipped';

    #[FinalState]
    #[TransitionFrom(from: '*')]
    case Cancelled = 'cancelled';
}

class WildcardOrder extends Model
{
    use HasStateMachines;

    protected $table = 'orders';
    protected $guarded = [];
    protected $casts = ['status' => WildcardStatus::class];
}

// ---- Non-final wildcard target (for self-loop exclusion test) ----

enum NonFinalWildcardTargetStatus: string
{
    #[InitialState]
    case Alpha = 'alpha';

    case Beta = 'beta';

    #[TransitionFrom(from: '*')]
    case Gamma = 'gamma';
}

class NonFinalWildcardOrder extends Model
{
    use HasStateMachines;

    protected $table = 'orders';
    protected $guarded = [];
    protected $casts = ['status' => NonFinalWildcardTargetStatus::class];
}

// ---- Enum with an explicit source list ----

enum ExplicitFromStatus: string
{
    #[InitialState]
    case Draft = 'draft';

    case Review = 'review';

    case Approved = 'approved';

    #[FinalState]
    #[TransitionFrom(from: [self::Draft, self::Review])]
    case Archived = 'archived';
}

class ExplicitFromOrder extends Model
{
    use HasStateMachines;

    protected $table = 'orders';
    protected $guarded = [];
    protected $casts = ['status' => ExplicitFromStatus::class];
}

// ---- Guarded reverse transitions ----

enum GuardedReverseAllowStatus: string
{
    #[InitialState]
    case Open = 'open';

    #[FinalState]
    #[TransitionFrom(from: '*', guard: WildcardAllowGuard::class)]
    case Closed = 'closed';
}

class GuardedReverseAllowOrder extends Model
{
    use HasStateMachines;

    protected $table = 'orders';
    protected $guarded = [];
    protected $casts = ['status' => GuardedReverseAllowStatus::class];
}

enum GuardedReverseBlockStatus: string
{
    #[InitialState]
    case Open = 'open';

    #[FinalState]
    #[TransitionFrom(from: '*', guard: WildcardBlockGuard::class)]
    case Closed = 'closed';
}

class GuardedReverseBlockOrder extends Model
{
    use HasStateMachines;

    protected $table = 'orders';
    protected $guarded = [];
    protected $casts = ['status' => GuardedReverseBlockStatus::class];
}

// ---- Merge: forward Transition + reverse TransitionFrom on the same edge ----

enum MergedDirectionsStatus: string
{
    #[InitialState]
    #[Transition(to: [self::Done], guard: WildcardBlockGuard::class)]
    case Pending = 'pending';

    #[TransitionFrom(from: [self::Pending], guard: WildcardAllowGuard::class)]
    case Done = 'done';
}

class MergedDirectionsOrder extends Model
{
    use HasStateMachines;

    protected $table = 'orders';
    protected $guarded = [];
    protected $casts = ['status' => MergedDirectionsStatus::class];
}

// ---- Foreign-enum rejection ----

enum ForeignSourceEnum: string
{
    case Alpha = 'alpha';
}

enum ForeignFromStatus: string
{
    #[InitialState]
    case Start = 'start';

    #[FinalState]
    #[TransitionFrom(from: [ForeignSourceEnum::Alpha])]
    case Finish = 'finish';
}

// ---- Tests ----

it('allows wildcard transition from any non-final case to the target', function () {
    $order = WildcardOrder::create(['status' => 'pending']);
    $order->transitionTo(WildcardStatus::Cancelled);
    expect($order->fresh()->status)->toBe(WildcardStatus::Cancelled);

    $order2 = WildcardOrder::create(['status' => 'processing']);
    $order2->transitionTo(WildcardStatus::Cancelled);
    expect($order2->fresh()->status)->toBe(WildcardStatus::Cancelled);
});

it('excludes final states from wildcard source expansion', function () {
    $order = WildcardOrder::create(['status' => 'shipped']);
    $order->transitionTo(WildcardStatus::Cancelled);
})->throws(FinalStateException::class);

it('excludes the target itself from its own wildcard (no self-loop)', function () {
    $order = NonFinalWildcardOrder::create(['status' => 'gamma']);

    expect($order->canTransitionTo(NonFinalWildcardTargetStatus::Gamma))->toBeFalse();
});

it('allows transitions from an explicit case list in TransitionFrom', function () {
    $draftOrder = ExplicitFromOrder::create(['status' => 'draft']);
    $draftOrder->transitionTo(ExplicitFromStatus::Archived);
    expect($draftOrder->fresh()->status)->toBe(ExplicitFromStatus::Archived);

    $reviewOrder = ExplicitFromOrder::create(['status' => 'review']);
    $reviewOrder->transitionTo(ExplicitFromStatus::Archived);
    expect($reviewOrder->fresh()->status)->toBe(ExplicitFromStatus::Archived);

    $approvedOrder = ExplicitFromOrder::create(['status' => 'approved']);
    expect($approvedOrder->canTransitionTo(ExplicitFromStatus::Archived))->toBeFalse();
});

it('honours guards declared on TransitionFrom (allow path)', function () {
    $order = GuardedReverseAllowOrder::create(['status' => 'open']);
    $order->transitionTo(GuardedReverseAllowStatus::Closed);

    expect($order->fresh()->status)->toBe(GuardedReverseAllowStatus::Closed);
});

it('honours guards declared on TransitionFrom (block path)', function () {
    $order = GuardedReverseBlockOrder::create(['status' => 'open']);
    $order->transitionTo(GuardedReverseBlockStatus::Closed);
})->throws(InvalidTransitionException::class, 'blocked by guard');

it('merges forward Transition and reverse TransitionFrom for the same edge', function () {
    // Forward guard blocks, reverse guard allows. OR semantics across the
    // two Transition objects on the source case should allow the transit.
    $order = MergedDirectionsOrder::create(['status' => 'pending']);
    $order->transitionTo(MergedDirectionsStatus::Done);

    expect($order->fresh()->status)->toBe(MergedDirectionsStatus::Done);
});

it('throws InvalidArgumentException when TransitionFrom::from contains a foreign enum case', function () {
    StateMachineManager::getTransitions(ForeignFromStatus::class);
})->throws(InvalidArgumentException::class, 'must contain only instances of');

it('graph command renders virtual reverse transitions', function () {
    Artisan::call('enum-states:graph', ['enum' => WildcardStatus::class]);
    $output = Artisan::output();

    expect($output)
        ->toContain('→ Cancelled')
        ->toContain('→ Processing')
        ->toContain('→ Shipped');
});
