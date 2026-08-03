<?php

declare(strict_types=1);

use SoylentGreenStudio\EnumStates\Attributes\FinalState;
use SoylentGreenStudio\EnumStates\Attributes\InitialState;
use SoylentGreenStudio\EnumStates\Attributes\Transition;
use SoylentGreenStudio\EnumStates\Attributes\TransitionFrom;
use SoylentGreenStudio\EnumStates\Contracts\TransitionGuard;
use SoylentGreenStudio\EnumStates\Traits\HasStateMachines;
use Illuminate\Database\Eloquent\Model;

// ---- Guards ----

class AvailAllowGuard implements TransitionGuard
{
    public function allow(Model $model, array $metadata): bool
    {
        return true;
    }
}

class AvailDenyGuard implements TransitionGuard
{
    public function allow(Model $model, array $metadata): bool
    {
        return false;
    }
}

class AvailMetadataGuard implements TransitionGuard
{
    public function allow(Model $model, array $metadata): bool
    {
        return ($metadata['approved'] ?? false) === true;
    }
}

// ---- Enums ----

enum AvailStatus: string
{
    #[InitialState]
    #[Transition(to: [self::Processing])]
    #[Transition(to: [self::Approved], guard: AvailAllowGuard::class)]
    #[Transition(to: [self::Rejected], guard: AvailDenyGuard::class)]
    case Pending = 'pending';

    #[Transition(to: [self::Shipped])]
    case Processing = 'processing';

    case Approved = 'approved';
    case Rejected = 'rejected';

    #[FinalState]
    case Shipped = 'shipped';
}

enum AvailDuplicateStatus: string
{
    // Done is reachable twice: blocked via the first attribute, allowed via the second
    #[InitialState]
    #[Transition(to: [self::Done], guard: AvailDenyGuard::class)]
    #[Transition(to: [self::Done], guard: AvailAllowGuard::class)]
    case Pending = 'pending';

    #[FinalState]
    case Done = 'done';
}

enum AvailMetadataStatus: string
{
    #[InitialState]
    #[Transition(to: [self::Approved], guard: AvailMetadataGuard::class)]
    case Pending = 'pending';

    case Approved = 'approved';
}

enum AvailWildcardStatus: string
{
    #[InitialState]
    #[Transition(to: [self::Active])]
    case Pending = 'pending';

    case Active = 'active';

    #[FinalState]
    #[TransitionFrom(from: '*')]
    case Cancelled = 'cancelled';
}

enum AvailPaymentStatus: string
{
    #[InitialState]
    #[Transition(to: [self::Paid])]
    case Unpaid = 'unpaid';

    #[FinalState]
    case Paid = 'paid';
}

// ---- Models ----

class AvailOrder extends Model
{
    use HasStateMachines;

    protected $table = 'orders';
    protected $guarded = [];
    protected $casts = ['status' => AvailStatus::class];
}

class AvailMultiFieldOrder extends Model
{
    use HasStateMachines;

    protected $table = 'orders';
    protected $guarded = [];
    protected $casts = [
        'status' => AvailWildcardStatus::class,
        'payment_status' => AvailPaymentStatus::class,
    ];
}

class AvailPlainOrder extends Model
{
    use HasStateMachines;

    protected $table = 'orders';
    protected $guarded = [];
}

// ---- Tests ----

it('lists only targets whose guards pass', function () {
    $order = AvailOrder::create(['status' => 'pending']);

    $allowed = array_map(fn ($s) => $s->value, $order->allowedTransitions());

    expect($allowed)->toBe(['processing', 'approved']);
});

it('returns an empty list for a final state', function () {
    $order = AvailOrder::create(['status' => 'shipped']);

    expect($order->allowedTransitions())->toBe([]);
});

it('returns enum instances, not strings', function () {
    $order = AvailOrder::create(['status' => 'processing']);

    expect($order->allowedTransitions())->toBe([AvailStatus::Shipped]);
});

it('agrees with canTransitionTo for every case', function () {
    $order = AvailOrder::create(['status' => 'pending']);

    $allowed = $order->allowedTransitions();

    foreach (AvailStatus::cases() as $case) {
        expect($order->canTransitionTo($case))->toBe(in_array($case, $allowed, true));
    }
});

it('reports a target reachable via several attributes only once', function () {
    $order = AvailOrder::create(['status' => 'pending']);
    $order->mergeCasts(['status' => AvailDuplicateStatus::class]);
    $order->status = AvailDuplicateStatus::Pending;

    expect($order->allowedTransitions())->toBe([AvailDuplicateStatus::Done]);
});

it('passes metadata through to guards', function () {
    $order = AvailOrder::create(['status' => 'pending']);
    $order->mergeCasts(['status' => AvailMetadataStatus::class]);
    $order->status = AvailMetadataStatus::Pending;

    expect($order->allowedTransitions())->toBe([])
        ->and($order->allowedTransitions(null, ['approved' => true]))
        ->toBe([AvailMetadataStatus::Approved]);
});

it('includes reverse edges declared with TransitionFrom', function () {
    $order = AvailMultiFieldOrder::create(['status' => 'active', 'payment_status' => 'unpaid']);

    expect($order->allowedTransitions('status'))->toBe([AvailWildcardStatus::Cancelled]);
});

it('resolves each field independently on a multi-field model', function () {
    $order = AvailMultiFieldOrder::create(['status' => 'pending', 'payment_status' => 'unpaid']);

    expect($order->allowedTransitions('status'))
        ->toBe([AvailWildcardStatus::Active, AvailWildcardStatus::Cancelled])
        ->and($order->allowedTransitions('payment_status'))
        ->toBe([AvailPaymentStatus::Paid]);
});

it('throws when the field is ambiguous and none was given', function () {
    $order = AvailMultiFieldOrder::create(['status' => 'pending', 'payment_status' => 'unpaid']);

    $order->allowedTransitions();
})->throws(InvalidArgumentException::class, 'multiple state machine fields');

it('throws when the model has no state machine fields', function () {
    $order = AvailPlainOrder::create(['status' => 'pending']);

    $order->allowedTransitions();
})->throws(InvalidArgumentException::class, 'no state machine fields');
