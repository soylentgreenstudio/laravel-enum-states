<?php

declare(strict_types=1);

namespace SoylentGreenStudio\EnumStates\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use SoylentGreenStudio\EnumStates\Contracts\AsyncTransitionHook;

class ProcessTransitionHook implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $hookClass,
        public readonly Model $model,
        public readonly mixed $from,
        public readonly mixed $to,
        public readonly array $metadata,
    ) {}

    public function handle(): void
    {
        /** @var AsyncTransitionHook $hook */
        $hook = app()->make($this->hookClass);
        $hook->handle($this->model, $this->from, $this->to, $this->metadata);
    }
}
