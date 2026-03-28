<?php

declare(strict_types=1);

namespace SoylentGreenStudio\EnumStates\Contracts;

use Illuminate\Database\Eloquent\Model;

interface AsyncTransitionHook
{
    public function handle(Model $model, mixed $from, mixed $to, array $metadata): void;

    /**
     * The queue name to dispatch this hook to, or null for the default queue.
     */
    public function queue(): ?string;
}
