<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Utils;

use Mk\Director\Tests\TestCase;
use Mk\Director\Utils\MkRequestAwareStorageUrl;

uses(TestCase::class);

/**
 * El utilitario que arma la url del storage con el host de la request.
 *
 * Nace del incidente de RETO (2026-07-20): los avatares salían rotos en el
 * panel. El archivo estaba bien, el endpoint devolvía bien el `avatar_url` y
 * el front leía el campo correcto — pero el host de esa URL era una IP vieja
 * (`192.168.0.4`, cambiada por DHCP a `.3`) y el server escuchaba sólo en
 * `127.0.0.1`. Cuatro eslabones sanos y la cadena rota igual.
 *
 * La mitad de los tests de acá NO son sobre armar la URL: son sobre CUÁNDO
 * NO hacerlo. Esa es la parte peligrosa.
 */
test('arma la url pegando el prefijo al host de la request', function () {
    expect(MkRequestAwareStorageUrl::buildUrl('http://192.168.0.3:8000'))
        ->toBe('http://192.168.0.3:8000/storage');

    expect(MkRequestAwareStorageUrl::buildUrl('https://reto.test'))
        ->toBe('https://reto.test/storage');
});

test('no duplica ni se come las barras', function () {
    expect(MkRequestAwareStorageUrl::buildUrl('http://localhost:8000/'))
        ->toBe('http://localhost:8000/storage');

    expect(MkRequestAwareStorageUrl::buildUrl('http://localhost:8000', '/media/'))
        ->toBe('http://localhost:8000/media');
});

test('AUTOMÁTICO significa SOLO EN LOCAL', function () {
    // `null` es el default y es el camino por el que pasa cualquiera que no
    // toque la config. Que en producción quede apagado NO puede depender de
    // que alguien se acuerde de apagarlo.
    expect(MkRequestAwareStorageUrl::shouldApply(true, false, null))->toBeTrue();
    expect(MkRequestAwareStorageUrl::shouldApply(false, false, null))->toBeFalse();
});

test('LA PROPIEDAD DE SEGURIDAD: en consola nunca aplica, ni forzado', function () {
    // Sin request entrante no hay host que seguir. Vale incluso con el flag en
    // `true`: en una cola o un cron, el host de la última request web sería un
    // dato prestado y equivocado, y quedaría escrito en un mail o en la base.
    expect(MkRequestAwareStorageUrl::shouldApply(true, true, null))->toBeFalse();
    expect(MkRequestAwareStorageUrl::shouldApply(true, true, true))->toBeFalse();
    expect(MkRequestAwareStorageUrl::shouldApply(false, true, true))->toBeFalse();
});

test('el opt-out explícito gana incluso en local', function () {
    expect(MkRequestAwareStorageUrl::shouldApply(true, false, false))->toBeFalse();
});

test('el opt-in explícito se respeta fuera de local (con su riesgo documentado)', function () {
    // Existe para el caso de staging detrás de un proxy donde el equipo SABE
    // lo que hace. No es el default y el docblock explica por qué no debería
    // usarse en producción: host header injection.
    expect(MkRequestAwareStorageUrl::shouldApply(false, false, true))->toBeTrue();
});
