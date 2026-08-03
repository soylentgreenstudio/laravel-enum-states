<?php

declare(strict_types=1);

namespace SoylentGreenStudio\EnumStates;

use Illuminate\Contracts\Container\BindingResolutionException;
use ReflectionEnumUnitCase;
use ReflectionException;
use SoylentGreenStudio\EnumStates\Attributes\FinalState;
use SoylentGreenStudio\EnumStates\Attributes\InitialState;
use SoylentGreenStudio\EnumStates\Attributes\Transition;
use SoylentGreenStudio\EnumStates\Attributes\TransitionFrom;
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
use InvalidArgumentException;
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

    /** @var array<string, array<string, class-string<BackedEnum>>> Cache of detected fields keyed by model class */
    protected static array $fieldDetectionCache = [];

    /**
     * Detect which fields on a model are state machine fields.
     *
     * @return array<string, class-string<BackedEnum>> field => enum class
     */
    public static function detectStateMachineFields(Model $model): array
    {
        $casts = $model->getCasts();
        $cacheKey = $model::class . '@' . crc32(json_encode($casts));

        if (isset(static::$fieldDetectionCache[$cacheKey])) {
            return static::$fieldDetectionCache[$cacheKey];
        }

        $fields = [];

        foreach ($casts as $field => $castType) {
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

        return static::$fieldDetectionCache[$cacheKey] = $fields;
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
            if (! empty($case->getAttributes(TransitionFrom::class))) {
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
     * Direct #[Transition] attributes are collected first, then every
     * #[TransitionFrom] on any case is expanded into virtual forward
     * Transition objects appended to the corresponding source cases.
     *
     * @param class-string<BackedEnum> $enumClass
     * @return array<string, Transition[]>
     * @throws ReflectionException
     */
    public static function getTransitions(string $enumClass): array
    {
        if (isset(static::$transitionCache[$enumClass])) {
            return static::$transitionCache[$enumClass];
        }

        $reflection = new ReflectionEnum($enumClass);
        $cases = $reflection->getCases();

        $transitions = [];
        foreach ($cases as $case) {
            $transitions[$case->getName()] = array_map(
                fn ($attr) => $attr->newInstance(),
                $case->getAttributes(Transition::class)
            );
        }

        $finalNames = [];
        foreach ($cases as $case) {
            if (! empty($case->getAttributes(FinalState::class))) {
                $finalNames[] = $case->getName();
            }
        }

        foreach ($cases as $targetCase) {
            $targetAttrs = $targetCase->getAttributes(TransitionFrom::class);
            if (empty($targetAttrs)) {
                continue;
            }

            $targetEnum = $enumClass::from($targetCase->getBackingValue());

            foreach ($targetAttrs as $attr) {
                /** @var TransitionFrom $reverse */
                $reverse = $attr->newInstance();
                $sources = static::expandReverseSources(
                    $reverse->from,
                    $enumClass,
                    $targetCase,
                    $cases,
                    $finalNames
                );

                foreach ($sources as $source) {
                    $transitions[$source->name][] = new Transition(
                        to: [$targetEnum],
                        guard: $reverse->guard,
                        before: $reverse->before,
                        after: $reverse->after,
                    );
                }
            }
        }

        return static::$transitionCache[$enumClass] = $transitions;
    }

    /**
     * Expand a TransitionFrom::$from declaration into concrete source enum cases.
     *
     * @param  string|array<int, BackedEnum>  $from
     * @param  class-string<BackedEnum>  $enumClass
     * @param  ReflectionEnumUnitCase[]  $cases
     * @param  string[]  $finalNames
     * @return BackedEnum[]
     */
    protected static function expandReverseSources(
        string|array $from,
        string $enumClass,
        ReflectionEnumUnitCase $targetCase,
        array $cases,
        array $finalNames
    ): array {
        if ($from === TransitionFrom::WILDCARD || $from === [TransitionFrom::WILDCARD]) {
            $result = [];
            foreach ($cases as $case) {
                if ($case->getName() === $targetCase->getName()) {
                    continue;
                }
                if (in_array($case->getName(), $finalNames, true)) {
                    continue;
                }
                $result[] = $enumClass::from($case->getBackingValue());
            }
            return $result;
        }

        if (! is_array($from)) {
            throw new InvalidArgumentException(
                "TransitionFrom::\$from on [{$enumClass}::{$targetCase->getName()}] must be '*' or an array of enum cases."
            );
        }

        $result = [];
        foreach ($from as $item) {
            if (! $item instanceof BackedEnum || $item::class !== $enumClass) {
                throw new InvalidArgumentException(
                    "TransitionFrom::\$from on [{$enumClass}::{$targetCase->getName()}] must contain only instances of [{$enumClass}]."
                );
            }
            $result[] = $item;
        }
        return $result;
    }

    /**
     * Get the list of final state case names for an enum.
     *
     * @param class-string<BackedEnum> $enumClass
     * @throws ReflectionException
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
     * List every target state reachable from the given state whose guards currently pass.
     *
     * Targets are de-duplicated: each distinct case is guard-checked once, no
     * matter how many #[Transition]/#[TransitionFrom] attributes point at it.
     * A final state yields an empty list.
     *
     * @return BackedEnum[]
     * @throws ReflectionException|BindingResolutionException
     */
    public static function allowedTargets(BackedEnum $from, Model $model, array $metadata = []): array
    {
        $enumClass = $from::class;

        if (in_array($from->name, static::getFinalStates($enumClass), true)) {
            return [];
        }

        $checked = [];
        $allowed = [];

        foreach (static::getTransitions($enumClass)[$from->name] ?? [] as $transition) {
            foreach ($transition->to as $target) {
                if (isset($checked[$target->name])) {
                    continue;
                }

                $checked[$target->name] = true;

                // findAllowedTransition already applies OR semantics across every
                // attribute pointing at this target, so one call settles the case.
                if (static::findAllowedTransition($from, $target, $model, $metadata) !== null) {
                    $allowed[] = $target;
                }
            }
        }

        return $allowed;
    }

    /**
     * Find all Transition attributes that allow from -> to.
     *
     * @return Transition[]
     * @throws ReflectionException
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
     * Find the first Transition whose guards (if any) all pass.
     *
     * Guards on a single transition are AND-combined: every guard must
     * return true. A single false short-circuits the transition. Multiple
     * matching Transition attributes still provide OR semantics across
     * attributes (the first transition whose guard chain fully passes wins).
     *
     * Returns null if no matching transition exists or every matching
     * transition was blocked by at least one of its guards.
     * @throws ReflectionException|BindingResolutionException
     */
    protected static function findAllowedTransition(BackedEnum $from, BackedEnum $to, Model $model, array $metadata = [], array &$checkedGuards = []): ?Transition
    {
        $transitions = static::findTransitions($from, $to);

        foreach ($transitions as $transition) {
            $guards = static::normaliseGuards($transition->guard);

            if ($guards === []) {
                return $transition;
            }

            $allPassed = true;
            foreach ($guards as $guardClass) {
                $guard = app()->make($guardClass);

                if (! $guard instanceof TransitionGuard) {
                    throw new InvalidArgumentException(
                        "Guard [{$guardClass}] must implement " . TransitionGuard::class . '.'
                    );
                }

                $checkedGuards[] = $guardClass;

                if (! $guard->allow($model, $metadata)) {
                    $allPassed = false;
                    break;
                }
            }

            if ($allPassed) {
                return $transition;
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    protected static function normaliseGuards(string|array|null $guard): array
    {
        if ($guard === null) {
            return [];
        }

        return is_array($guard) ? array_values($guard) : [$guard];
    }

    /**
     * Execute a state transition on a model.
     *
     * @throws FinalStateException
     * @throws InvalidTransitionException
     * @throws ReflectionException
     * @throws BindingResolutionException
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

        // Resolve the transition up front so guards run once (reused under the lock
        // when the state has not moved in between).
        $preTransition = static::findAllowedTransition($from, $to, $model, $metadata);

        // Fire TransitionStarted with the in-memory state
        event(new TransitionStarted($model, $field, $from, $to, $metadata));

        try {
            /** @var BackedEnum $confirmedFrom */
            $confirmedFrom = null;
            $pendingAsyncHooks = [];

            DB::transaction(function () use ($model, $field, $from, $to, $metadata, $enumClass, $preTransition, &$confirmedFrom, &$pendingAsyncHooks) {
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
                // Reuse pre-transaction result if state hasn't changed (avoids double guard execution)
                $checkedGuards = [];
                if ($preTransition !== null && $confirmedFrom === $from) {
                    $transition = $preTransition;
                } else {
                    $transition = static::findAllowedTransition($confirmedFrom, $to, $fresh, $metadata, $checkedGuards);
                }

                if ($transition === null) {
                    throw InvalidTransitionException::guardBlocked($confirmedFrom, $to, $field, implode(', ', $checkedGuards));
                }

                // 4. Run before hook — sync inline on the locked instance, async
                //    collected for post-commit dispatch
                if ($transition->before !== null) {
                    $hook = app()->make($transition->before);
                    static::validateHookInterface($hook, $transition->before);

                    if ($hook instanceof AsyncTransitionHook) {
                        $pendingAsyncHooks[] = [
                            'hookClass' => $transition->before,
                            'queue' => $hook->queue(),
                        ];
                    } else {
                        // $fresh holds the lock and is the instance about to be saved,
                        // so attribute changes made here are persisted with the transition.
                        $hook->handle($fresh, $confirmedFrom, $to, $metadata);
                    }
                }

                // 5. Update model (save on $fresh which holds the lock)
                $fresh->{$field} = $to;
                $fresh->save();

                // Sync the committed state back to the caller's model instance
                $model->{$field} = $to;
                $model->syncOriginalAttribute($field);

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
                    static::validateHookInterface($hook, $transition->after);

                    if ($hook instanceof AsyncTransitionHook) {
                        // Collect for post-commit dispatch
                        $pendingAsyncHooks[] = [
                            'hookClass' => $transition->after,
                            'queue' => $hook->queue(),
                        ];
                    } elseif ($hook instanceof TransitionHook) {
                        $hook->handle($fresh, $confirmedFrom, $to, $metadata);
                    }
                }
            });

            // 8. Dispatch async hooks (only after successful commit, before-hooks first)
            foreach ($pendingAsyncHooks as $asyncHook) {
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
     * Validate that a resolved hook implements TransitionHook or AsyncTransitionHook.
     */
    protected static function validateHookInterface(object $hook, string $hookClass): void
    {
        if (! $hook instanceof TransitionHook && ! $hook instanceof AsyncTransitionHook) {
            throw new InvalidArgumentException(
                "Hook [{$hookClass}] must implement " . TransitionHook::class . ' or ' . AsyncTransitionHook::class . '.'
            );
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
        static::$fieldDetectionCache = [];
    }
}
