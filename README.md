# soylentgreenstudio/laravel-enum-states

[![Tests](https://github.com/soylentgreenstudio/laravel-enum-states/actions/workflows/tests.yml/badge.svg)](https://github.com/soylentgreenstudio/laravel-enum-states/actions/workflows/tests.yml)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE.md)
[![Laravel](https://img.shields.io/badge/Laravel-10.x--13.x-red.svg)](https://laravel.com)
[![PHP](https://img.shields.io/badge/PHP-8.2%2B-purple.svg)](https://www.php.net)

**A state machine library for Laravel using native PHP Backed Enums as the single source of truth.**

Declare states, transitions, guards, and hooks via PHP Attributes directly on your Enum — no separate state classes, no boilerplate.

## Table of Contents

- [Quick Start](#quick-start)
- [Features](#features)
- [Installation](#installation)
- [Configuration](#configuration)
- [Architecture](#architecture)
- [Enum Definition](#enum-definition)
- [Model Setup](#model-setup)
- [Transitioning States](#transitioning-states)
- [Reverse / Wildcard Transitions](#reverse--wildcard-transitions)
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
php artisan vendor:publish --tag=enum-states-migrations
php artisan migrate
```

```php
// 1. Define your enum with attributes
enum OrderStatus: string
{
    #[InitialState]
    #[Transition(to: [self::Processing])]
    case Pending = 'pending';

    #[Transition(to: [self::Shipped])]
    case Processing = 'processing';

    #[FinalState]
    case Shipped = 'shipped';

    // Reachable from any non-final case — no need to list it on each source
    #[FinalState]
    #[TransitionFrom(from: '*')]
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

// 4b. Or list every state reachable right now, guards applied
$order->allowedTransitions(); // [OrderStatus::Shipped, OrderStatus::Cancelled]

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
| **Reverse / wildcard transitions** | Declare inbound edges on the target state with `#[TransitionFrom(from: '*')]` or an explicit case list |
| **Available actions** | `allowedTransitions()` returns every state reachable from the current one, with guards applied |
| **Guards** | Control whether a transition is allowed via `TransitionGuard` contract |
| **Multiple guards (AND)** | Pass `guard: [GuardA::class, GuardB::class]` — every guard in the array must pass |
| **Hooks** | Run logic before/after transition via `TransitionHook` contract |
| **Transition history** | Every transition recorded with metadata in a configurable history table, eager-loadable via `stateTransitions` |
| **Configurable table name** | Override the history table via `config/enum-states.php` or the `ENUM_STATES_TABLE` env var |
| **Query scopes** | `whereState`, `whereNotState`, `whereStateIn` — filter models by state |
| **Events** | `TransitionStarted`, `TransitionCompleted`, `TransitionFailed` fired automatically |
| **Multiple state machines** | One model can have multiple state fields, each independent |
| **DB transactions** | Transitions wrapped in `DB::transaction()` with pessimistic locking — hooks and state update are atomic |
| **Initial / Final states** | Mark states with `#[InitialState]` and `#[FinalState]` attributes |
| **Metadata** | Pass arbitrary data with each transition — stored in history as JSON |
| **Async hooks** | Dispatch hooks to queues via `AsyncTransitionHook` — queued only after a successful commit |
| **Artisan commands** | `enum-states:graph`, `make:enum-state`, `make:transition-guard` for visualization and scaffolding |
| **Container resolution** | Guards and hooks are resolved via Laravel's service container — inject dependencies freely |

## Installation

### Requirements

- PHP 8.2+ (PHP 8.3+ for Laravel 13)
- Laravel 10.x — 13.x

> **Laravel 10 and 11 are end-of-life.** Every released version carries security
> advisories that will never be patched, and Composer 2.10+ refuses to install
> them by default. The package still declares support for them, but a fresh
> install on those versions requires overriding Composer's advisory policy.
> Laravel 12 and 13 install normally.

### Install

```bash
composer require soylentgreenstudio/laravel-enum-states
```

### Publish the migration

```bash
php artisan vendor:publish --tag=enum-states-migrations
php artisan migrate
```

This copies `create_state_transitions_table.php.stub` to your application's `database/migrations/` with a fresh timestamp and creates the history table (default name: `state_transitions`).

### Publish the config (optional)

Only needed if you want to rename the history table or override defaults:

```bash
php artisan vendor:publish --tag=enum-states-config
```

This creates `config/enum-states.php` in your application.

## Configuration

The package ships with a single config file, `config/enum-states.php`:

```php
return [
    'table' => env('ENUM_STATES_TABLE', 'state_transitions'),
];
```

### Customizing the history table name

If `state_transitions` conflicts with an existing table or doesn't fit your naming convention, override it via `.env`:

```bash
ENUM_STATES_TABLE=audit_state_transitions
```

Or publish the config and edit directly:

```php
// config/enum-states.php
'table' => 'audit_state_transitions',
```

**Important:** set this value **before** running `php artisan migrate` — the migration reads the config value at runtime, and the `StateTransition` model resolves its table name on construction.

## Architecture

### Transition lifecycle

```
$order->transitionTo(OrderStatus::Processing, $metadata)
  └─ StateMachineManager::transition()
      ├─ 1. Check current state is not #[FinalState]   → FinalStateException
      ├─ 2. Find a matching #[Transition] edge          → InvalidTransitionException
      ├─ 3. Resolve guard(s) and verify all allow       → InvalidTransitionException
      ├─ 4. Fire TransitionStarted event
      └─ 5. DB::transaction() with lockForUpdate()
            ├─ Re-read and re-validate under the lock
            ├─ Run `before` hook (sync; async collected for post-commit)
            ├─ Update model field + save
            ├─ Write record to history table
            └─ Run `after` hook (sync; async collected for post-commit)
      ├─ 6. Dispatch any async hooks (post-commit, before-hooks first)
      ├─ 7. Fire TransitionCompleted event
      └─ On exception: Fire TransitionFailed event, re-throw
```

Sync hooks receive the freshly-read, locked model instance — the same object that
gets saved — so attribute changes made in a `before` hook are persisted together
with the state change. Async hooks are queued only after a successful commit, so a
rolled-back transition never leaves a job behind.

### How auto-detection works

The `HasStateMachines` trait inspects the model's `$casts` array on first access:

1. For each cast that points to a `BackedEnum` class
2. Check if any case on that enum has `#[Transition]`, `#[TransitionFrom]`, `#[InitialState]`, or `#[FinalState]` attributes
3. Register those fields as managed state machines

No manual field registration required.

### Database schema

The history table (default name `state_transitions`, override via `config('enum-states.table')`) stores full transition history:

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

Define your states as a PHP Backed Enum. Use attributes to declare the state machine behavior:

```php
use SoylentGreenStudio\EnumStates\Attributes\InitialState;
use SoylentGreenStudio\EnumStates\Attributes\FinalState;
use SoylentGreenStudio\EnumStates\Attributes\Transition;
use SoylentGreenStudio\EnumStates\Attributes\TransitionFrom;

enum OrderStatus: string
{
    #[InitialState]
    #[Transition(
        to: [self::Processing],
        guard: HasItemsInStock::class,
        after: SendOrderConfirmation::class,
    )]
    case Pending = 'pending';

    #[Transition(
        to: [self::Shipped],
        before: ValidateShippingAddress::class,
    )]
    case Processing = 'processing';

    #[FinalState]
    case Shipped = 'shipped';

    // Reverse-declared: reachable from any non-final case
    #[FinalState]
    #[TransitionFrom(from: '*')]
    case Cancelled = 'cancelled';
}
```

### Attribute reference

| Attribute | Target | Description |
|-----------|--------|-------------|
| `#[InitialState]` | Enum case | Marks the default/starting state |
| `#[FinalState]` | Enum case | Marks a terminal state — no transitions allowed from it |
| `#[Transition]` | Enum case (repeatable) | Declares outbound transitions from this state |
| `#[TransitionFrom]` | Enum case (repeatable) | Declares inbound transitions to this state from the given sources |

### Transition attribute parameters

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `to` | `array` | required | Array of enum cases this state can transition to |
| `guard` | `string\|array\|null` | `null` | FQCN of a `TransitionGuard`, or an array of FQCNs (AND-combined) |
| `before` | `?string` | `null` | FQCN of a `TransitionHook` or `AsyncTransitionHook` — runs before persisting |
| `after` | `?string` | `null` | FQCN of a `TransitionHook` or `AsyncTransitionHook` — runs after persisting |

The `#[Transition]` attribute is repeatable — you can stack multiple transitions on one case (OR semantics across attributes):

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

### Listing available transitions

`allowedTransitions()` returns every state reachable from the current one, with guards
evaluated. This is what you want when rendering action buttons or exposing available
actions in an API response:

```php
$order->allowedTransitions();
// => [OrderStatus::Processing, OrderStatus::Cancelled]
```

Each distinct target is guard-checked exactly once, no matter how many
`#[Transition]` / `#[TransitionFrom]` attributes point at it.

Pass metadata when your guards depend on it — it is forwarded unchanged:

```php
$order->allowedTransitions(metadata: ['user_id' => auth()->id()]);
```

On a model with several state machines, name the field:

```php
$order->allowedTransitions('payment_status');
```

Omitting the field on a multi-field model throws `InvalidArgumentException` — there is
no implicit "first field" behaviour. Returns an empty array when the current state is
`#[FinalState]` or the field holds no valid enum value.

Rendering the available actions in Blade:

```blade
@foreach ($order->allowedTransitions() as $state)
    <button name="to" value="{{ $state->value }}">{{ $state->name }}</button>
@endforeach
```

### Exception handling

| Exception | When |
|-----------|------|
| `FinalStateException` | Transitioning from a state marked `#[FinalState]` |
| `InvalidTransitionException` | No `#[Transition]`/`#[TransitionFrom]` allows the requested state change |
| `InvalidTransitionException` | All guards for the matching transitions returned `false` — message lists the guard class names |

## Reverse / Wildcard Transitions

Sometimes a state is reachable from many source states. Rather than duplicate `#[Transition(to: [self::Cancelled])]` on every source case, declare the inbound direction on the **target** case with `#[TransitionFrom]`.

### Wildcard: reachable from any non-final state

Use the `'*'` sentinel to make the target reachable from every non-final case (excluding the target itself and any `#[FinalState]` case):

```php
enum OrderStatus: string
{
    #[InitialState]
    #[Transition(to: [self::Processing])]
    case Pending = 'pending';

    #[Transition(to: [self::Shipped])]
    case Processing = 'processing';

    #[FinalState]
    case Shipped = 'shipped';

    // Cancelled is reachable from Pending and Processing —
    // without touching those cases' own attribute lists.
    #[FinalState]
    #[TransitionFrom(from: '*')]
    case Cancelled = 'cancelled';
}
```

### Explicit source list

Pass an array of enum cases (of the same enum) to enumerate allowed sources:

```php
enum DocumentStatus: string
{
    #[InitialState]
    case Draft = 'draft';

    case Review = 'review';
    case Approved = 'approved';

    // Archived from Draft or Review — but NOT from Approved
    #[FinalState]
    #[TransitionFrom(from: [self::Draft, self::Review])]
    case Archived = 'archived';
}
```

### With guards and hooks

`#[TransitionFrom]` accepts the same `guard`, `before`, and `after` parameters as `#[Transition]`:

```php
#[FinalState]
#[TransitionFrom(
    from: '*',
    guard: [IsAdmin::class, HasCloseReason::class],
    after: NotifyClosureWebhook::class,
)]
case Closed = 'closed';
```

### Wildcard semantics

- `from: '*'` expands to every non-final case of the enum **except the target itself**. No self-loop is created, and final states are never included as sources.
- The expansion is resolved once per enum class and cached — there is no runtime overhead compared to hand-written `#[Transition]` attributes.
- Reverse edges are visible in `enum-states:graph` output just like forward transitions.

### Mixing forward and reverse on the same edge

Forward `#[Transition]` on a source case and reverse `#[TransitionFrom]` on the target case may cover the same edge. Both contribute separate `Transition` objects to the source case; OR semantics between them means the transition is permitted if **either** attribute allows it:

```php
enum Status: string
{
    #[InitialState]
    #[Transition(to: [self::Done], guard: FastPathGuard::class)]
    case Pending = 'pending';

    #[TransitionFrom(from: [self::Pending], guard: FallbackGuard::class)]
    case Done = 'done';
}
```

### TransitionFrom attribute parameters

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `from` | `string\|array` | required | `'*'` for all non-final cases (excluding the target), or an array of BackedEnum cases of the same enum |
| `guard` | `string\|array\|null` | `null` | Single guard or AND-combined array |
| `before` | `?string` | `null` | Before-hook FQCN |
| `after` | `?string` | `null` | After-hook FQCN |

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

### Multiple guards per transition (AND)

Pass an array of guard classes to require **all** of them to return `true`:

```php
#[Transition(
    to: [self::Approved],
    guard: [IsAdmin::class, HasApprovalPermission::class, BudgetAvailable::class],
)]
case Pending = 'pending';
```

Semantics:
- Every guard in the array must return `true` for the transition to be allowed.
- Guards are evaluated in array order. The first `false` short-circuits the rest.
- On failure, `InvalidTransitionException` lists every guard class name that was checked.

A single string guard continues to work unchanged — passing `guard: IsAdmin::class` is equivalent to `guard: [IsAdmin::class]`.

### AND vs OR

| Goal | Syntax |
|------|--------|
| **All** guards must pass (AND) | One `#[Transition]` with `guard: [A::class, B::class]` |
| **Any** guard may pass (OR) | Multiple stacked `#[Transition]` attributes with different guards |

Example — admin can approve directly, or a manager with budget approval can approve:

```php
#[Transition(to: [self::Approved], guard: IsAdmin::class)]
#[Transition(to: [self::Approved], guard: [IsManager::class, BudgetAvailable::class])]
case Pending = 'pending';
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

### Which model instance a hook receives

Hooks are handed the freshly-read instance that holds the row lock — the same object the
transition saves. Attribute changes made in a `before` hook are therefore committed
alongside the state change, with no extra `save()`:

```php
class StampShippedAt implements TransitionHook
{
    public function handle(Model $model, mixed $from, mixed $to, array $metadata): void
    {
        $model->shipped_at = now(); // persisted with the transition
    }
}
```

An `after` hook runs once the model is already saved, so changes made there need their
own `save()` call (still inside the transaction, so they roll back together).

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

Every transition is recorded in the history table (default `state_transitions`, configurable):

```php
// History for a specific field
$order->stateHistory('status');
// => Collection of StateTransition models

// History for all state machine fields
$order->stateHistory();
```

### Eager loading

History is also exposed as a `morphMany` relation, so it can be eager loaded instead of
firing one query per model:

```php
$orders = Order::with('stateTransitions')->get();

foreach ($orders as $order) {
    $order->stateHistory('status'); // no extra queries — filtered in memory
}
```

`stateHistory()` reads from the loaded relation when present and falls back to a query
otherwise, so existing code keeps working unchanged.

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

When an array of guards is used, they are joined with `+` in the output (e.g. `guard: IsAdmin+HasBudget`) to signal AND-combination.

Virtual edges contributed by `#[TransitionFrom]` are rendered alongside forward transitions — no special flag needed.

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
| `before` | Runs inside DB transaction, blocks transition | Queued after commit, does not block |
| `after` | Runs inside DB transaction, can roll back | Queued after commit |

- **Sync hooks** continue to work exactly as before (full backward compatibility)
- **Async hooks never block the transition** — they cannot veto it and cannot roll it back
- **Async hooks are dispatched only after the DB transaction commits successfully.** A transition that gets rolled back — by a guard re-checked under the lock, a throwing sync hook, or a deleted model — leaves nothing on the queue
- `before` and `after` only determine dispatch order for async hooks (before-hooks are pushed first); both run outside the transaction, so neither observes the pre-transition state
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
| `GuardTest` | Guard blocking, guard allowing, `canTransitionTo` with guards, double-guard prevention |
| `MultiGuardTest` | AND-combined guard arrays, short-circuit on first false, exception message content, backward compat with single string guard |
| `WildcardTransitionTest` | `#[TransitionFrom]` wildcard + explicit list, final-state and self-loop exclusion, merged forward/reverse edges, guards on reverse, graph rendering |
| `ConfigTableTest` | Default table name, config override at construction, migration against default name |
| `HookTest` | Before/after hook execution order, rollback on hook exception, before-hook attribute changes persisted |
| `AllowedTransitionsTest` | `allowedTransitions()` guard filtering, target de-duplication, final states, reverse edges, field resolution |
| `HistoryTest` | History recording, metadata storage, multi-field independence, eager loading via `stateTransitions` |
| `ScopeTest` | `whereState`, `whereNotState`, `whereStateIn` |
| `EventTest` | `TransitionStarted`, `TransitionCompleted`, metadata in events |
| `AsyncHookTest` | Async hook dispatch, named queues, post-commit dispatch and ordering, no dispatch on rollback, backward compatibility |
| `CommandTest` | Graph command (text/mermaid), generator commands, error handling |
| `EdgeCaseTest` | Multiple `#[Transition]` OR-semantics, duplicate enum on fields, initial+final on same case, plain model |
| `ValidationTest` | Guards/hooks not implementing contracts, reflection cache invalidation, descriptive error messages |

## Comparison with Alternatives

### vs. spatie/laravel-model-states

| Aspect | spatie/laravel-model-states | laravel-enum-states |
|--------|---------------------------|---------------------|
| **State definition** | Separate PHP classes per state | Native PHP Backed Enum cases |
| **Transitions** | Separate `Transition` classes or `$transitions` array | `#[Transition]` / `#[TransitionFrom]` attributes on enum cases |
| **Configuration** | `$states` config in model + state classes | `$casts` only — auto-detected from enum attributes |
| **Guards** | Inside transition classes or `canTransitionTo()` method | Dedicated `TransitionGuard` contract, container-resolved, AND-combined arrays |
| **Reverse / wildcard** | Manual duplication per source | `#[TransitionFrom(from: '*')]` on the target |
| **Hooks** | Transition class `handle()` + events | `before`/`after` hooks on the attribute, sync or async |
| **History** | Via separate package or custom | Built-in, configurable table name |
| **Multiple fields** | Supported, requires explicit config | Supported, auto-detected from `$casts` |
| **Boilerplate** | 1 class per state + 1 class per transition | 1 enum + attributes only |
| **PHP version** | PHP 8.0+ | PHP 8.2+ (requires Backed Enums) |

**Advantages of laravel-enum-states:**
- Zero boilerplate — no separate state/transition classes
- Everything declared in one place — the Enum itself
- Native PHP Enums for type safety — IDE autocomplete, exhaustive `match`
- Reverse/wildcard transitions and multi-guard AND out of the box
- Built-in transition history with metadata, configurable table name
- Guards and hooks resolved via service container

**Disadvantages compared to spatie:**
- Requires PHP 8.2+ (Backed Enums)
- No custom transition logic classes — hooks are simpler but less flexible
- Smaller community and ecosystem
- No default state configuration on the model

### vs. asantibanez/laravel-eloquent-state-machines

| Aspect | asantibanez | laravel-enum-states |
|--------|------------|---------------------|
| **State definition** | `StateMachine` class with `$initialState` and `$transitions` | Backed Enum with `#[Transition]` attributes |
| **Configuration** | `$stateMachines` array in model | Auto-detected from `$casts` |
| **History** | Built-in | Built-in, configurable table name |
| **Guards** | `beforeTransitionHook()` in StateMachine class | Dedicated `TransitionGuard` contract, AND-combined arrays |
| **Type safety** | String-based states | Enum-based — IDE autocomplete, type checking |

### Summary: When to use laravel-enum-states

**Choose laravel-enum-states when:**
- You want states defined as native PHP Enums with full type safety
- You prefer zero-config auto-detection over manual registration
- You want guards and hooks as separate, testable, injectable classes
- You need built-in transition history with metadata and a configurable table name
- You want reverse/wildcard transitions without hand-written duplication
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
| `#[Transition]` | Enum case (repeatable) | `to: array`, `guard: string\|array\|null`, `before: ?string`, `after: ?string` |
| `#[TransitionFrom]` | Enum case (repeatable) | `from: string\|array`, `guard: string\|array\|null`, `before: ?string`, `after: ?string` |

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
| `allowedTransitions($field, $metadata)` | `BackedEnum[]` | States reachable from the current one, guards applied |
| `stateHistory($field)` | `Collection` | Get transition history for a field |
| `stateHistory()` | `Collection` | Get transition history for all fields |
| `stateTransitions()` | `MorphMany` | History relation — eager-loadable with `with()` |
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
| `InvalidTransitionException` | No matching `#[Transition]`/`#[TransitionFrom]` found, or every matching transition was blocked by its guard(s) |

### Configuration

| Key | Default | Description |
|-----|---------|-------------|
| `enum-states.table` | `state_transitions` (overridable via `ENUM_STATES_TABLE` env) | Name of the history table |

### Publishable assets

| Tag | Publishes |
|-----|-----------|
| `enum-states-migrations` | The migration stub as a timestamped migration in `database/migrations/` |
| `enum-states-config` | `config/enum-states.php` into the application's config directory |

### Models

| Class | Table | Description |
|-------|-------|-------------|
| `StateTransition` | `config('enum-states.table')` (default `state_transitions`) | Polymorphic history record with `from`, `to`, `field`, `metadata`, `transitioned_at` |

## License

MIT License. See [LICENSE.md](LICENSE.md) for details.
