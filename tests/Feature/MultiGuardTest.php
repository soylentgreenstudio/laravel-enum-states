<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;
use SoylentGreenStudio\EnumStates\Attributes\InitialState;
use SoylentGreenStudio\EnumStates\Attributes\Transition;
use SoylentGreenStudio\EnumStates\Contracts\TransitionGuard;
use SoylentGreenStudio\EnumStates\Exceptions\InvalidTransitionException;
use SoylentGreenStudio\EnumStates\Traits\HasStateMachines;

// ---- Guards ----

class MultiAllowA implements TransitionGuard
{
    public static int $calls = 0;

    public function allow(Model $model, array $metadata): bool
    {
        self::$calls++;

        return true;
    }
}

class MultiAllowB implements TransitionGuard
{
    public static int $calls = 0;

    public function allow(Model $model, array $metadata): bool
    {
        self::$calls++;

        return true;
    }
}

class MultiBlockFirst implements TransitionGuard
{
    public static int $calls = 0;

    public function allow(Model $model, array $metadata): bool
    {
        self::$calls++;

        return false;
    }
}

class MultiBlockSecond implements TransitionGuard
{
    public static int $calls = 0;

    public function allow(Model $model, array $metadata): bool
    {
        self::$calls++;

        return false;
    }
}

class NotAGuardForMulti
{
    public function allow(Model $model, array $metadata): bool
    {
        return true;
    }
}

// ---- Enums ----

enum MultiGuardAllStatus: string
{
    #[InitialState]
    #[Transition(to: [self::Done], guard: [MultiAllowA::class, MultiAllowB::class])]
    case Pending = 'pending';

    case Done = 'done';
}

enum MultiGuardBlockStatus: string
{
    #[InitialState]
    #[Transition(to: [self::Done], guard: [MultiAllowA::class, MultiBlockFirst::class])]
    case Pending = 'pending';

    case Done = 'done';
}

enum MultiGuardShortCircuitStatus: string
{
    #[InitialState]
    #[Transition(to: [self::Done], guard: [MultiBlockFirst::class, MultiBlockSecond::class])]
    case Pending = 'pending';

    case Done = 'done';
}

enum MultiGuardSingleStringStatus: string
{
    #[InitialState]
    #[Transition(to: [self::Done], guard: MultiAllowA::class)]
    case Pending = 'pending';

    case Done = 'done';
}

enum MultiGuardBadImplStatus: string
{
    #[InitialState]
    #[Transition(to: [self::Done], guard: [MultiAllowA::class, NotAGuardForMulti::class])]
    case Pending = 'pending';

    case Done = 'done';
}

enum MultiGuardGraphStatus: string
{
    #[InitialState]
    #[Transition(to: [self::Done], guard: [MultiAllowA::class, MultiAllowB::class])]
    case Pending = 'pending';

    case Done = 'done';
}

class NativeMultiGuardOrder extends Model
{
    use HasStateMachines;

    protected $table = 'orders';
    protected $guarded = [];
    protected $casts = ['status' => MultiGuardAllStatus::class];
}

// ---- Tests ----

it('allows the transition when all guards in the array return true', function () {
    $order = NativeMultiGuardOrder::create(['status' => 'pending']);

    $order->transitionTo(MultiGuardAllStatus::Done);

    expect($order->fresh()->status)->toBe(MultiGuardAllStatus::Done);
});

it('blocks the transition when any guard in the array returns false', function () {
    $order = NativeMultiGuardOrder::create(['status' => 'pending']);
    $order->mergeCasts(['status' => MultiGuardBlockStatus::class]);
    $order->status = MultiGuardBlockStatus::Pending;

    $order->transitionTo(MultiGuardBlockStatus::Done);
})->throws(InvalidTransitionException::class, 'blocked by guard');

it('short-circuits: does not invoke guards after the first false', function () {
    MultiBlockFirst::$calls = 0;
    MultiBlockSecond::$calls = 0;

    $order = NativeMultiGuardOrder::create(['status' => 'pending']);
    $order->mergeCasts(['status' => MultiGuardShortCircuitStatus::class]);
    $order->status = MultiGuardShortCircuitStatus::Pending;

    try {
        $order->transitionTo(MultiGuardShortCircuitStatus::Done);
    } catch (InvalidTransitionException) {
        // expected
    }

    expect(MultiBlockFirst::$calls)->toBeGreaterThan(0)
        ->and(MultiBlockSecond::$calls)->toBe(0);
});

it('reports every guard class name that was checked in the exception message', function () {
    $order = NativeMultiGuardOrder::create(['status' => 'pending']);
    $order->mergeCasts(['status' => MultiGuardBlockStatus::class]);
    $order->status = MultiGuardBlockStatus::Pending;

    try {
        $order->transitionTo(MultiGuardBlockStatus::Done);
        $this->fail('Expected InvalidTransitionException');
    } catch (InvalidTransitionException $e) {
        expect($e->getMessage())
            ->toContain('MultiAllowA')
            ->toContain('MultiBlockFirst');
    }
});

it('accepts a single string guard as before (backward compat)', function () {
    $order = NativeMultiGuardOrder::create(['status' => 'pending']);
    $order->mergeCasts(['status' => MultiGuardSingleStringStatus::class]);
    $order->status = MultiGuardSingleStringStatus::Pending;

    $order->transitionTo(MultiGuardSingleStringStatus::Done);

    expect($order->fresh()->status)->toBe(MultiGuardSingleStringStatus::Done);
});

it('throws when one of the array guards does not implement TransitionGuard', function () {
    $order = NativeMultiGuardOrder::create(['status' => 'pending']);
    $order->mergeCasts(['status' => MultiGuardBadImplStatus::class]);
    $order->status = MultiGuardBadImplStatus::Pending;

    $order->transitionTo(MultiGuardBadImplStatus::Done);
})->throws(InvalidArgumentException::class, 'must implement');

it('graph command joins array guards with a + separator', function () {
    Artisan::call('enum-states:graph', ['enum' => MultiGuardGraphStatus::class]);
    $output = Artisan::output();

    expect($output)->toContain('guard: MultiAllowA+MultiAllowB');
});
