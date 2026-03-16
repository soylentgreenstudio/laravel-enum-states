# Laravel Enum States

A state machine library for Laravel that uses native PHP 8.1 Backed Enums as the single source of truth for states and transitions. Everything is declared via PHP Attributes directly on the Enum — no separate state classes, no config files, no boilerplate.

## Requirements

- PHP 8.1+
- Laravel 10, 11, or 12

## Installation

```bash
composer require soylentgreenstudio/laravel-enum-states
```

Publish and run the migration:

```bash
php artisan vendor:publish --provider="SoylentGreenStudio\EnumStates\EnumStatesServiceProvider"
php artisan migrate
```

## Quick Start

### 1. Define your enum with attributes

```php
use SoylentGreenStudio\EnumStates\Attributes\InitialState;
use SoylentGreenStudio\EnumStates\Attributes\FinalState;
use SoylentGreenStudio\EnumStates\Attributes\Transition;

enum OrderStatus: string
{
    #[InitialState]
    #[Transition(
        to: [self::Processing, self::Cancelled],
        guard: HasItemsInStock::class,
        after: SendOrderConfirmation::class,
    )]
    case Pending = 'pending';

    #[Transition(
        to: [self::Shipped, self::Cancelled],
        before: ValidateShippingAddress::class,
    )]
    case Processing = 'processing';

    #[FinalState]
    case Shipped = 'shipped';

    #[FinalState]
    case Cancelled = 'cancelled';
}
```

### 2. Add the trait to your model

```php
use SoylentGreenStudio\EnumStates\Traits\HasStateMachines;

class Order extends Model
{
    use HasStateMachines;

    protected $casts = [
        'status' => OrderStatus::class,
    ];
}
```

That's it. The trait auto-detects which cast fields are Backed Enums with state machine attributes.

### 3. Transition states

```php
// Simple transition
$order->transitionTo(OrderStatus::Processing);

// With metadata (stored in history)
$order->transitionTo(OrderStatus::Processing, [
    'reason'  => 'Payment confirmed',
    'user_id' => auth()->id(),
]);

// Check without throwing
$order->canTransitionTo(OrderStatus::Cancelled); // bool

// Explicit field (for models with multiple state machines)
$order->transitionTo('payment_status', PaymentStatus::Paid, $meta);
```

## Guards

Guards control whether a transition is allowed. Implement the `TransitionGuard` contract:

```php
use SoylentGreenStudio\EnumStates\Contracts\TransitionGuard;

class HasItemsInStock implements TransitionGuard
{
    public function allow(Model $model, array $metadata): bool
    {
        return $model->items()->where('in_stock', true)->exists();
    }
}
```

Guards are resolved via the Laravel service container, so you can inject dependencies.

If a guard returns `false`, `transitionTo()` throws `InvalidTransitionException`.

## Hooks

Hooks run logic before or after a transition. Implement the `TransitionHook` contract:

```php
use SoylentGreenStudio\EnumStates\Contracts\TransitionHook;

class SendOrderConfirmation implements TransitionHook
{
    public function handle(Model $model, $from, $to, array $metadata): void
    {
        Mail::to($model->user)->send(new OrderConfirmed($model));
    }
}
```

- **`before`** runs before the model is persisted. If it throws, the transition is rolled back.
- **`after`** runs after persisting, inside the same DB transaction.

## Transition History

Every transition is recorded in the `state_transitions` table:

```php
// History for a specific field
$order->stateHistory('status');

// History for all state machine fields
$order->stateHistory();
```

Each record contains: `from`, `to`, `field`, `metadata` (JSON), `transitioned_at`.

## Query Scopes

```php
Order::whereState('status', OrderStatus::Pending)->get();
Order::whereNotState('status', OrderStatus::Cancelled)->get();
Order::whereStateIn('status', [OrderStatus::Pending, OrderStatus::Processing])->get();
```

## Events

Three events are fired automatically during transitions:

| Event | When |
|---|---|
| `TransitionStarted` | Before the DB transaction begins |
| `TransitionCompleted` | After the DB transaction commits |
| `TransitionFailed` | When any exception occurs (then re-thrown) |

All events receive: `$model`, `$field`, `$from`, `$to`, `$metadata`.

## Testing

```bash
composer test
```

## License

MIT
