<?php

declare(strict_types=1);

namespace SoylentGreenStudio\EnumStates;

use Illuminate\Support\ServiceProvider;

class EnumStatesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/enum-states.php', 'enum-states');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../database/migrations/create_state_transitions_table.php.stub'
                    => database_path('migrations/' . date('Y_m_d_His') . '_create_state_transitions_table.php'),
            ], 'enum-states-migrations');

            $this->publishes([
                __DIR__ . '/../config/enum-states.php' => config_path('enum-states.php'),
            ], 'enum-states-config');

            $this->commands([
                Commands\EnumStatesGraphCommand::class,
                Commands\EnumStateMakeCommand::class,
                Commands\TransitionGuardMakeCommand::class,
            ]);
        }
    }
}
