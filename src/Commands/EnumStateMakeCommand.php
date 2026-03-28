<?php

declare(strict_types=1);

namespace SoylentGreenStudio\EnumStates\Commands;

use Illuminate\Console\GeneratorCommand;

class EnumStateMakeCommand extends GeneratorCommand
{
    protected $name = 'make:enum-state';

    protected $description = 'Create a new enum state machine';

    protected $type = 'Enum State';

    protected function getStub(): string
    {
        return $this->resolveStubPath('/stubs/enum-state.stub');
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
        return $rootNamespace . '\\Enums';
    }
}
