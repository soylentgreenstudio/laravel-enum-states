<?php

declare(strict_types=1);

namespace SoylentGreenStudio\EnumStates;

use Illuminate\Support\ServiceProvider;

class EnumStatesServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishesMigrations([
                __DIR__ . '/../database/migrations' => database_path('migrations'),
            ]);

            $this->commands([
                Commands\EnumStatesGraphCommand::class,
                Commands\EnumStateMakeCommand::class,
                Commands\TransitionGuardMakeCommand::class,
            ]);
        }
    }
}
