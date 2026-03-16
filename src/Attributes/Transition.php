<?php

declare(strict_types=1);

namespace SoylentGreenStudio\EnumStates\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS_CONSTANT | Attribute::IS_REPEATABLE)]
class Transition
{
    public function __construct(
        public readonly array $to,
        public readonly ?string $guard = null,
        public readonly ?string $before = null,
        public readonly ?string $after = null,
    ) {}
}
