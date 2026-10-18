<?php

declare(strict_types=1);

use Illuminate\Foundation\Auth\User;
use Mk\Director\Auth\Enums\ScopeStatus;
use Mk\Director\Auth\Services\AccountStatus;
use Mk\Director\Tests\MkLaravelTestCase;

/**
 * La ÚNICA definición de "esta cuenta puede autenticarse", compartida por
 * login, refresh y `mk.auth`. Si divergen, un usuario bloqueado entra por la
 * puerta que quedó con la regla vieja.
 */
uses(MkLaravelTestCase::class);

function accountStatusUser(array $attributes, array $casts = []): User
{
    $user = new User;
    $user->mergeCasts($casts);
    $user->setRawAttributes($attributes);

    return $user;
}

test('ScopeStatus: sólo Active autentica', function () {
    $cast = ['status' => ScopeStatus::class];

    expect(AccountStatus::allowsAuthentication(accountStatusUser(['status' => ScopeStatus::Active->value], $cast)))->toBeTrue();

    foreach ([ScopeStatus::Inactive, ScopeStatus::Blocked, ScopeStatus::Pending] as $status) {
        expect(AccountStatus::allowsAuthentication(accountStatusUser(['status' => $status->value], $cast)))->toBeFalse();
    }
});

test('legacy is_active: false/0/"0" bloquean; true y null dejan pasar', function () {
    foreach ([false, 0, '0'] as $value) {
        expect(AccountStatus::allowsAuthentication(accountStatusUser(['is_active' => $value])))->toBeFalse();
    }
    foreach ([true, 1, '1', null] as $value) {
        expect(AccountStatus::allowsAuthentication(accountStatusUser(['is_active' => $value])))->toBeTrue();
    }
});

test('BC: sin columna status ni is_active, el usuario sigue autenticando', function () {
    expect(AccountStatus::allowsAuthentication(accountStatusUser(['name' => 'x'])))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Los chequeos extra del consumidor
|--------------------------------------------------------------------------
|
| El caso que los pidió: suspender a la EMPRESA tiene que cortarles a todos
| sus usuarios, al que está entrando y al que ya tiene un token vivo. Como
| viven acá, las tres puertas los ven de una sola vez.
*/

final class ChequeoQueNiega
{
    public function __invoke($user): bool
    {
        return false;
    }
}

final class ChequeoQueConcede
{
    public function __invoke($user): bool
    {
        return true;
    }
}

afterEach(function () {
    config(['mk_director.auth.account_checks' => []]);
});

test('🔴 un chequeo extra puede negarle la autenticación a un usuario activo', function () {
    $activo = accountStatusUser(['status' => ScopeStatus::Active->value], ['status' => ScopeStatus::class]);

    expect(AccountStatus::allowsAuthentication($activo))->toBeTrue();

    config(['mk_director.auth.account_checks' => [ChequeoQueNiega::class]]);

    expect(AccountStatus::allowsAuthentication($activo))->toBeFalse();
});

test('🔴 UN CHEQUEO EXTRA NO REHABILITA A UN USUARIO BLOQUEADO', function () {
    // Si alcanzara para conceder, agregar un chequeo podría abrirle la puerta
    // a alguien que su propio estado ya bloqueó — y nadie lo leería así.
    config(['mk_director.auth.account_checks' => [ChequeoQueConcede::class]]);

    $bloqueado = accountStatusUser(['status' => ScopeStatus::Blocked->value], ['status' => ScopeStatus::class]);

    expect(AccountStatus::allowsAuthentication($bloqueado))->toBeFalse();
});

test('todos tienen que decir que sí', function () {
    $activo = accountStatusUser(['status' => ScopeStatus::Active->value], ['status' => ScopeStatus::class]);

    config(['mk_director.auth.account_checks' => [ChequeoQueConcede::class, ChequeoQueNiega::class]]);

    expect(AccountStatus::allowsAuthentication($activo))->toBeFalse();
});

test('acepta un closure además del nombre de una clase', function () {
    $activo = accountStatusUser(['status' => ScopeStatus::Active->value], ['status' => ScopeStatus::class]);

    config(['mk_director.auth.account_checks' => [fn ($user) => false]]);

    expect(AccountStatus::allowsAuthentication($activo))->toBeFalse();
});

test('sin chequeos configurados se comporta igual que antes de que existieran', function () {
    config(['mk_director.auth.account_checks' => []]);

    expect(AccountStatus::allowsAuthentication(accountStatusUser(['name' => 'x'])))->toBeTrue();
});

test('🔴 denial(): el código y el estado los declara quien niega; sin ellos, los de siempre', function () {
    $activo = accountStatusUser(['status' => ScopeStatus::Active->value], ['status' => ScopeStatus::class]);

    config(['mk_director.auth.account_checks' => [new class
    {
        public function __invoke($user): bool
        {
            return false;
        }

        public function denialCode(): string
        {
            return 'ERR_DEVICE_REVOKED';
        }

        public function denialStatus(): int
        {
            return 403;
        }
    }]]);
    $denial = AccountStatus::denial($activo);
    expect($denial?->code)->toBe('ERR_DEVICE_REVOKED')
        ->and($denial?->status)->toBe(403)
        ->and($denial?->message)->toBe(AccountStatus::DEFAULT_DENIAL);

    config(['mk_director.auth.account_checks' => [ChequeoQueNiega::class]]);
    $denial = AccountStatus::denial($activo);
    expect($denial?->code)->toBe(AccountStatus::DEFAULT_CODE)->and($denial?->status)->toBeNull();

    config(['mk_director.auth.account_checks' => []]);
    expect(AccountStatus::denial($activo))->toBeNull();
});

test('denial(): un estado que no es 4xx se ignora — negar nunca sale como 200 ni como 500', function () {
    $activo = accountStatusUser(['status' => ScopeStatus::Active->value], ['status' => ScopeStatus::class]);

    foreach ([200, 302, 500] as $raro) {
        config(['mk_director.auth.account_checks' => [new class($raro)
        {
            public function __construct(private int $status) {}

            public function __invoke($user): bool
            {
                return false;
            }

            public function denialStatus(): int
            {
                return $this->status;
            }
        }]]);

        expect(AccountStatus::denial($activo)?->status)->toBeNull();
    }
});
