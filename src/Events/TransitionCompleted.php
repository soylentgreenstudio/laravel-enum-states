<?php

declare(strict_types=1);

namespace SoylentGreenStudio\EnumStates\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

class TransitionCompleted
{
    use Dispatchable;

    public function __construct(
        public readonly Model $model,
        public readonly string $field,
        public readonly mixed $from,
        public readonly mixed $to,
        public readonly array $metadata,
    ) {}
}
