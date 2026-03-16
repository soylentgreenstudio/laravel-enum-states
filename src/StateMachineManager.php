<?php

declare(strict_types=1);

namespace SoylentGreenStudio\EnumStates;

use SoylentGreenStudio\EnumStates\Attributes\FinalState;
use SoylentGreenStudio\EnumStates\Attributes\InitialState;
use SoylentGreenStudio\EnumStates\Attributes\Transition;
use SoylentGreenStudio\EnumStates\Contracts\TransitionGuard;
use SoylentGreenStudio\EnumStates\Contracts\TransitionHook;
use SoylentGreenStudio\EnumStates\Events\TransitionCompleted;
use SoylentGreenStudio\EnumStates\Events\TransitionFailed;
use SoylentGreenStudio\EnumStates\Events\TransitionStarted;
use SoylentGreenStudio\EnumStates\Exceptions\FinalStateException;
use SoylentGreenStudio\EnumStates\Exceptions\InvalidTransitionException;
use SoylentGreenStudio\EnumStates\Models\StateTransition;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use ReflectionEnum;
use ReflectionEnumBackedCase;

class StateMachineManager
{
    /** @var array<string, array<string, Transition[]>> Cache of enum transitions keyed by enum class then case name */
    protected static array $transitionCache = [];

    /** @var array<string, string[]> Cache of final states keyed by enum class */
    protected static array $finalStateCache = [];

    /** @var array<string, string[]> Cache of initial states keyed by enum class */
    protected static array $initialStateCache = [];

    /**
     * Detect which fields on a model are state machine fields.
     *
     * @return array<string, class-string<\BackedEnum>> field => enum class
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
     * Check if the current state can transition to the target state.
     */
    public static function canTransition(\BackedEnum $from, \BackedEnum $to, Model $model, array $metadata = []): bool
    {
        $enumClass = $from::class;

        // Check if from is a final state
        if (in_array($from->name, static::getFinalStates($enumClass), true)) {
            return false;
        }

        // Find a matching transition
        $transition = static::findTransition($from, $to);

        if ($transition === null) {
            return false;
        }

        // Check guard
        if ($transition->guard !== null) {
            /** @var TransitionGuard $guard */
            $guard = app()->make($transition->guard);

            if (! $guard->allow($model, $metadata)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Find the Transition attribute that allows from -> to.
     */
    protected static function findTransition(\BackedEnum $from, \BackedEnum $to): ?Transition
    {
        $enumClass = $from::class;
        $transitions = static::getTransitions($enumClass);
        $caseTransitions = $transitions[$from->name] ?? [];

        foreach ($caseTransitions as $transition) {
            foreach ($transition->to as $target) {
                if ($target === $to) {
                    return $transition;
                }
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
    public static function transition(Model $model, string $field, \BackedEnum $to, array $metadata = []): void
    {
        $from = $model->{$field};
        $enumClass = $from::class;

        // 1. Check if current state is final
        if (in_array($from->name, static::getFinalStates($enumClass), true)) {
            throw FinalStateException::cannotTransition($from, $field);
        }

        // 2. Find matching transition
        $transition = static::findTransition($from, $to);

        if ($transition === null) {
            throw InvalidTransitionException::notAllowed($from, $to, $field);
        }

        // 3. Check guard
        if ($transition->guard !== null) {
            /** @var TransitionGuard $guard */
            $guard = app()->make($transition->guard);

            if (! $guard->allow($model, $metadata)) {
                throw InvalidTransitionException::guardBlocked($from, $to, $field, $transition->guard);
            }
        }

        // 4. Fire TransitionStarted
        event(new TransitionStarted($model, $field, $from, $to, $metadata));

        try {
            // 5. Wrap in DB transaction
            DB::transaction(function () use ($model, $field, $from, $to, $transition, $metadata) {
                // 6. Run before hook
                if ($transition->before !== null) {
                    /** @var TransitionHook $hook */
                    $hook = app()->make($transition->before);
                    $hook->handle($model, $from, $to, $metadata);
                }

                // 7. Update model
                $model->{$field} = $to;
                $model->save();

                // 8. Write history
                StateTransition::create([
                    'model_type' => $model->getMorphClass(),
                    'model_id' => $model->getKey(),
                    'field' => $field,
                    'from' => $from->value,
                    'to' => $to->value,
                    'metadata' => ! empty($metadata) ? $metadata : null,
                    'transitioned_at' => now(),
                ]);

                // 9. Run after hook
                if ($transition->after !== null) {
                    /** @var TransitionHook $hook */
                    $hook = app()->make($transition->after);
                    $hook->handle($model, $from, $to, $metadata);
                }
            });

            // 10. Fire TransitionCompleted
            event(new TransitionCompleted($model, $field, $from, $to, $metadata));
        } catch (\Throwable $e) {
            // 11. Fire TransitionFailed, re-throw
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
