<?php

declare(strict_types=1);

namespace SoylentGreenStudio\EnumStates\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use InvalidArgumentException;
use SoylentGreenStudio\EnumStates\Contracts\AsyncTransitionHook;

class ProcessTransitionHook implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public bool $deleteWhenMissingModels = true;

    public function __construct(
        public readonly string $hookClass,
        public readonly Model $model,
        public readonly mixed $from,
        public readonly mixed $to,
        public readonly array $metadata,
    ) {}

    public function handle(): void
    {
        $hook = app()->make($this->hookClass);

        if (! $hook instanceof AsyncTransitionHook) {
            throw new InvalidArgumentException(
                "Hook [{$this->hookClass}] must implement " . AsyncTransitionHook::class . '.'
            );
        }

        $hook->handle($this->model, $this->from, $this->to, $this->metadata);
    }
}
