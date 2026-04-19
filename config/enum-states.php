<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | State Transitions Table
    |--------------------------------------------------------------------------
    |
    | The database table used by the StateTransition history model. Override
    | via the ENUM_STATES_TABLE environment variable or by editing the value
    | after publishing this config with:
    |
    |   php artisan vendor:publish --tag=enum-states-config
    */

    'table' => env('ENUM_STATES_TABLE', 'state_transitions'),
];
