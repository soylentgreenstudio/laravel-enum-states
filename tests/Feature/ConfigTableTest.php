<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use SoylentGreenStudio\EnumStates\Models\StateTransition;

it('uses the default state_transitions table when config is untouched', function () {
    expect((new StateTransition())->getTable())->toBe('state_transitions');
});

it('resolves the table name from config at construction time', function () {
    config()->set('enum-states.table', 'custom_audit_trail');

    expect((new StateTransition())->getTable())->toBe('custom_audit_trail');
});

it('creates the default state_transitions table via the published migration', function () {
    expect(Schema::hasTable('state_transitions'))->toBeTrue();
});
