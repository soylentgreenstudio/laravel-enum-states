<?php

declare(strict_types=1);

namespace SoylentGreenStudio\EnumStates\Attributes;

use Attribute;
use BackedEnum;

/**
 * Declares inbound transitions on the target state case.
 *
 * Use the sentinel '*' to allow this target from every non-final case in
 * the enum (excluding the target itself), or supply an explicit array of
 * BackedEnum cases of the same enum to enumerate the allowed sources.
 */
#[Attribute(Attribute::TARGET_CLASS_CONSTANT | Attribute::IS_REPEATABLE)]
class TransitionFrom
{
    public const WILDCARD = '*';

    /**
     * @param  string|array<int, BackedEnum>  $from   '*' or an array of cases of the same enum.
     * @param  string|array<int, string>|null  $guard  A single guard class, an array of guard classes (AND), or null.
     */
    public function __construct(
        public readonly string|array $from,
        public readonly string|array|null $guard = null,
        public readonly ?string $before = null,
        public readonly ?string $after = null,
    ) {}
}
