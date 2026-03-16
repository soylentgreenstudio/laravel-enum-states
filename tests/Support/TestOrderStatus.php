<?php

declare(strict_types=1);

namespace SoylentGreenStudio\EnumStates\Tests\Support;

use SoylentGreenStudio\EnumStates\Attributes\FinalState;
use SoylentGreenStudio\EnumStates\Attributes\InitialState;
use SoylentGreenStudio\EnumStates\Attributes\Transition;

enum TestOrderStatus: string
{
    #[InitialState]
    #[Transition(to: [self::Processing, self::Cancelled])]
    case Pending = 'pending';

    #[Transition(to: [self::Shipped, self::Cancelled])]
    case Processing = 'processing';

    #[FinalState]
    case Shipped = 'shipped';

    #[FinalState]
    case Cancelled = 'cancelled';
}
