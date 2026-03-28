# soylentgreenstudio/laravel-enum-states

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE.md)
[![Laravel](https://img.shields.io/badge/Laravel-10.x--12.x-red.svg)](https://laravel.com)
[![PHP](https://img.shields.io/badge/PHP-8.1%2B-purple.svg)](https://www.php.net)

**A state machine library for Laravel using native PHP 8.1 Backed Enums as the single source of truth.**

Declare states, transitions, guards, and hooks via PHP Attributes directly on your Enum — no separate state classes, no config files, no boilerplate.

## Table of Contents

- [Quick Start](#quick-start)
- [Features](#features)
- [Installation](#installation)
- [Architecture](#architecture)
- [Enum Definition](#enum-definition)
- [Model Setup](#model-setup)
- [Transitioning States](#transitioning-states)
- [Guards](#guards)
- [Hooks](#hooks)
- [Transition History](#transition-history)
- [Query Scopes](#query-scopes)
- [Events](#events)
- [Artisan Commands](#artisan-commands)
- [Async Hooks](#async-hooks)
- [Testing](#testing)
- [Comparison with Alternatives](#comparison-with-alternatives)
- [API Reference](#api-reference)
- [License](#license)

## Quick Start

```bash
composer require soylentgreenstudio/laravel-enum-states
php artisan vendor:publish --provider="SoylentGreenStudio\EnumStates\EnumStatesServiceProvider"
php artisan migrate
```

```php
// 1. Define your enum with attributes
enum OrderStatus: string
{
    #[InitialState]
    #[Transition(to: [self::Processing, self::Cancelled])]
    case Pending = 'pending';

    #[Transition(to: [self::Shipped, self::Cancelled])]
    case Processing = 'processing';

    #[FinalState]
    case Shipped = 'shipped';

    #[FinalState]
    case Cancelled = 'cancelled';
}

// 2. Add the trait to your model — that's it
class Order extends Model
{
    use HasStateMachines;

    protected $casts = [
        'status' => OrderStatus::class,
    ];
}

// 3. Transition states
$order->transitionTo(OrderStatus::Processing);
$order->transitionTo(OrderStatus::Shipped, ['tracking' => 'ABC123']);

// 4. Check if transition is allowed (never throws)
$order->canTransitionTo(OrderStatus::Cancelled); // bool

// 5. Query by state
Order::whereState('status', OrderStatus::Pending)->get();

// 6. View transition history
$order->stateHistory('status');
```

## Features

| Feature | Description |
|---------|-------------|
| **Enum-driven** | States and transitions declared via PHP Attributes on Backed Enums |
| **Zero config** | Trait auto-detects state machine fields from `$casts` — no registration needed |
| **Guards** | Control whether a transition is allowed via `TransitionGuard` contract |
| **Hooks** | Run logic before/after transition via `TransitionHook` contract |
| **Transition history** | Every transition recorded in `state_transitions` table with metadata |
| **Query scopes** | `whereState`, `whereNotState`, `whereStateIn` — filter models by state |
| **Events** | `TransitionStarted`, `TransitionCompleted`, `TransitionFailed` fired automatically |
| **Multiple state machines** | One model can have multiple state fields, each independent |
| **DB transactions** | Transitions are wrapped in `DB::transaction()` — hooks and state update are atomic |
| **Initial / Final states** | Mark states with `#[InitialState]` and `#[FinalState]` attributes |
| **Metadata** | Pass arbitrary data with each transition — stored in history as JSON |
| **Async hooks** | Dispatch hooks to queues via `AsyncTransitionHook` — fire-and-forget before, post-commit after |
| **Artisan commands** | `enum-states:graph`, `make:enum-state`, `make:transition-guard` for visualization and scaffolding |
| **Container resolution** | Guards and hooks are resolved via Laravel's service container — inject dependencies freely |

## Installation

### Requirements

- PHP 8.1+
- Laravel 10.x — 12.x

### Install

```bash
composer require soylentgreenstudio/laravel-enum-states
```

### Publish and run migration

```bash
php artisan vendor:publish --provider="SoylentGreenStudio\EnumStates\EnumStatesServiceProvider"
php artisan migrate
```

This creates the `state_transitions` table used for transition history.

## Architecture

### Transition lifecycle

```
$order->transitionTo(OrderStatus::Processing, $metadata)
  └─ StateMachineManager::transition()
      ├─ 1. Check current state is not #[FinalState]  → FinalStateException
      ├─ 2. Find matching #[Transition] attribute      → InvalidTransitionException
      ├─ 3. Resolve and run Guard via app()->make()    → InvalidTransitionException
      ├─ 4. Fire TransitionStarted event
      └─ 5. DB::transaction()
            ├─ Run `before` hook
            ├─ Update model field + save
            ├─ Write record to state_transitions table
            └─ Run `after` hook
      ├─ 6. Fire TransitionCompleted event
      └─ On exception: Fire TransitionFailed event, re-throw
```

### How auto-detection works

The `HasStateMachines` trait inspects the model's `$casts` array on first access:

1. For each cast that points to a `BackedEnum` class
2. Check if any case on that enum has `#[Transition]`, `#[InitialState]`, or `#[FinalState]` attributes
3. Register those fields as managed state machines

No manual field registration required.

### Database schema

The `state_transitions` table stores full transition history:

| Column | Type | Description |
|--------|------|-------------|
| `id` | bigint | Primary key |
| `model_type` | string | Morphable model class |
| `model_id` | bigint | Morphable model ID |
| `field` | string | Field name (e.g. `status`) |
| `from` | string | Previous state value |
| `to` | string | New state value |
| `metadata` | json, nullable | Arbitrary data passed with the transition |
| `transitioned_at` | timestamp | When the transition occurred |
| `created_at` | timestamp | Record creation time |

## Enum Definition

Define your states as a PHP 8.1 Backed Enum. Use attributes to declare the state machine behavior:

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

### Attribute reference

| Attribute | Target | Description |
|-----------|--------|-------------|
| `#[InitialState]` | Enum case | Marks the default/starting state |
| `#[FinalState]` | Enum case | Marks a terminal state — no transitions allowed from it |
| `#[Transition]` | Enum case (repeatable) | Declares allowed transitions from this state |

### Transition attribute parameters

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `to` | `array` | required | Array of enum cases this state can transition to |
| `guard` | `?string` | `null` | FQCN of a `TransitionGuard` implementation |
| `before` | `?string` | `null` | FQCN of a `TransitionHook` or `AsyncTransitionHook` — runs before persisting |
| `after` | `?string` | `null` | FQCN of a `TransitionHook` or `AsyncTransitionHook` — runs after persisting |

The `#[Transition]` attribute is repeatable — you can define multiple transition groups on a single case with different guards/hooks:

```php
#[Transition(to: [self::Approved], guard: ManagerApproval::class)]
#[Transition(to: [self::Rejected], guard: CanReject::class)]
case Pending = 'pending';
```

## Model Setup

Add the `HasStateMachines` trait and cast your state fields to the enum:

```php
use SoylentGreenStudio\EnumStates\Traits\HasStateMachines;

class Order extends Model
{
    use HasStateMachines;

    protected $casts = [
        'status'         => OrderStatus::class,
        'payment_status' => PaymentStatus::class,
    ];
}
```

That's it. The trait auto-detects which cast fields are Backed Enums with state machine attributes. No `getStateMachineFields()` method needed.

## Transitioning States

### Basic transition

```php
$order->transitionTo(OrderStatus::Processing);
```

### With metadata

Metadata is stored in the transition history record:

```php
$order->transitionTo(OrderStatus::Processing, [
    'reason'  => 'Payment confirmed',
    'user_id' => auth()->id(),
]);
```

### Explicit field name

When a model has multiple state machines, specify the field:

```php
$order->transitionTo('payment_status', PaymentStatus::Paid, $metadata);
```

### Check before transitioning

Returns `bool`, never throws:

```php
if ($order->canTransitionTo(OrderStatus::Shipped)) {
    $order->transitionTo(OrderStatus::Shipped);
}
```

### Exception handling

| Exception | When |
|-----------|------|
| `FinalStateException` | Transitioning from a state marked `#[FinalState]` |
| `InvalidTransitionException` | No `#[Transition]` allows the requested state change |
| `InvalidTransitionException` | Guard returned `false` |

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

Guards are resolved via the Laravel service container — you can inject any dependencies via the constructor.

If a guard returns `false`, `transitionTo()` throws `InvalidTransitionException`.

### Guard with dependency injection

```php
class HasSufficientBalance implements TransitionGuard
{
    public function __construct(
        private PaymentGateway $gateway,
    ) {}

    public function allow(Model $model, array $metadata): bool
    {
        return $this->gateway->getBalance($model->user_id) >= $model->total;
    }
}
```

## Hooks

Hooks run logic before or after a transition. Implement the `TransitionHook` contract:

```php
use SoylentGreenStudio\EnumStates\Contracts\TransitionHook;

class SendOrderConfirmation implements TransitionHook
{
    public function handle(Model $model, mixed $from, mixed $to, array $metadata): void
    {
        Mail::to($model->user)->send(new OrderConfirmed($model));
    }
}
```

### Before vs After

| Type | When it runs | On exception |
|------|-------------|--------------|
| `before` | Before model is saved, inside DB transaction | Transition is rolled back |
| `after` | After model is saved, inside same DB transaction | Transition is rolled back |

Both hooks receive the model, the `$from` and `$to` enum cases, and the metadata array.

### Hook with dependency injection

```php
class NotifySlack implements TransitionHook
{
    public function __construct(
        private SlackClient $slack,
    ) {}

    public function handle(Model $model, mixed $from, mixed $to, array $metadata): void
    {
        $this->slack->send("Order #{$model->id} changed from {$from->name} to {$to->name}");
    }
}
```

## Transition History

Every transition is recorded in the `state_transitions` table:

```php
// History for a specific field
$order->stateHistory('status');
// => Collection of StateTransition models

// History for all state machine fields
$order->stateHistory();
```

Each `StateTransition` record contains:

```php
$transition->from;              // 'pending'
$transition->to;                // 'processing'
$transition->field;             // 'status'
$transition->metadata;          // ['reason' => 'Payment confirmed'] or null
$transition->transitioned_at;   // Carbon instance
$transition->created_at;        // Carbon instance
```

## Query Scopes

The `HasStateMachines` trait adds query scopes for filtering models by state:

```php
// Exact match
Order::whereState('status', OrderStatus::Pending)->get();

// Exclude a state
Order::whereNotState('status', OrderStatus::Cancelled)->get();

// Match multiple states
Order::whereStateIn('status', [
    OrderStatus::Pending,
    OrderStatus::Processing,
])->get();
```

## Events

Three events are fired automatically during each transition:

| Event | When | Payload |
|-------|------|---------|
| `TransitionStarted` | Before DB transaction begins | `$model`, `$field`, `$from`, `$to`, `$metadata` |
| `TransitionCompleted` | After DB transaction commits | `$model`, `$field`, `$from`, `$to`, `$metadata` |
| `TransitionFailed` | On any exception (then re-thrown) | `$model`, `$field`, `$from`, `$to`, `$exception` |

### Listening to events

```php
// In EventServiceProvider or via Event::listen()
use SoylentGreenStudio\EnumStates\Events\TransitionCompleted;

Event::listen(TransitionCompleted::class, function (TransitionCompleted $event) {
    Log::info("Order #{$event->model->id}: {$event->field} changed", [
        'from' => $event->from->value,
        'to'   => $event->to->value,
        'meta' => $event->metadata,
    ]);
});
```

## Artisan Commands

### Visualize State Graph

```bash
php artisan enum-states:graph "App\Enums\OrderStatus"
```

Output:

```
OrderStatus State Graph
========================
[Initial] Pending
  → Processing (guard: HasItemsInStock)
  → Cancelled
Processing
  → Shipped (before: ValidateShippingAddress)
  → Cancelled
[Final] Shipped
[Final] Cancelled
```

Generate a Mermaid diagram for documentation:

```bash
php artisan enum-states:graph "App\Enums\OrderStatus" --format=mermaid
```

Output:

```
stateDiagram-v2
    [*] --> Pending
    Pending --> Processing : guard: HasItemsInStock
    Pending --> Cancelled
    Processing --> Shipped : before: ValidateShippingAddress
    Processing --> Cancelled
    Shipped --> [*]
    Cancelled --> [*]
```

### Generate Enum State

Scaffold a new enum with state machine attributes:

```bash
php artisan make:enum-state OrderStatus
```

Creates `app/Enums/OrderStatus.php` with `#[InitialState]`, `#[FinalState]`, and `#[Transition]` boilerplate.

### Generate Transition Guard

Scaffold a new guard class:

```bash
php artisan make:transition-guard HasItemsInStock
```

Creates `app/Guards/HasItemsInStock.php` implementing `TransitionGuard`.

## Async Hooks

For hooks that don't need to block the transition, implement the `AsyncTransitionHook` contract. Async hooks are dispatched as queued jobs instead of running synchronously.

```php
use SoylentGreenStudio\EnumStates\Contracts\AsyncTransitionHook;

class SendShipmentNotification implements AsyncTransitionHook
{
    public function handle(Model $model, mixed $from, mixed $to, array $metadata): void
    {
        Mail::to($model->user)->send(new OrderShipped($model));
    }

    public function queue(): ?string
    {
        return 'notifications'; // or null for default queue
    }
}
```

Use it the same way as synchronous hooks in the `#[Transition]` attribute:

```php
#[Transition(
    to: [self::Shipped],
    before: ValidateShippingAddress::class,    // sync — runs inside transaction
    after: SendShipmentNotification::class,     // async — dispatched to queue
)]
case Processing = 'processing';
```

### Behavior

| Hook type | `TransitionHook` (sync) | `AsyncTransitionHook` (async) |
|-----------|------------------------|-------------------------------|
| `before` | Runs inside DB transaction, blocks transition | Fire-and-forget: dispatched to queue, does not block |
| `after` | Runs inside DB transaction, can roll back | Dispatched after successful commit |

- **Sync hooks** continue to work exactly as before (full backward compatibility)
- **Async before hooks** are dispatched to the queue at the before-hook point but do not block the transition
- **Async after hooks** are dispatched only after the DB transaction commits successfully
- The internal `ProcessTransitionHook` job wraps the async hook execution

### AsyncTransitionHook contract

```php
interface AsyncTransitionHook
{
    public function handle(Model $model, mixed $from, mixed $to, array $metadata): void;
    public function queue(): ?string; // queue name or null for default
}
```

## Testing

```bash
composer test
```

The package uses [Pest](https://pestphp.com/) + [Orchestra Testbench](https://github.com/orchestral/testbench).

### Test coverage

| Suite | Covers |
|-------|--------|
| `TransitionTest` | Happy path transitions, disallowed transitions, final state enforcement, auto-detection |
| `GuardTest` | Guard blocking, guard allowing, `canTransitionTo` with guards |
| `HookTest` | Before/after hook execution order, rollback on hook exception |
| `HistoryTest` | History recording, metadata storage, multi-field independence |
| `ScopeTest` | `whereState`, `whereNotState`, `whereStateIn` |
| `EventTest` | `TransitionStarted`, `TransitionCompleted`, metadata in events |
| `AsyncHookTest` | Async hook dispatch, named queues, post-commit dispatch, backward compatibility |
| `CommandTest` | Graph command (text/mermaid), generator commands, error handling |

## Comparison with Alternatives

### vs. spatie/laravel-model-states

| Aspect | spatie/laravel-model-states | laravel-enum-states |
|--------|---------------------------|---------------------|
| **State definition** | Separate PHP classes per state | Native PHP Backed Enum cases |
| **Transitions** | Separate `Transition` classes or `$transitions` array | `#[Transition]` attributes on enum cases |
| **Configuration** | `$states` config in model + state classes | `$casts` only — auto-detected from enum attributes |
| **Guards** | Inside transition classes or `canTransitionTo()` method | Dedicated `TransitionGuard` contract, container-resolved |
| **Hooks** | Transition class `handle()` + events | `before`/`after` hooks on `#[Transition]` attribute |
| **History** | Via separate package or custom | Built-in `state_transitions` table |
| **Multiple fields** | Supported, requires explicit config | Supported, auto-detected from `$casts` |
| **Boilerplate** | 1 class per state + 1 class per transition | 1 enum + attributes only |
| **PHP version** | PHP 8.0+ | PHP 8.1+ (requires Backed Enums) |

**Advantages of laravel-enum-states:**
- Zero boilerplate — no separate state/transition classes
- Everything declared in one place — the Enum itself
- Native PHP Enums for type safety — IDE autocomplete, exhaustive match
- Built-in transition history with metadata
- Guards and hooks resolved via service container

**Disadvantages compared to spatie:**
- Requires PHP 8.1+ (Backed Enums)
- No custom transition logic classes — hooks are simpler but less flexible
- Smaller community and ecosystem
- No default state configuration on the model

### vs. asantibanez/laravel-eloquent-state-machines

| Aspect | asantibanez | laravel-enum-states |
|--------|------------|---------------------|
| **State definition** | `StateMachine` class with `$initialState` and `$transitions` | Backed Enum with `#[Transition]` attributes |
| **Configuration** | `$stateMachines` array in model | Auto-detected from `$casts` |
| **History** | Built-in | Built-in |
| **Guards** | `beforeTransitionHook()` in StateMachine class | Dedicated `TransitionGuard` contract |
| **Type safety** | String-based states | Enum-based — IDE autocomplete, type checking |

### Summary: When to use laravel-enum-states

**Choose laravel-enum-states when:**
- You want states defined as native PHP Enums with full type safety
- You prefer zero-config auto-detection over manual registration
- You want guards and hooks as separate, testable, injectable classes
- You need built-in transition history with metadata
- You want everything declared in one place — the Enum

**Choose alternatives when:**
- You need complex transition logic in dedicated classes
- You need PHP 8.0 compatibility
- You need a larger community and ecosystem
- You prefer explicit configuration over convention

## API Reference

### Attributes

| Attribute | Target | Parameters |
|-----------|--------|------------|
| `#[InitialState]` | Enum case | — |
| `#[FinalState]` | Enum case | — |
| `#[Transition]` | Enum case (repeatable) | `to: array`, `?guard: string`, `?before: string`, `?after: string` |

### Contracts

| Interface | Method |
|-----------|--------|
| `TransitionGuard` | `allow(Model $model, array $metadata): bool` |
| `TransitionHook` | `handle(Model $model, mixed $from, mixed $to, array $metadata): void` |
| `AsyncTransitionHook` | `handle(...)` + `queue(): ?string` — dispatched as queued job |

### HasStateMachines trait

| Method | Returns | Description |
|--------|---------|-------------|
| `transitionTo($state, $metadata)` | `void` | Transition to a new state |
| `transitionTo($field, $state, $metadata)` | `void` | Transition with explicit field name |
| `canTransitionTo($state, $metadata)` | `bool` | Check if transition is allowed |
| `stateHistory($field)` | `Collection` | Get transition history for a field |
| `stateHistory()` | `Collection` | Get transition history for all fields |
| `getStateMachineFields()` | `array` | Get detected state machine fields |

### Query scopes

| Scope | Signature |
|-------|-----------|
| `whereState` | `whereState(string $field, BackedEnum $state)` |
| `whereNotState` | `whereNotState(string $field, BackedEnum $state)` |
| `whereStateIn` | `whereStateIn(string $field, array $states)` |

### Events

| Event | Properties |
|-------|------------|
| `TransitionStarted` | `Model $model`, `string $field`, `mixed $from`, `mixed $to`, `array $metadata` |
| `TransitionCompleted` | `Model $model`, `string $field`, `mixed $from`, `mixed $to`, `array $metadata` |
| `TransitionFailed` | `Model $model`, `string $field`, `mixed $from`, `mixed $to`, `Throwable $exception` |

### Exceptions

| Exception | When thrown |
|-----------|------------|
| `FinalStateException` | Attempting to transition from a `#[FinalState]` |
| `InvalidTransitionException` | No matching `#[Transition]` found, or guard returned `false` |

### Models

| Class | Table | Description |
|-------|-------|-------------|
| `StateTransition` | `state_transitions` | Polymorphic history record with `from`, `to`, `field`, `metadata`, `transitioned_at` |

## License

MIT License. See [LICENSE.md](LICENSE.md) for details.
