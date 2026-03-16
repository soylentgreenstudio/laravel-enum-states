<?php

declare(strict_types=1);

namespace SoylentGreenStudio\EnumStates\Traits;

use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use SoylentGreenStudio\EnumStates\Models\StateTransition;
use SoylentGreenStudio\EnumStates\StateMachineManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

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
     * Get transition history for a field, or all fields.
     */
    public function stateHistory(?string $field = null): Collection
    {
        $query = StateTransition::where('model_type', $this->getMorphClass())
            ->where('model_id', $this->getKey());

        if ($field !== null) {
            $query->where('field', $field);
        }

        return $query->orderBy('transitioned_at')->orderBy('id')->get();
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
     * Resolve which field a given enum belongs to on this model.
     */
    protected function resolveFieldForEnum(BackedEnum $enum): string
    {
        $enumClass = $enum::class;
        $fields = $this->getStateMachineFields();

        foreach ($fields as $field => $class) {
            if ($class === $enumClass) {
                return $field;
            }
        }

        throw new \InvalidArgumentException("No state machine field found for enum [{$enumClass}] on model [" . static::class . '].');
    }
}
