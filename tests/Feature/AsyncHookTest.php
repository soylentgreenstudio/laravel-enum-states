<?php

declare(strict_types=1);

use SoylentGreenStudio\EnumStates\Attributes\InitialState;
use SoylentGreenStudio\EnumStates\Attributes\Transition;
use SoylentGreenStudio\EnumStates\Contracts\AsyncTransitionHook;
use SoylentGreenStudio\EnumStates\Contracts\TransitionHook;
use SoylentGreenStudio\EnumStates\Jobs\ProcessTransitionHook;
use SoylentGreenStudio\EnumStates\Models\StateTransition;
use SoylentGreenStudio\EnumStates\Tests\Support\Factories\AsyncHookedOrderFactory;
use SoylentGreenStudio\EnumStates\Traits\HasStateMachines;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Queue;

// ---- Test-local constructs ----

class AsyncBeforeHook implements AsyncTransitionHook
{
    public static array $log = [];

    public function handle(Model $model, mixed $from, mixed $to, array $metadata): void
    {
        self::$log[] = ['async-before', $from->value, $to->value, $metadata];
    }

    public function queue(): ?string
    {
        return null;
    }
}

class AsyncAfterHook implements AsyncTransitionHook
{
    public static array $log = [];

    public function handle(Model $model, mixed $from, mixed $to, array $metadata): void
    {
        self::$log[] = ['async-after', $from->value, $to->value, $metadata];
    }

    public function queue(): ?string
    {
        return null;
    }
}

class NamedQueueHook implements AsyncTransitionHook
{
    public static array $log = [];

    public function handle(Model $model, mixed $from, mixed $to, array $metadata): void
    {
        self::$log[] = ['named-queue', $from->value, $to->value, $metadata];
    }

    public function queue(): ?string
    {
        return 'high-priority';
    }
}

class SyncBeforeLog implements TransitionHook
{
    public static array $log = [];

    public function handle(Model $model, mixed $from, mixed $to, array $metadata): void
    {
        self::$log[] = ['sync-before', $from->value, $to->value, $metadata];
    }
}

class ThrowingSyncBeforeHook implements TransitionHook
{
    public function handle(Model $model, mixed $from, mixed $to, array $metadata): void
    {
        throw new RuntimeException('Sync before hook failed');
    }
}

enum AsyncHookedStatus: string
{
    #[InitialState]
    #[Transition(to: [self::Active], before: AsyncBeforeHook::class, after: AsyncAfterHook::class)]
    #[Transition(to: [self::Failed], before: ThrowingSyncBeforeHook::class)]
    case Pending = 'pending';

    case Active = 'active';
    case Failed = 'failed';
}

enum NamedQueueStatus: string
{
    #[InitialState]
    #[Transition(to: [self::Done], after: NamedQueueHook::class)]
    case Pending = 'pending';

    case Done = 'done';
}

enum MixedHookStatus: string
{
    #[InitialState]
    #[Transition(to: [self::Active], before: SyncBeforeLog::class, after: AsyncAfterHook::class)]
    case Pending = 'pending';

    case Active = 'active';
}

class AsyncHookedOrder extends Model
{
    use HasFactory;
    use HasStateMachines;

    protected $table = 'orders';
    protected $guarded = [];
    protected $casts = ['status' => AsyncHookedStatus::class];

    protected static function newFactory(): AsyncHookedOrderFactory
    {
        return AsyncHookedOrderFactory::new();
    }
}

// ---- Tests ----

beforeEach(function () {
    AsyncBeforeHook::$log = [];
    AsyncAfterHook::$log = [];
    NamedQueueHook::$log = [];
    SyncBeforeLog::$log = [];
});

it('dispatches async before hook as fire-and-forget', function () {
    Queue::fake();

    $order = AsyncHookedOrder::factory()->create(['status' => 'pending']);
    $order->transitionTo(AsyncHookedStatus::Active);

    Queue::assertPushed(ProcessTransitionHook::class, function (ProcessTransitionHook $job) {
        return $job->hookClass === AsyncBeforeHook::class;
    });

    expect($order->fresh()->status)->toBe(AsyncHookedStatus::Active);
});

it('dispatches async after hook after commit', function () {
    Queue::fake();

    $order = AsyncHookedOrder::factory()->create(['status' => 'pending']);
    $order->transitionTo(AsyncHookedStatus::Active);

    Queue::assertPushed(ProcessTransitionHook::class, function (ProcessTransitionHook $job) {
        return $job->hookClass === AsyncAfterHook::class;
    });
});

it('dispatches async hook to named queue', function () {
    Queue::fake();

    // Temporarily swap casts for NamedQueueStatus
    $order = AsyncHookedOrder::factory()->create(['status' => 'pending']);
    $order->mergeCasts(['status' => NamedQueueStatus::class]);
    $order->status = NamedQueueStatus::Pending;

    $order->transitionTo(NamedQueueStatus::Done);

    Queue::assertPushedOn('high-priority', ProcessTransitionHook::class);
});

it('does not dispatch async after hook if transaction rolls back', function () {
    Queue::fake();

    $order = AsyncHookedOrder::factory()->create(['status' => 'pending']);

    try {
        $order->transitionTo(AsyncHookedStatus::Failed);
    } catch (RuntimeException) {
        // expected — sync before hook throws
    }

    // Only the async before hook from the Failed transition should NOT be dispatched
    // because ThrowingSyncBeforeHook is a sync hook that throws, rolling back the transaction
    Queue::assertNotPushed(ProcessTransitionHook::class, function (ProcessTransitionHook $job) {
        return $job->hookClass === AsyncAfterHook::class;
    });

    expect($order->fresh()->status)->toBe(AsyncHookedStatus::Pending);
    expect(StateTransition::count())->toBe(0);
});

it('runs sync hooks normally with backward compatibility', function () {
    $order = AsyncHookedOrder::factory()->create(['status' => 'pending']);
    $order->mergeCasts(['status' => MixedHookStatus::class]);
    $order->status = MixedHookStatus::Pending;

    Queue::fake();

    $order->transitionTo(MixedHookStatus::Active, ['key' => 'val']);

    // Sync before hook ran immediately
    expect(SyncBeforeLog::$log)->toHaveCount(1)
        ->and(SyncBeforeLog::$log[0][0])->toBe('sync-before')
        ->and(SyncBeforeLog::$log[0][3])->toBe(['key' => 'val']);

    // Async after hook was dispatched, not run synchronously
    Queue::assertPushed(ProcessTransitionHook::class, function (ProcessTransitionHook $job) {
        return $job->hookClass === AsyncAfterHook::class;
    });

    expect($order->fresh()->status)->toBe(MixedHookStatus::Active);
});

it('ProcessTransitionHook job executes the hook handle method', function () {
    $order = AsyncHookedOrder::factory()->create(['status' => 'pending']);

    $job = new ProcessTransitionHook(
        hookClass: AsyncBeforeHook::class,
        model: $order,
        from: AsyncHookedStatus::Pending,
        to: AsyncHookedStatus::Active,
        metadata: ['test' => true],
    );

    $job->handle();

    expect(AsyncBeforeHook::$log)->toHaveCount(1)
        ->and(AsyncBeforeHook::$log[0])->toBe(['async-before', 'pending', 'active', ['test' => true]]);
});

it('dispatches both before and after async hooks for same transition', function () {
    Queue::fake();

    $order = AsyncHookedOrder::factory()->create(['status' => 'pending']);
    $order->transitionTo(AsyncHookedStatus::Active);

    Queue::assertPushed(ProcessTransitionHook::class, 2);
});
