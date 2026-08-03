<?php

declare(strict_types=1);

use SoylentGreenStudio\EnumStates\Attributes\InitialState;
use SoylentGreenStudio\EnumStates\Attributes\Transition;
use SoylentGreenStudio\EnumStates\Contracts\TransitionHook;
use SoylentGreenStudio\EnumStates\Events\TransitionFailed;
use SoylentGreenStudio\EnumStates\Models\StateTransition;
use SoylentGreenStudio\EnumStates\Tests\Support\Factories\HookedOrderFactory;
use SoylentGreenStudio\EnumStates\Traits\HasStateMachines;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;

// ---- Test-local constructs ----

enum HookedStatus: string
{
    #[InitialState]
    #[Transition(to: [self::Active], before: RecordBeforeHook::class, after: RecordAfterHook::class)]
    #[Transition(to: [self::Failed], before: ThrowingHook::class)]
    case Pending = 'pending';

    case Active = 'active';
    case Failed = 'failed';
}

class HookLog
{
    public static array $log = [];

    public static function clear(): void
    {
        self::$log = [];
    }
}

class RecordBeforeHook implements TransitionHook
{
    public function handle(Model $model, mixed $from, mixed $to, array $metadata): void
    {
        HookLog::$log[] = ['before', $from->value, $to->value, $metadata];
    }
}

class RecordAfterHook implements TransitionHook
{
    public function handle(Model $model, mixed $from, mixed $to, array $metadata): void
    {
        HookLog::$log[] = ['after', $from->value, $to->value, $metadata];
    }
}

class ThrowingHook implements TransitionHook
{
    public function handle(Model $model, mixed $from, mixed $to, array $metadata): void
    {
        throw new RuntimeException('Hook failed intentionally');
    }
}

class MutatingBeforeHook implements TransitionHook
{
    public function handle(Model $model, mixed $from, mixed $to, array $metadata): void
    {
        $model->payment_status = 'touched-by-hook';
    }
}

enum MutatingHookStatus: string
{
    #[InitialState]
    #[Transition(to: [self::Active], before: MutatingBeforeHook::class)]
    case Pending = 'pending';

    case Active = 'active';
}

/**
 * @method static HookedOrderFactory factory($count = null, $state = [])
 */
class HookedOrder extends Model
{
    use HasFactory;
    use HasStateMachines;

    protected $table = 'orders';
    protected $guarded = [];
    protected $casts = ['status' => HookedStatus::class];

    protected static function newFactory(): HookedOrderFactory
    {
        return HookedOrderFactory::new();
    }
}

// ---- Tests ----

beforeEach(function () {
    HookLog::clear();
});

it('runs before and after hooks in order', function () {
    $order = HookedOrder::factory()->create(['status' => 'pending']);

    $order->transitionTo(HookedStatus::Active, ['key' => 'val']);

    expect(HookLog::$log)->toHaveCount(2)
        ->and(HookLog::$log[0][0])->toBe('before')
        ->and(HookLog::$log[1][0])->toBe('after')
        ->and(HookLog::$log[0][3])->toBe(['key' => 'val']);
});

it('rolls back transition when before hook throws', function () {
    $order = HookedOrder::factory()->create(['status' => 'pending']);

    try {
        $order->transitionTo(HookedStatus::Failed);
    } catch (RuntimeException) {
        // expected
    }

    expect($order->fresh()->status)->toBe(HookedStatus::Pending)
        ->and(StateTransition::count())->toBe(0);
});

it('persists attribute changes made by a before hook', function () {
    $order = HookedOrder::factory()->create(['status' => 'pending', 'payment_status' => 'unpaid']);
    $order->mergeCasts(['status' => MutatingHookStatus::class]);
    $order->status = MutatingHookStatus::Pending;

    $order->transitionTo(MutatingHookStatus::Active);

    // The hook receives the locked instance that is about to be saved,
    // so its changes are committed together with the state change.
    expect($order->fresh()->payment_status)->toBe('touched-by-hook')
        ->and($order->fresh()->status)->toBe(MutatingHookStatus::Active);
});

it('fires TransitionFailed event when hook throws', function () {
    Event::fake([
        TransitionFailed::class,
    ]);

    $order = HookedOrder::factory()->create(['status' => 'pending']);

    try {
        $order->transitionTo(HookedStatus::Failed);
    } catch (RuntimeException) {
        // expected
    }

    Event::assertDispatched(
        TransitionFailed::class
    );
});
