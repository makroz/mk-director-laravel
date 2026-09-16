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
