<?php

declare(strict_types=1);

use SoylentGreenStudio\EnumStates\Attributes\InitialState;
use SoylentGreenStudio\EnumStates\Attributes\FinalState;
use SoylentGreenStudio\EnumStates\Attributes\Transition;
use SoylentGreenStudio\EnumStates\Contracts\TransitionGuard;
use SoylentGreenStudio\EnumStates\StateMachineManager;
use SoylentGreenStudio\EnumStates\Tests\Support\TestOrderStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;

// ---- Test-local enum with guards and hooks for annotation testing ----

enum AnnotatedStatus: string
{
    #[InitialState]
    #[Transition(to: [self::Processing], guard: \SoylentGreenStudio\EnumStates\Contracts\TransitionGuard::class, before: \SoylentGreenStudio\EnumStates\Contracts\TransitionHook::class, after: \SoylentGreenStudio\EnumStates\Contracts\TransitionHook::class)]
    case Pending = 'pending';

    #[FinalState]
    case Processing = 'processing';
}

// ---- enum-states:graph tests ----

it('displays text graph for a simple enum', function () {
    $this->artisan('enum-states:graph', ['enum' => TestOrderStatus::class])
        ->expectsOutputToContain('[Initial] Pending')
        ->expectsOutputToContain('→ Processing')
        ->expectsOutputToContain('→ Cancelled')
        ->expectsOutputToContain('[Final] Shipped')
        ->expectsOutputToContain('[Final] Cancelled')
        ->assertSuccessful();
});

it('displays mermaid graph', function () {
    $this->artisan('enum-states:graph', ['enum' => TestOrderStatus::class, '--format' => 'mermaid'])
        ->expectsOutputToContain('stateDiagram-v2')
        ->expectsOutputToContain('[*] --> Pending')
        ->expectsOutputToContain('Pending --> Processing')
        ->expectsOutputToContain('Pending --> Cancelled')
        ->expectsOutputToContain('Shipped --> [*]')
        ->expectsOutputToContain('Cancelled --> [*]')
        ->assertSuccessful();
});

it('shows guard and hook annotations in text format', function () {
    Artisan::call('enum-states:graph', ['enum' => AnnotatedStatus::class]);
    $output = Artisan::output();

    expect($output)
        ->toContain('guard: TransitionGuard')
        ->toContain('before: TransitionHook')
        ->toContain('after: TransitionHook');
});

it('shows annotations in mermaid format', function () {
    Artisan::call('enum-states:graph', ['enum' => AnnotatedStatus::class, '--format' => 'mermaid']);
    $output = Artisan::output();

    expect($output)
        ->toContain('guard: TransitionGuard')
        ->toContain('before: TransitionHook')
        ->toContain('after: TransitionHook');
});

it('fails for non-existent class', function () {
    $this->artisan('enum-states:graph', ['enum' => 'App\\NonExistent\\FakeEnum'])
        ->expectsOutputToContain('is not a valid enum')
        ->assertFailed();
});

it('fails for non-enum class', function () {
    $this->artisan('enum-states:graph', ['enum' => \stdClass::class])
        ->expectsOutputToContain('is not a valid enum')
        ->assertFailed();
});

// ---- make:enum-state tests ----

it('generates an enum state file', function () {
    $this->artisan('make:enum-state', ['name' => 'OrderStatus'])
        ->assertSuccessful();

    $path = app_path('Enums/OrderStatus.php');
    expect(file_exists($path))->toBeTrue();

    $content = file_get_contents($path);
    expect($content)
        ->toContain('enum OrderStatus: string')
        ->toContain('use SoylentGreenStudio\EnumStates\Attributes\InitialState')
        ->toContain('use SoylentGreenStudio\EnumStates\Attributes\Transition')
        ->toContain('#[InitialState]')
        ->toContain('#[FinalState]');

    // Cleanup
    @unlink($path);
    @rmdir(app_path('Enums'));
});

// ---- make:transition-guard tests ----

it('generates a transition guard file', function () {
    $this->artisan('make:transition-guard', ['name' => 'StockCheckGuard'])
        ->assertSuccessful();

    $path = app_path('Guards/StockCheckGuard.php');
    expect(file_exists($path))->toBeTrue();

    $content = file_get_contents($path);
    expect($content)
        ->toContain('class StockCheckGuard implements TransitionGuard')
        ->toContain('use SoylentGreenStudio\EnumStates\Contracts\TransitionGuard')
        ->toContain('public function allow(Model $model, array $metadata): bool');

    // Cleanup
    @unlink($path);
    @rmdir(app_path('Guards'));
});

it('generates a guard that is actually runnable', function () {
    $this->artisan('make:transition-guard', ['name' => 'RunnableGuard'])
        ->assertSuccessful();

    $path = app_path('Guards/RunnableGuard.php');
    require $path;

    $guard = new App\Guards\RunnableGuard();
    $model = new class extends Model {};

    // A stub whose body is just a comment satisfies the string assertions above
    // but fatals here on the declared bool return type.
    expect($guard)->toBeInstanceOf(TransitionGuard::class)
        ->and($guard->allow($model, []))->toBeBool();

    @unlink($path);
    @rmdir(app_path('Guards'));
});

it('generates an enum whose states are all reachable', function () {
    $this->artisan('make:enum-state', ['name' => 'ReachableStatus'])
        ->assertSuccessful();

    $path = app_path('Enums/ReachableStatus.php');
    require $path;

    $enumClass = App\Enums\ReachableStatus::class;

    $targets = [];
    foreach (StateMachineManager::getTransitions($enumClass) as $caseTransitions) {
        foreach ($caseTransitions as $transition) {
            foreach ($transition->to as $target) {
                $targets[$target->name] = true;
            }
        }
    }

    $initial = StateMachineManager::getInitialState($enumClass);

    foreach ($enumClass::cases() as $case) {
        if ($case->name === $initial?->name) {
            continue;
        }

        expect(isset($targets[$case->name]))
            ->toBeTrue("generated state {$case->name} is unreachable");
    }

    @unlink($path);
    @rmdir(app_path('Enums'));
});
