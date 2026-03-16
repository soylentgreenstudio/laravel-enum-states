<?php

declare(strict_types=1);

namespace SoylentGreenStudio\EnumStates\Exceptions;

use BackedEnum;
use RuntimeException;

class FinalStateException extends RuntimeException
{
    public static function cannotTransition(mixed $from, string $field): self
    {
        $fromName = $from instanceof BackedEnum ? $from->name : (string) $from;

        return new self("Cannot transition from final state [{$fromName}] on field [{$field}].");
    }
}
