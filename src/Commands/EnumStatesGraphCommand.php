<?php

declare(strict_types=1);

namespace SoylentGreenStudio\EnumStates\Commands;

use Illuminate\Console\Command;
use ReflectionEnum;
use SoylentGreenStudio\EnumStates\StateMachineManager;

class EnumStatesGraphCommand extends Command
{
    protected $signature = 'enum-states:graph
        {enum : Fully qualified enum class name}
        {--format=text : Output format (text or mermaid)}';

    protected $description = 'Display the state graph for an enum state machine';

    public function handle(): int
    {
        $enumClass = $this->argument('enum');

        if (! enum_exists($enumClass)) {
            $this->error("Class [{$enumClass}] is not a valid enum.");

            return self::FAILURE;
        }

        $reflection = new ReflectionEnum($enumClass);

        if (! $reflection->isBacked()) {
            $this->error("Enum [{$enumClass}] must be a backed enum.");

            return self::FAILURE;
        }

        $format = $this->option('format');

        return match ($format) {
            'mermaid' => $this->renderMermaid($enumClass, $reflection),
            default => $this->renderText($enumClass, $reflection),
        };
    }

    protected function renderText(string $enumClass, ReflectionEnum $reflection): int
    {
        $transitions = StateMachineManager::getTransitions($enumClass);
        $finalStates = StateMachineManager::getFinalStates($enumClass);
        $initialState = StateMachineManager::getInitialState($enumClass);
        $initialStateName = $initialState?->name;

        $shortName = class_basename($enumClass);
        $this->line("{$shortName} State Graph");
        $this->line(str_repeat('=', strlen($shortName) + 12));

        foreach ($reflection->getCases() as $case) {
            $name = $case->getName();
            $prefix = '';

            if ($name === $initialStateName) {
                $prefix .= '[Initial] ';
            }

            if (in_array($name, $finalStates, true)) {
                $prefix .= '[Final] ';
            }

            $this->line("{$prefix}{$name}");

            $caseTransitions = $transitions[$name] ?? [];

            foreach ($caseTransitions as $transition) {
                foreach ($transition->to as $target) {
                    $annotations = $this->buildAnnotations($transition);
                    $line = "  → {$target->name}";

                    if ($annotations !== '') {
                        $line .= " ({$annotations})";
                    }

                    $this->line($line);
                }
            }
        }

        return self::SUCCESS;
    }

    protected function renderMermaid(string $enumClass, ReflectionEnum $reflection): int
    {
        $transitions = StateMachineManager::getTransitions($enumClass);
        $finalStates = StateMachineManager::getFinalStates($enumClass);
        $initialState = StateMachineManager::getInitialState($enumClass);

        $this->line('stateDiagram-v2');

        if ($initialState !== null) {
            $this->line("    [*] --> {$initialState->name}");
        }

        foreach ($reflection->getCases() as $case) {
            $name = $case->getName();
            $caseTransitions = $transitions[$name] ?? [];

            foreach ($caseTransitions as $transition) {
                foreach ($transition->to as $target) {
                    $annotations = $this->buildAnnotations($transition);
                    $line = "    {$name} --> {$target->name}";

                    if ($annotations !== '') {
                        $line .= " : {$annotations}";
                    }

                    $this->line($line);
                }
            }
        }

        foreach ($finalStates as $finalName) {
            $this->line("    {$finalName} --> [*]");
        }

        return self::SUCCESS;
    }

    protected function buildAnnotations($transition): string
    {
        $parts = [];

        if ($transition->guard !== null) {
            $parts[] = 'guard: ' . class_basename($transition->guard);
        }

        if ($transition->before !== null) {
            $parts[] = 'before: ' . class_basename($transition->before);
        }

        if ($transition->after !== null) {
            $parts[] = 'after: ' . class_basename($transition->after);
        }

        return implode(', ', $parts);
    }
}
