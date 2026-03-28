<?php

declare(strict_types=1);

use SoylentGreenStudio\EnumStates\Attributes\InitialState;
use SoylentGreenStudio\EnumStates\Attributes\Transition;
use SoylentGreenStudio\EnumStates\Contracts\TransitionGuard;
use SoylentGreenStudio\EnumStates\Contracts\TransitionHook;
use SoylentGreenStudio\EnumStates\Contracts\AsyncTransitionHook;
use SoylentGreenStudio\EnumStates\Exceptions\InvalidTransitionException;
use SoylentGreenStudio\EnumStates\StateMachineManager;
use SoylentGreenStudio\EnumStates\Traits\HasStateMachines;
use Illuminate\Database\Eloquent\Model;

// ---- Fakes: classes that do NOT implement the required interfaces ----

class NotAGuard
{
    public function allow(Model $model, array $metadata): bool
    {
        return true;
    }
}

class NotAHook
{
    public function handle(Model $model, mixed $from, mixed $to, array $metadata): void
    {
        // looks like a hook but implements neither interface
    }
}

// ---- Enums that use the fakes ----

enum FakeGuardStatus: string
{
    #[InitialState]
    #[Transition(to: [self::Done], guard: NotAGuard::class)]
    case Pending = 'pending';

    case Done = 'done';
}

enum FakeBeforeHookStatus: string
{
    #[InitialState]
    #[Transition(to: [self::Done], before: NotAHook::class)]
    case Pending = 'pending';

    case Done = 'done';
}

enum FakeAfterHookStatus: string
{
    #[InitialState]
    #[Transition(to: [self::Done], after: NotAHook::class)]
    case Pending = 'pending';

    case Done = 'done';
}

enum FakeAsyncBeforeHookStatus: string
{
    #[InitialState]
    #[Transition(to: [self::Done], before: NotAHook::class)]
    case Pending = 'pending';

    case Done = 'done';
}

// ---- Enum with two guards that both block (for guard name reporting) ----

class NamedGuardA implements TransitionGuard
{
    public function allow(Model $model, array $metadata): bool
    {
        return false;
    }
}

class NamedGuardB implements TransitionGuard
{
    public function allow(Model $model, array $metadata): bool
    {
        return false;
    }
}

enum MultiBlockGuardStatus: string
{
    #[InitialState]
    #[Transition(to: [self::Done], guard: NamedGuardA::class)]
    #[Transition(to: [self::Done], guard: NamedGuardB::class)]
    case Pending = 'pending';

    case Done = 'done';
}

// ---- Model that swaps casts dynamically ----

class ValidationTestOrder extends Model
{
    use HasStateMachines;

    protected $table = 'orders';
    protected $guarded = [];
    protected $casts = ['status' => FakeGuardStatus::class];
}

// ---- Tests ----

// === Fix #1: Guard that doesn't implement TransitionGuard throws InvalidArgumentException ===

it('throws InvalidArgumentException when guard does not implement TransitionGuard', function () {
    $order = ValidationTestOrder::create(['status' => 'pending']);

    $order->transitionTo(FakeGuardStatus::Done);
})->throws(InvalidArgumentException::class, 'must implement');

it('throws InvalidArgumentException for bad guard via canTransitionTo too', function () {
    $order = ValidationTestOrder::create(['status' => 'pending']);

    $order->canTransitionTo(FakeGuardStatus::Done);
})->throws(InvalidArgumentException::class, 'must implement');

// === Fix #3: detectStateMachineFields uses static cache ===

it('caches detectStateMachineFields statically across instances', function () {
    $order1 = ValidationTestOrder::create(['status' => 'pending']);
    $order2 = ValidationTestOrder::create(['status' => 'pending']);

    // Call on first instance to populate cache
    $fields1 = StateMachineManager::detectStateMachineFields($order1);
    // Call on second instance — should hit the static cache, not create new ReflectionEnum
    $fields2 = StateMachineManager::detectStateMachineFields($order2);

    expect($fields1)->toBe($fields2)
        ->and($fields1)->toHaveKey('status');
});

it('static cache invalidates when casts change via mergeCasts', function () {
    $order = ValidationTestOrder::create(['status' => 'pending']);

    $fieldsBefore = StateMachineManager::detectStateMachineFields($order);
    expect($fieldsBefore['status'])->toBe(FakeGuardStatus::class);

    // Swap cast to a different enum
    $order->mergeCasts(['status' => MultiBlockGuardStatus::class]);

    $fieldsAfter = StateMachineManager::detectStateMachineFields($order);
    expect($fieldsAfter['status'])->toBe(MultiBlockGuardStatus::class);
});

// === Fix #4: guardBlocked lists actual guard class names ===

it('lists specific guard class names in guardBlocked exception', function () {
    $order = ValidationTestOrder::create(['status' => 'pending']);
    $order->mergeCasts(['status' => MultiBlockGuardStatus::class]);
    $order->status = MultiBlockGuardStatus::Pending;

    try {
        $order->transitionTo(MultiBlockGuardStatus::Done);
        $this->fail('Expected InvalidTransitionException');
    } catch (InvalidTransitionException $e) {
        // Must contain both guard class names, not "all matching guards"
        expect($e->getMessage())
            ->toContain('NamedGuardA')
            ->toContain('NamedGuardB')
            ->not->toContain('all matching guards');
    }
});

// === Fix #5: Hook without interface throws instead of silent ignore ===

it('throws InvalidArgumentException when before hook does not implement any hook interface', function () {
    $order = ValidationTestOrder::create(['status' => 'pending']);
    $order->mergeCasts(['status' => FakeBeforeHookStatus::class]);
    $order->status = FakeBeforeHookStatus::Pending;

    $order->transitionTo(FakeBeforeHookStatus::Done);
})->throws(InvalidArgumentException::class, 'must implement');

it('throws InvalidArgumentException when after hook does not implement any hook interface', function () {
    $order = ValidationTestOrder::create(['status' => 'pending']);
    $order->mergeCasts(['status' => FakeAfterHookStatus::class]);
    $order->status = FakeAfterHookStatus::Pending;

    $order->transitionTo(FakeAfterHookStatus::Done);
})->throws(InvalidArgumentException::class, 'must implement');
