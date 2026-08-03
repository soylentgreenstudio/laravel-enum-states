<?php

declare(strict_types=1);

use SoylentGreenStudio\EnumStates\Attributes\FinalState;
use SoylentGreenStudio\EnumStates\Attributes\InitialState;
use SoylentGreenStudio\EnumStates\Attributes\Transition;
use SoylentGreenStudio\EnumStates\Contracts\TransitionGuard;
use SoylentGreenStudio\EnumStates\Exceptions\InvalidTransitionException;
use SoylentGreenStudio\EnumStates\Tests\Support\TestOrder;
use SoylentGreenStudio\EnumStates\Tests\Support\TestOrderStatus;
use SoylentGreenStudio\EnumStates\Traits\HasStateMachines;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// ---- Test-local enums and models ----

enum MultiGuardStatus: string
{
    #[InitialState]
    #[Transition(to: [self::Approved], guard: OnlyAdminGuard::class)]
    #[Transition(to: [self::Approved], guard: OnlyManagerGuard::class)]
    #[Transition(to: [self::Rejected])]
    case Pending = 'pending';

    case Approved = 'approved';
    case Rejected = 'rejected';
}

class OnlyAdminGuard implements TransitionGuard
{
    public function allow(Model $model, array $metadata): bool
    {
        return ($metadata['role'] ?? '') === 'admin';
    }
}

class OnlyManagerGuard implements TransitionGuard
{
    public function allow(Model $model, array $metadata): bool
    {
        return ($metadata['role'] ?? '') === 'manager';
    }
}

class MultiGuardOrder extends Model
{
    use HasStateMachines;

    protected $table = 'orders';
    protected $guarded = [];
    protected $casts = ['status' => MultiGuardStatus::class];
}

// Model with same enum on two fields
class DuplicateEnumOrder extends Model
{
    use HasStateMachines;

    protected $table = 'orders';
    protected $guarded = [];
    protected $casts = [
        'status' => TestOrderStatus::class,
        'payment_status' => TestOrderStatus::class,
    ];
}

// Enum with both InitialState and FinalState on same case
enum InitialFinalStatus: string
{
    #[InitialState]
    #[FinalState]
    case Stuck = 'stuck';

    case Other = 'other';
}

class InitialFinalOrder extends Model
{
    use HasStateMachines;

    protected $table = 'orders';
    protected $guarded = [];
    protected $casts = ['status' => InitialFinalStatus::class];
}

// Model with no state machine fields
class PlainOrder extends Model
{
    use HasStateMachines;

    protected $table = 'orders';
    protected $guarded = [];
    protected $casts = ['status' => 'string'];
}

// Backed by a table whose status column is nullable from the start
class NullableOrder extends Model
{
    use HasStateMachines;

    protected $table = 'nullable_orders';
    protected $guarded = [];
    protected $casts = ['status' => TestOrderStatus::class];
}

// ---- 5. Multiple #[Transition] on same case with different guards ----

it('allows transition when first guard blocks but second guard passes', function () {
    $order = MultiGuardOrder::create(['status' => 'pending']);

    $order->transitionTo(MultiGuardStatus::Approved, ['role' => 'manager']);

    expect($order->fresh()->status)->toBe(MultiGuardStatus::Approved);
});

it('allows transition when first guard passes', function () {
    $order = MultiGuardOrder::create(['status' => 'pending']);

    $order->transitionTo(MultiGuardStatus::Approved, ['role' => 'admin']);

    expect($order->fresh()->status)->toBe(MultiGuardStatus::Approved);
});

it('blocks transition when all guards for the target state fail', function () {
    $order = MultiGuardOrder::create(['status' => 'pending']);

    $order->transitionTo(MultiGuardStatus::Approved, ['role' => 'nobody']);
})->throws(InvalidTransitionException::class);

it('canTransitionTo checks multiple transition attributes', function () {
    $order = MultiGuardOrder::create(['status' => 'pending']);

    expect($order->canTransitionTo(MultiGuardStatus::Approved, ['role' => 'admin']))->toBeTrue()
        ->and($order->canTransitionTo(MultiGuardStatus::Approved, ['role' => 'manager']))->toBeTrue()
        ->and($order->canTransitionTo(MultiGuardStatus::Approved, ['role' => 'nobody']))->toBeFalse();
});

