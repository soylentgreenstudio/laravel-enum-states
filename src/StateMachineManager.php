<?php

declare(strict_types=1);

namespace SoylentGreenStudio\EnumStates;

use ReflectionException;
use SoylentGreenStudio\EnumStates\Attributes\FinalState;
use SoylentGreenStudio\EnumStates\Attributes\InitialState;
use SoylentGreenStudio\EnumStates\Attributes\Transition;
use SoylentGreenStudio\EnumStates\Contracts\AsyncTransitionHook;
use SoylentGreenStudio\EnumStates\Contracts\TransitionGuard;
use SoylentGreenStudio\EnumStates\Contracts\TransitionHook;
use SoylentGreenStudio\EnumStates\Jobs\ProcessTransitionHook;
use SoylentGreenStudio\EnumStates\Events\TransitionCompleted;
use SoylentGreenStudio\EnumStates\Events\TransitionFailed;
use SoylentGreenStudio\EnumStates\Events\TransitionStarted;
use SoylentGreenStudio\EnumStates\Exceptions\FinalStateException;
use SoylentGreenStudio\EnumStates\Exceptions\InvalidTransitionException;
use SoylentGreenStudio\EnumStates\Models\StateTransition;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use BackedEnum;
use ReflectionEnum;
use RuntimeException;
use Throwable;

class StateMachineManager
{
    /** @var array<string, array<string, Transition[]>> Cache of enum transitions keyed by enum class then case name */
    protected static array $transitionCache = [];

    /** @var array<string, string[]> Cache of final states keyed by enum class */
    protected static array $finalStateCache = [];

    /** @var array<string, ?string> Cache of initial state case name keyed by enum class */
    protected static array $initialStateCache = [];

    /**
     * Detect which fields on a model are state machine fields.
     *
     * @return array<string, class-string<BackedEnum>> field => enum class
     */
    public static function detectStateMachineFields(Model $model): array
    {
        $fields = [];

        foreach ($model->getCasts() as $field => $castType) {
            if (! is_string($castType)) {
                continue;
            }

            if (! enum_exists($castType)) {
                continue;
            }

            $reflection = new ReflectionEnum($castType);

            if (! $reflection->isBacked()) {
                continue;
            }

            if (static::hasStateMachineAttributes($reflection)) {
                $fields[$field] = $castType;
            }
        }

        return $fields;
    }

