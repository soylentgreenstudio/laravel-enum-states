<?php

declare(strict_types=1);

namespace SoylentGreenStudio\EnumStates\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS_CONSTANT | Attribute::IS_REPEATABLE)]
class Transition
{
    /**
     * @param  array  $to     Target BackedEnum cases of this transition.
     * @param  string|array<int, string>|null  $guard  A single guard class, an array of guard classes (AND-combined), or null.
     */
    public function __construct(
        public readonly array $to,
        public readonly string|array|null $guard = null,
        public readonly ?string $before = null,
        public readonly ?string $after = null,
    ) {}
}