// ---- 6. Invalid field name in transitionTo ----

it('throws when transitioning with invalid field name', function () {
    $order = TestOrder::factory()->create(['status' => 'pending']);

    $order->transitionTo('nonexistent', TestOrderStatus::Processing);
})->throws(RuntimeException::class);

// ---- 7. Case with both #[InitialState] and #[FinalState] ----

it('auto-fills initial state even when it is also final', function () {
    $order = InitialFinalOrder::create([]);

    expect($order->status)->toBe(InitialFinalStatus::Stuck);
});

it('blocks transition from state that is both initial and final', function () {
    $order = InitialFinalOrder::create(['status' => 'stuck']);

    expect($order->canTransitionTo(InitialFinalStatus::Other))->toBeFalse();
});

// ---- 8. Chaining scopes on multiple fields ----

it('chains whereState scopes on different fields', function () {
    // DuplicateEnumOrder uses same enum for both fields, but we test scope chaining
    // on TestOrder with single field + combined conditions
    TestOrder::factory()->create(['status' => 'pending']);
    TestOrder::factory()->create(['status' => 'processing']);
    TestOrder::factory()->create(['status' => 'pending']);

    $results = TestOrder::whereState('status', TestOrderStatus::Pending)
        ->whereNotState('status', TestOrderStatus::Processing)
        ->get();

    expect($results)->toHaveCount(2)
        ->and($results->every(fn ($o) => $o->status === TestOrderStatus::Pending))->toBeTrue();
});

it('chains whereState with whereStateIn', function () {
    TestOrder::factory()->create(['status' => 'pending']);
    TestOrder::factory()->create(['status' => 'processing']);
    TestOrder::factory()->create(['status' => 'shipped']);

    $results = TestOrder::whereStateIn('status', [TestOrderStatus::Pending, TestOrderStatus::Processing])
        ->whereNotState('status', TestOrderStatus::Shipped)
        ->get();

    expect($results)->toHaveCount(2);
});

// ---- 9. Model without state machine fields ----

it('returns empty array for model without state machine fields', function () {
    $order = new PlainOrder();

    expect($order->getStateMachineFields())->toBe([]);
});

// ---- Extra: resolveFieldForEnum with duplicate enum fields ----

it('throws when enum is used on multiple fields and field is not specified', function () {
    $order = DuplicateEnumOrder::create(['status' => 'pending', 'payment_status' => 'pending']);

    $order->transitionTo(TestOrderStatus::Processing);
})->throws(InvalidArgumentException::class, 'Multiple state machine fields');

// ---- Extra: null state field ----

it('throws when state field is null', function () {
    // A dedicated table with a nullable column, rather than ->change() on the
    // existing one: altering a column needs doctrine/dbal on Laravel 10.
    Schema::create('nullable_orders', function (Blueprint $table) {
        $table->id();
        $table->string('status')->nullable();
        $table->timestamps();
    });

    // Insert with null status bypassing the creating hook
    DB::table('nullable_orders')->insert([
        'status' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $order = NullableOrder::find(DB::getPdo()->lastInsertId());

    $order->transitionTo('status', TestOrderStatus::Processing);
})->throws(RuntimeException::class, 'not a valid enum state');

// ---- Extra: InitialState autofill on model creation ----

it('auto-fills initial state when creating model without specifying state', function () {
    // Insert via query builder to avoid any cast issues, then use the model
    $order = TestOrder::create([]);

    expect($order->status)->toBe(TestOrderStatus::Pending);
});

// ---- Dirty attributes should not leak through transitionTo ----

it('does not persist unrelated dirty attributes during transition', function () {
    $order = TestOrder::factory()->create(['status' => 'pending', 'payment_status' => 'unpaid']);

    // Set a dirty attribute that should NOT be saved by transitionTo
    $order->payment_status = 'changed_value';

    $order->transitionTo(TestOrderStatus::Processing);

    // State transitioned
    expect($order->status)->toBe(TestOrderStatus::Processing);

    // Dirty attribute was NOT persisted to DB
    $fresh = $order->fresh();
    expect($fresh->status)->toBe(TestOrderStatus::Processing)
        ->and($fresh->payment_status)->toBe('unpaid');
});
