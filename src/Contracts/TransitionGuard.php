<?php

declare(strict_types=1);

namespace SoylentGreenStudio\EnumStates\Contracts;

use Illuminate\Database\Eloquent\Model;

interface TransitionGuard
{
    public function allow(Model $model, array $metadata): bool;
}