    /**
     * Check if an enum class has any state machine attributes.
     */
    protected static function hasStateMachineAttributes(ReflectionEnum $reflection): bool
    {
        foreach ($reflection->getCases() as $case) {
            if (! empty($case->getAttributes(Transition::class))) {
                return true;
            }
            if (! empty($case->getAttributes(InitialState::class))) {
                return true;
            }
            if (! empty($case->getAttributes(FinalState::class))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get all transitions for an enum class, indexed by case name.
     *
     * @param class-string<BackedEnum> $enumClass
     * @return array<string, Transition[]>
     */
    public static function getTransitions(string $enumClass): array
    {
        if (isset(static::$transitionCache[$enumClass])) {
            return static::$transitionCache[$enumClass];
        }

        $transitions = [];
        $reflection = new ReflectionEnum($enumClass);

        foreach ($reflection->getCases() as $case) {
            $attrs = $case->getAttributes(Transition::class);
            $transitions[$case->getName()] = array_map(
                fn ($attr) => $attr->newInstance(),
                $attrs
            );
        }

        return static::$transitionCache[$enumClass] = $transitions;
    }

    /**
     * Get the list of final state case names for an enum.
     *
     * @param class-string<BackedEnum> $enumClass
     */
    public static function getFinalStates(string $enumClass): array
    {
        if (isset(static::$finalStateCache[$enumClass])) {
            return static::$finalStateCache[$enumClass];
        }

        $finals = [];
        $reflection = new ReflectionEnum($enumClass);

        foreach ($reflection->getCases() as $case) {
            if (! empty($case->getAttributes(FinalState::class))) {
                $finals[] = $case->getName();
            }
        }

        return static::$finalStateCache[$enumClass] = $finals;
    }

    /**
     * Get the initial state for an enum class, or null if none is marked.
     *
     * @param class-string<BackedEnum> $enumClass
     * @throws ReflectionException
     */
    public static function getInitialState(string $enumClass): ?BackedEnum
    {
        if (isset(static::$initialStateCache[$enumClass])) {
            $name = static::$initialStateCache[$enumClass];

            return $enumClass::from((new ReflectionEnum($enumClass))->getCase($name)->getBackingValue());
        }

        if (array_key_exists($enumClass, static::$initialStateCache)) {
            return null;
        }

        $reflection = new ReflectionEnum($enumClass);

        foreach ($reflection->getCases() as $case) {
            if (! empty($case->getAttributes(InitialState::class))) {
                static::$initialStateCache[$enumClass] = $case->getName();

                return $enumClass::from($case->getBackingValue());
            }
        }

        static::$initialStateCache[$enumClass] = null;

        return null;
    }

    /**
     * Check if the current state can transition to the target state.
     */
    public static function canTransition(BackedEnum $from, BackedEnum $to, Model $model, array $metadata = []): bool
    {
        $enumClass = $from::class;

        if (in_array($from->name, static::getFinalStates($enumClass), true)) {
            return false;
        }

        return static::findAllowedTransition($from, $to, $model, $metadata) !== null;
    }

    /**
     * Find all Transition attributes that allow from -> to.
     *
     * @return Transition[]
     */
    protected static function findTransitions(BackedEnum $from, BackedEnum $to): array
    {
        $enumClass = $from::class;
        $transitions = static::getTransitions($enumClass);
        $caseTransitions = $transitions[$from->name] ?? [];

        $matched = [];

        foreach ($caseTransitions as $transition) {
            foreach ($transition->to as $target) {
                if ($target === $to) {
                    $matched[] = $transition;
                    break;
                }
            }
        }

        return $matched;
    }

    /**
     * Find the first Transition whose guard (if any) passes.
     * Returns null if no matching transition exists or all guards block.
     */
    protected static function findAllowedTransition(BackedEnum $from, BackedEnum $to, Model $model, array $metadata = []): ?Transition
    {
        $transitions = static::findTransitions($from, $to);

        foreach ($transitions as $transition) {
            if ($transition->guard === null) {
                return $transition;
            }

            /** @var TransitionGuard $guard */
            $guard = app()->make($transition->guard);

            if ($guard->allow($model, $metadata)) {
                return $transition;
            }
        }

        return null;
    }

    /**
     * Execute a state transition on a model.
     *
     * @throws FinalStateException
     * @throws InvalidTransitionException
     */
    public static function transition(Model $model, string $field, BackedEnum $to, array $metadata = []): void
    {
        $from = $model->{$field};
        $enumClass = $to::class;

        if (! ($from instanceof BackedEnum)) {
            throw new RuntimeException(
                "Cannot transition field [{$field}] on model [{$model->getMorphClass()}]: current value is not a valid enum state."
            );
        }

        // Pre-validate outside transaction for fast feedback
        if (in_array($from->name, static::getFinalStates($enumClass), true)) {
            throw FinalStateException::cannotTransition($from, $field);
        }

        if (empty(static::findTransitions($from, $to))) {
            throw InvalidTransitionException::notAllowed($from, $to, $field);
        }

        // Fire TransitionStarted with the in-memory state
        event(new TransitionStarted($model, $field, $from, $to, $metadata));

        try {
            /** @var BackedEnum $confirmedFrom */
            $confirmedFrom = null;
            $pendingAsyncAfterHooks = [];

            DB::transaction(function () use ($model, $field, $to, $metadata, $enumClass, &$confirmedFrom, &$pendingAsyncAfterHooks) {
                // 1. Re-read the model with a lock to prevent race conditions
                $fresh = $model->newQuery()->lockForUpdate()->find($model->getKey());

                if ($fresh === null) {
                    throw new RuntimeException("Model [{$model->getMorphClass()}:{$model->getKey()}] was deleted during transition.");
                }

                $confirmedFrom = $fresh->{$field};

                // 2. Validate the locked state
                if (! ($confirmedFrom instanceof BackedEnum)) {
                    throw new RuntimeException("Field [{$field}] on model [{$model->getMorphClass()}] is not a valid enum state.");
                }

                if (in_array($confirmedFrom->name, static::getFinalStates($enumClass), true)) {
                    throw FinalStateException::cannotTransition($confirmedFrom, $field);
                }

                if (empty(static::findTransitions($confirmedFrom, $to))) {
                    throw InvalidTransitionException::notAllowed($confirmedFrom, $to, $field);
                }

                // 3. Find a transition whose guard passes
                $transition = static::findAllowedTransition($confirmedFrom, $to, $fresh, $metadata);

                if ($transition === null) {
                    throw InvalidTransitionException::guardBlocked($confirmedFrom, $to, $field, 'all matching guards');
                }

                // 4. Run before hook
                if ($transition->before !== null) {
                    $hook = app()->make($transition->before);

                    if ($hook instanceof AsyncTransitionHook) {
                        // Fire-and-forget: dispatch to queue, does not block the transition
                        $pending = ProcessTransitionHook::dispatch(
                            $transition->before, $model, $confirmedFrom, $to, $metadata
                        );
                        if ($hook->queue() !== null) {
                            $pending->onQueue($hook->queue());
                        }
                    } elseif ($hook instanceof TransitionHook) {
                        $hook->handle($model, $confirmedFrom, $to, $metadata);
                    }
                }

                // 5. Update model
                $model->{$field} = $to;
                $model->save();

                // 6. Write history
                StateTransition::create([
                    'model_type' => $model->getMorphClass(),
                    'model_id' => $model->getKey(),
                    'field' => $field,
                    'from' => $confirmedFrom->value,
                    'to' => $to->value,
                    'metadata' => ! empty($metadata) ? $metadata : null,
                    'transitioned_at' => now(),
                ]);

                // 7. Run after hook
                if ($transition->after !== null) {
                    $hook = app()->make($transition->after);

                    if ($hook instanceof AsyncTransitionHook) {
                        // Collect for post-commit dispatch
                        $pendingAsyncAfterHooks[] = [
                            'hookClass' => $transition->after,
                            'queue' => $hook->queue(),
                        ];
                    } elseif ($hook instanceof TransitionHook) {
                        $hook->handle($model, $confirmedFrom, $to, $metadata);
                    }
                }
            });

            // 8. Dispatch async after-hooks (only after successful commit)
            foreach ($pendingAsyncAfterHooks as $asyncHook) {
                $pending = ProcessTransitionHook::dispatch(
                    $asyncHook['hookClass'], $model, $confirmedFrom ?? $from, $to, $metadata
                );
                if ($asyncHook['queue'] !== null) {
                    $pending->onQueue($asyncHook['queue']);
                }
            }

            // Fire TransitionCompleted with the confirmed from-state
            event(new TransitionCompleted($model, $field, $confirmedFrom ?? $from, $to, $metadata));
        } catch (Throwable $e) {
            event(new TransitionFailed($model, $field, $from, $to, $e));

            throw $e;
        }
    }

    /**
     * Clear the internal reflection caches (useful for testing).
     */
    public static function clearCache(): void
    {
        static::$transitionCache = [];
        static::$finalStateCache = [];
        static::$initialStateCache = [];
    }
}
