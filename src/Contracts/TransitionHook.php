<?php

declare(strict_types=1);

namespace SoylentGreenStudio\EnumStates\Contracts;

use Illuminate\Database\Eloquent\Model;

interface TransitionHook
{
    public function handle(Model $model, mixed $from, mixed $to, array $metadata): void;
}
