<?php

declare(strict_types=1);

namespace SoylentGreenStudio\EnumStates\Traits;

use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use SoylentGreenStudio\EnumStates\Models\StateTransition;
use SoylentGreenStudio\EnumStates\StateMachineManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * @mixin Model
 *
 * @method static Builder whereState(string $field, BackedEnum $state)
 * @method static Builder whereNotState(string $field, BackedEnum $state)
 * @method static Builder whereStateIn(string $field, array $states)
 */
trait HasStateMachines
{
    /**
     * Cached detected state machine fields for this model instance.
     *
     * @var array<string, class-string<BackedEnum>>|null
     */
    protected ?array $stateMachineFieldsCache = null;

    /**
     * Boot the trait: autofill state fields with their #[InitialState] values on model creation.
     */
    public static function bootHasStateMachines(): void
    {
        static::creating(function (Model $model) {
            $fields = StateMachineManager::detectStateMachineFields($model);

            foreach ($fields as $field => $enumClass) {
                if ($model->{$field} === null || ! $model->isDirty($field)) {
                    $initial = StateMachineManager::getInitialState($enumClass);

                    if ($initial !== null && $model->getAttribute($field) === null) {
                        $model->{$field} = $initial;
                    }
                }
            }
        });
    }

    /**
     * Get the state machine fields on this model.
     *
     * @return array<string, class-string<BackedEnum>>
     */
    public function getStateMachineFields(): array
    {
        if ($this->stateMachineFieldsCache === null) {
            $this->stateMachineFieldsCache = StateMachineManager::detectStateMachineFields($this);
        }

        return $this->stateMachineFieldsCache;
    }

    /**
     * Transition to a new state.
     *
     * Supports two signatures:
     *   $model->transitionTo(OrderStatus::Processing)
     *   $model->transitionTo(OrderStatus::Processing, $metadata)
     *   $model->transitionTo('payment_status', PaymentStatus::Paid)
     *   $model->transitionTo('payment_status', PaymentStatus::Paid, $metadata)
     */
    public function transitionTo(string|BackedEnum $fieldOrState, BackedEnum|array $stateOrMeta = [], array $metadata = []): void
    {
        if ($fieldOrState instanceof BackedEnum) {
            // Signature: transitionTo(Enum, $meta)
            $to = $fieldOrState;
            $metadata = is_array($stateOrMeta) ? $stateOrMeta : [];
            $field = $this->resolveFieldForEnum($to);
        } else {
            // Signature: transitionTo('field', Enum, $meta)
            $field = $fieldOrState;
            $to = $stateOrMeta;
            // $metadata is already set from the third parameter
        }

        StateMachineManager::transition($this, $field, $to, $metadata);
    }

    /**
     * Check if a transition is allowed (never throws).
     */
    public function canTransitionTo(string|BackedEnum $fieldOrState, BackedEnum|array $stateOrMeta = [], array $metadata = []): bool
    {
        if ($fieldOrState instanceof BackedEnum) {
            $to = $fieldOrState;
            $metadata = is_array($stateOrMeta) ? $stateOrMeta : [];
            $field = $this->resolveFieldForEnum($to);
        } else {
            $field = $fieldOrState;
            $to = $stateOrMeta;
        }

        $from = $this->{$field};

        if (! ($from instanceof BackedEnum)) {
            return false;
        }

        return StateMachineManager::canTransition($from, $to, $this, $metadata);
    }

    /**
     * List the states reachable from the current one, with guards applied.
     *
     * Useful for rendering the available actions for a model. Returns an empty
     * array when the current state is final or the field holds no valid enum.
     *
     * @return BackedEnum[]
     */
    public function allowedTransitions(?string $field = null, array $metadata = []): array
    {
        $field ??= $this->resolveSoleStateMachineField();

        $from = $this->{$field};

        if (! ($from instanceof BackedEnum)) {
            return [];
        }

        return StateMachineManager::allowedTargets($from, $this, $metadata);
    }

    /**
     * All transition history records for this model, oldest first.
     *
     * Exposed as a relation so history can be eager loaded:
     * Order::with('stateTransitions')->get().
     */
    public function stateTransitions(): MorphMany
    {
        return $this->morphMany(StateTransition::class, 'model')
            ->orderBy('transitioned_at')
            ->orderBy('id');
    }

    /**
     * Get transition history for a field, or all fields.
     *
     * Reads from the eager-loaded relation when present, so calling this in a
     * loop over Order::with('stateTransitions')->get() costs no extra queries.
     */
    public function stateHistory(?string $field = null): Collection
    {
        if ($this->relationLoaded('stateTransitions')) {
            $history = $this->getRelation('stateTransitions');

            return $field === null
                ? $history
                : $history->where('field', $field)->values();
        }

        $query = $this->stateTransitions();

        if ($field !== null) {
            $query->where('field', $field);
        }

        return $query->get();
    }

    /**
     * Query scope: where a state field equals a given enum value.
     */
    public function scopeWhereState(Builder $query, string $field, BackedEnum $state): Builder
    {
        return $query->where($field, $state->value);
    }

    /**
     * Query scope: where a state field does NOT equal a given enum value.
     */
    public function scopeWhereNotState(Builder $query, string $field, BackedEnum $state): Builder
    {
        return $query->where($field, '!=', $state->value);
    }

    /**
     * Query scope: where a state field is in a set of enum values.
     */
    public function scopeWhereStateIn(Builder $query, string $field, array $states): Builder
    {
        $values = array_map(fn (BackedEnum $s) => $s->value, $states);

        return $query->whereIn($field, $values);
    }

    /**
     * Resolve the field to act on when the caller did not name one.
     */
    protected function resolveSoleStateMachineField(): string
    {
        $fields = $this->getStateMachineFields();

        if (count($fields) === 0) {
            throw new InvalidArgumentException(
                'Model [' . static::class . '] has no state machine fields.'
            );
        }

        if (count($fields) > 1) {
            throw new InvalidArgumentException(
                'Model [' . static::class . '] has multiple state machine fields ['
                . implode(', ', array_keys($fields)) . ']. Specify the field name explicitly.'
            );
        }

        return array_key_first($fields);
    }

    /**
     * Resolve which field a given enum belongs to on this model.
     */
    protected function resolveFieldForEnum(BackedEnum $enum): string
    {
        $enumClass = $enum::class;
        $fields = $this->getStateMachineFields();

        $matched = array_keys(array_filter($fields, fn (string $class) => $class === $enumClass));

        if (count($matched) === 0) {
            throw new InvalidArgumentException("No state machine field found for enum [{$enumClass}] on model [" . static::class . '].');
        }

        if (count($matched) > 1) {
            throw new InvalidArgumentException(
                "Multiple state machine fields [" . implode(', ', $matched) . "] use enum [{$enumClass}] on model [" . static::class . ']. Specify the field name explicitly.'
            );
        }

        return $matched[0];
    }
}
