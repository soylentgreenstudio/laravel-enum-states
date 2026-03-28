<?php

declare(strict_types=1);

namespace SoylentGreenStudio\EnumStates\Commands;

use Illuminate\Console\GeneratorCommand;

class TransitionGuardMakeCommand extends GeneratorCommand
{
    protected $name = 'make:transition-guard';

    protected $description = 'Create a new transition guard';

    protected $type = 'Transition Guard';

    protected function getStub(): string
    {
        return $this->resolveStubPath('/stubs/transition-guard.stub');
    }

    protected function resolveStubPath(string $stub): string
    {
        $customPath = $this->laravel->basePath(trim($stub, '/'));

        return file_exists($customPath)
            ? $customPath
            : __DIR__ . $stub;
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace . '\\Guards';
    }
}
