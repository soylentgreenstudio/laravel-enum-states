<?php

declare(strict_types=1);

namespace SoylentGreenStudio\EnumStates\Tests;

use SoylentGreenStudio\EnumStates\EnumStatesServiceProvider;
use SoylentGreenStudio\EnumStates\StateMachineManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        StateMachineManager::clearCache();

        $this->setUpDatabase();
    }

    protected function getPackageProviders($app): array
    {
        return [EnumStatesServiceProvider::class];
    }

    protected function setUpDatabase(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('status')->default('pending');
            $table->string('payment_status')->default('unpaid');
            $table->timestamps();
        });

        // Run the package migration (stub is evaluated as PHP; extension does not matter for include)
        $migration = include __DIR__ . '/../database/migrations/create_state_transitions_table.php.stub';
        $migration->up();
    }
}
