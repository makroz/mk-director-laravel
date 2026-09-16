<?php

declare(strict_types=1);

use Mk\Director\Auth\Enums\TwoFactorPolicy;
use Mk\Director\Tests\MkLaravelTestCase;

/**
 * La decisión del segundo factor en el login: qué pasa con cada política según
 * el usuario esté enrolado o no.
 *
 * Son cinco casillas y una sola de ellas sirve para el caso que motivó todo
 * esto (la consola de plataforma de NetPizza: `required` + sin enrolar ⇒ sólo
 * el enrolamiento). El resto tiene que quedar como estaba: un scope en `off`
 * no cambia de comportamiento aunque el usuario tenga columnas de 2FA cargadas.
 */
uses(MkLaravelTestCase::class);

test('la tabla de decisión completa', function (TwoFactorPolicy $policy, bool $enrolled, bool $challenge, bool $enrollment) {
    expect($policy->requiresChallenge($enrolled))->toBe($challenge);
    expect($policy->requiresEnrollment($enrolled))->toBe($enrollment);
})->with([
    // `off` no mira nada: ni al usuario enrolado. Es la garantía de BC.
    'off + sin enrolar' => [TwoFactorPolicy::Off, false, false, false],
    'off + enrolado' => [TwoFactorPolicy::Off, true, false, false],
    // `optional`: el que se enroló usa su segundo factor; el que no, entra igual.
    'optional + sin enrolar' => [TwoFactorPolicy::Optional, false, false, false],
    'optional + enrolado' => [TwoFactorPolicy::Optional, true, true, false],
    // `required`: sin enrolar sólo puede enrolarse; enrolado, el desafío.
    'required + sin enrolar' => [TwoFactorPolicy::Required, false, false, true],
    'required + enrolado' => [TwoFactorPolicy::Required, true, true, false],
]);

test('desafío y enrolamiento NUNCA son los dos a la vez', function () {
    foreach (TwoFactorPolicy::cases() as $policy) {
        foreach ([true, false] as $enrolled) {
            expect($policy->requiresChallenge($enrolled) && $policy->requiresEnrollment($enrolled))
                ->toBeFalse("{$policy->name} con enrolado=".var_export($enrolled, true));
        }
    }
});

test('los valores son enteros desde 1 (la convención del paquete) y `off` es el default', function () {
    expect(TwoFactorPolicy::Off->value)->toBe(1);
    expect(TwoFactorPolicy::Optional->value)->toBe(2);
    expect(TwoFactorPolicy::Required->value)->toBe(3);
    expect(TwoFactorPolicy::default())->toBe(TwoFactorPolicy::Off);
});

test('se construye desde el nombre que usa el scaffolder, y lo desconocido no cae silencioso en off', function () {
    expect(TwoFactorPolicy::fromName('off'))->toBe(TwoFactorPolicy::Off);
    expect(TwoFactorPolicy::fromName('optional'))->toBe(TwoFactorPolicy::Optional);
    expect(TwoFactorPolicy::fromName('REQUIRED'))->toBe(TwoFactorPolicy::Required);

    // 🔴 Caer en `off` ante un valor mal escrito apagaría el segundo factor de
    // un scope que lo pidió obligatorio, sin un solo error.
    expect(fn () => TwoFactorPolicy::fromName('obligatorio'))->toThrow(ValueError::class);
});

test('`disable` sólo está prohibido cuando la política es required', function () {
    expect(TwoFactorPolicy::Off->allowsDisabling())->toBeTrue();
    expect(TwoFactorPolicy::Optional->allowsDisabling())->toBeTrue();
    expect(TwoFactorPolicy::Required->allowsDisabling())->toBeFalse();
});

test('la contraseña actual se exige para prender el 2FA sólo cuando es opcional', function () {
    // Con `required` el usuario no elige: el login lo manda al enrolamiento sin
    // sesión, y ahí la prueba de identidad fue la contraseña del login mismo.
    expect(TwoFactorPolicy::Optional->requiresPasswordToEnable())->toBeTrue();
    expect(TwoFactorPolicy::Required->requiresPasswordToEnable())->toBeFalse();
    expect(TwoFactorPolicy::Off->requiresPasswordToEnable())->toBeTrue();
});
