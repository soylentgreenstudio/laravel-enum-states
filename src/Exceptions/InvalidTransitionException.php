<?php

declare(strict_types=1);

namespace SoylentGreenStudio\EnumStates\Exceptions;

use BackedEnum;
use RuntimeException;

class InvalidTransitionException extends RuntimeException
{
    public static function notAllowed(mixed $from, mixed $to, string $field): self
    {
        $fromName = $from instanceof BackedEnum ? $from->name : (string) $from;
        $toName = $to instanceof BackedEnum ? $to->name : (string) $to;

        return new self("Transition from [{$fromName}] to [{$toName}] is not allowed on field [{$field}].");
    }

    public static function guardBlocked(mixed $from, mixed $to, string $field, string $guard): self
    {
        $fromName = $from instanceof BackedEnum ? $from->name : (string) $from;
        $toName = $to instanceof BackedEnum ? $to->name : (string) $to;

        return new self("Transition from [{$fromName}] to [{$toName}] on field [{$field}] was blocked by guard [{$guard}].");
    }
}
