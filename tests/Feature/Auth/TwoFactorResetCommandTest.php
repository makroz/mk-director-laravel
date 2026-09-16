<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\PersonalAccessToken;
use Mk\Director\Auth\Events\AuthEvent;
use Mk\Director\Auth\Models\AuthUser;
use Mk\Director\Auth\Services\TotpService;
use Mk\Director\Auth\Support\MorphPivot;
use Mk\Director\Console\Commands\AuthTwoFactorResetCommand;
use Mk\Director\Tests\Concerns\BootsHttpApp;
use Mk\Director\Tests\MkLaravelTestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * `mk:auth:two-factor-reset` — la única salida de un usuario que perdió el
 * teléfono en un scope con la política `required`.
 *
 * Por qué importa que corte las sesiones: sacarle el enrolamiento a alguien y
 * dejarle el token vivo no le saca nada. Quien tenga el dispositivo perdido
 * sigue adentro hasta que el access token expire — y su refresh token, siete
 * días.
 */
uses(MkLaravelTestCase::class, BootsHttpApp::class);

final class ResetCommandOperator extends AuthUser
{
    protected $table = 'reset_command_operators';

    protected $guarded = [];

    protected $casts = [
        'password' => 'hashed',
        'two_factor_secret' => 'encrypted',
        'two_factor_recovery_codes' => 'array',
        'two_factor_confirmed_at' => 'datetime',
        'two_factor_last_step' => 'integer',
    ];

    public function getAuthScope(): string
    {
        return 'operator';
    }
}

beforeEach(function () {
    $this->bootHttpApp(ResetCommandOperator::class, ['tenant' => ['enabled' => false]]);
    config(['hashing' => ['driver' => 'bcrypt', 'bcrypt' => ['rounds' => 4]]]);
    config(['auth.guards.operator' => ['driver' => 'sanctum', 'provider' => 'operators']]);
    config(['auth.providers.operators' => ['driver' => 'eloquent', 'model' => ResetCommandOperator::class]]);

    Schema::create('reset_command_operators', function ($t) {
        $t->uuid('id')->primary();
        $t->string('name');
        $t->string('email');
        $t->string('password');
        $t->string('auth_scope')->default('operator');
        $t->text('two_factor_secret')->nullable();
        $t->text('two_factor_recovery_codes')->nullable();
        $t->timestamp('two_factor_confirmed_at')->nullable();
        $t->unsignedBigInteger('two_factor_last_step')->nullable();
        $t->timestamps();
    });

    Schema::create('personal_access_tokens', function ($t) {
        $t->id();
        $t->morphs('tokenable');
        $t->string('name');
        $t->string('token', 64)->unique();
        $t->text('abilities')->nullable();
        $t->timestamp('last_used_at')->nullable();
        $t->timestamp('expires_at')->nullable();
        $t->timestamps();
    });

    ResetCommandOperator::create([
        'name' => 'Operadora',
        'email' => 'ops@netpizza.test',
        'password' => Hash::make('secret'),
        'auth_scope' => 'operator',
    ]);
});

afterEach(function () {
    MorphPivot::flushSchemaCache();
    $this->tearDownHttpApp();
});

/** @return array{0:int, 1:string} */
function runReset(object $test, array $args): array
{
    $command = new AuthTwoFactorResetCommand;
    $command->setLaravel($test->httpApp);
    $output = new BufferedOutput;

    return [$command->run(new ArrayInput($args), $output), $output->fetch()];
}

function enrolledOperator(): ResetCommandOperator
{
    $service = new TotpService;
    $user = ResetCommandOperator::query()->firstOrFail();
    $user->two_factor_secret = $service->generateSecret();
    $user->two_factor_recovery_codes = $service->hashRecoveryCodes($service->generateRecoveryCodes());
    $user->two_factor_confirmed_at = now();
    $user->two_factor_last_step = 12345;
    $user->save();

    return $user->refresh();
}

test('limpia las cuatro columnas y cierra las sesiones vivas', function () {
    Event::fake();
    $user = enrolledOperator();
    $user->createToken('access', ['auth_scope:operator']);
    $user->createToken('refresh', ['refresh', 'auth_scope:operator']);
    expect(PersonalAccessToken::query()->count())->toBe(2);

    [$exit, $output] = runReset($this, [
        'scope' => 'operator',
        'login' => 'ops@netpizza.test',
        '--force' => true,
    ]);

    expect($exit)->toBe(0, $output);

    $user->refresh();
    expect($user->two_factor_secret)->toBeNull();
    expect($user->two_factor_recovery_codes)->toBeNull();
    expect($user->two_factor_confirmed_at)->toBeNull();
    expect($user->two_factor_last_step)->toBeNull();
    expect($user->hasConfirmedTwoFactor())->toBeFalse();

    // 🔴 Lo que hace que el reset signifique algo.
    expect(PersonalAccessToken::query()->count())->toBe(0);

    Event::assertDispatched(AuthEvent::class, fn (AuthEvent $e) => $e->type === 'auth.two_factor.reset');
});

test('es IDEMPOTENTE: correrlo de nuevo no falla ni toca nada', function () {
    enrolledOperator();

    [$first] = runReset($this, ['scope' => 'operator', 'login' => 'ops@netpizza.test', '--force' => true]);
    [$second, $output] = runReset($this, ['scope' => 'operator', 'login' => 'ops@netpizza.test', '--force' => true]);

    expect($first)->toBe(0);
    expect($second)->toBe(0);
    expect($output)->toContain('no hay nada que sacar');
});

test('limpia también un enrolamiento a medias (secreto sin confirmar)', function () {
    $user = ResetCommandOperator::query()->firstOrFail();
    $user->two_factor_secret = (new TotpService)->generateSecret();
    $user->save();

    [$exit, $output] = runReset($this, ['scope' => 'operator', 'login' => 'ops@netpizza.test', '--force' => true]);

    expect($exit)->toBe(0, $output);
    expect($user->refresh()->two_factor_secret)->toBeNull();
});

test('`--keep-sessions` deja los tokens donde estaban', function () {
    $user = enrolledOperator();
    $user->createToken('access', ['auth_scope:operator']);

    [$exit] = runReset($this, [
        'scope' => 'operator',
        'login' => 'ops@netpizza.test',
        '--force' => true,
        '--keep-sessions' => true,
    ]);

    expect($exit)->toBe(0);
    expect($user->refresh()->two_factor_secret)->toBeNull();
    expect(PersonalAccessToken::query()->count())->toBe(1);
});

test('un usuario que no existe, o un scope sin modelo, fallan sin tocar nada', function () {
    enrolledOperator();

    [$exit, $output] = runReset($this, ['scope' => 'operator', 'login' => 'nadie@netpizza.test', '--force' => true]);
    expect($exit)->toBe(1);
    expect($output)->toContain('No hay ningún operator');

    [$exit, $output] = runReset($this, ['scope' => 'inventado', 'login' => 'ops@netpizza.test', '--force' => true]);
    expect($exit)->toBe(1);
    expect($output)->toContain('No se encontró el modelo');

    [$exit] = runReset($this, ['scope' => 'MAL Scope', 'login' => 'ops@netpizza.test', '--force' => true]);
    expect($exit)->toBe(1);

    // Nada de eso tocó al usuario enrolado.
    expect(ResetCommandOperator::query()->firstOrFail()->hasConfirmedTwoFactor())->toBeTrue();
});
