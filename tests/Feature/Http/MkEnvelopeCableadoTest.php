<?php

declare(strict_types=1);

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;
use Mk\Director\Auth\Models\AuthUser;
use Mk\Director\Http\Middleware\MkEnvelope;
use Mk\Director\Tests\Concerns\BootsHttpApp;
use Mk\Director\Tests\MkLaravelTestCase;

/**
 * HALLAZGO 38, LA MITAD QUE FALTA: QUE EL NORMALIZADOR ESTÉ CABLEADO.
 *
 * `tests/Unit/Http/MkEnvelopeMiddlewareTest.php` mide QUÉ HACE el middleware, y con
 * eso alcanza para la forma del sobre. Pero los nueve casos pasan en verde con el
 * middleware escrito y nunca registrado — que es exactamente cómo este paquete ya se
 * equivocó tres veces: la Policy que `CRUDSmart` no invocaba (hallazgo 31), el
 * `AbilityResolver` que nadie bindeaba (hallazgo 13), y el aviso del hallazgo 14.
 *
 * Acá se mide el CABLEADO: una ruta de verdad, en el grupo `api` de verdad, por el
 * Kernel de verdad.
 *
 * ── ⚠️ Y EL DEFAULT TAMBIÉN SE MIDE ─────────────────────────────────────────
 *
 * `force_envelope` es opt-in a propósito: prenderlo en un `composer update`
 * reescribiría el cuerpo de todas las respuestas JSON de todo consumidor. Un test que
 * sólo compruebe que con el flag prendido funciona deja pasar el día en que el default
 * cambie sin que nadie lo decida, y eso es un BC break silencioso.
 */
uses(MkLaravelTestCase::class, BootsHttpApp::class);

final class EnvelopeAdmin extends AuthUser
{
    protected $table = 'envelope_admins';

    protected $guarded = [];

    public function getAuthScope(): string
    {
        return 'admin';
    }
}

afterEach(function () {
    $this->tearDownHttpApp();
});

/** Levanta la app con el flag en el estado pedido y publica una ruta sin sobre. */
function armarMundoDelSobre(object $test, ?bool $forzar): void
{
    $mk = ['tenant' => ['enabled' => false]];

    if ($forzar !== null) {
        $mk['response'] = ['force_envelope' => $forzar];
    }

    $test->bootHttpApp(EnvelopeAdmin::class, $mk);

    // Un controller escrito a mano, que es el caso del hallazgo: devuelve
    // `{data: ...}` sin `success`, como haría cualquier `Resource->response()`.
    Route::middleware(['api'])->get('api/pedidos', fn () => new JsonResponse(['data' => ['id' => 7]], 200));

    // La misma respuesta, con el alias puesto a mano en la ruta.
    Route::middleware(['api', 'mk.envelope'])->get('api/pedidos-con-alias', fn () => new JsonResponse(['data' => ['id' => 7]], 200));
}

function cuerpoDe(object $test, string $uri): array
{
    return (array) json_decode((string) $test->httpGet($uri)->getContent(), true);
}

// ─────────────────────────────────────────────────────────────────────────────

test('🔴 con `force_envelope` prendido, una ruta escrita a mano sale CON sobre', function () {
    armarMundoDelSobre($this, forzar: true);

    $cuerpo = cuerpoDe($this, '/api/pedidos');

    expect($cuerpo['success'])->toBeTrue()
        ->and($cuerpo['data']['id'])->toBe(7);
});

test('el alias `mk.envelope` está registrado y sirve para UNA parte de la API', function () {
    // Flag APAGADO: lo único que pone el sobre acá es el alias de la ruta.
    armarMundoDelSobre($this, forzar: false);

    expect(cuerpoDe($this, '/api/pedidos-con-alias'))->toHaveKey('success');
});

/*
|--------------------------------------------------------------------------
| LOS CONTROLES DEL DEFAULT.
|--------------------------------------------------------------------------
*/

test('⚠️ CONTROL: el default NO fuerza el sobre — prenderlo es decisión del consumidor', function () {
    armarMundoDelSobre($this, forzar: null);

    $cuerpo = cuerpoDe($this, '/api/pedidos');

    expect($cuerpo)->not->toHaveKey('success')
        ->and($cuerpo['data']['id'])->toBe(7);
});

test('CONTROL: con el flag apagado, la ruta sin alias sigue sin sobre', function () {
    armarMundoDelSobre($this, forzar: false);

    expect(cuerpoDe($this, '/api/pedidos'))->not->toHaveKey('success');
});

test('CONTROL: el flag no duplica el middleware en la ruta que YA tiene el alias', function () {
    armarMundoDelSobre($this, forzar: true);

    // Correr dos veces sería idempotente igual (la segunda ve `success` y se va),
    // pero conviene dejarlo medido: es lo que hace que prender el flag no rompa a
    // quien ya puso el alias a mano.
    $cuerpo = cuerpoDe($this, '/api/pedidos-con-alias');

    expect($cuerpo['success'])->toBeTrue()
        ->and($cuerpo['data'])->toBe(['id' => 7]);
});

test('el alias apunta a la clase del paquete, no a una copia del consumidor', function () {
    armarMundoDelSobre($this, forzar: false);

    expect($this->httpApp['router']->getMiddleware()['mk.envelope'] ?? null)
        ->toBe(MkEnvelope::class);
});

/*
|--------------------------------------------------------------------------
| HALLAZGO 66 — LAS RUTAS DE MÓDULO NO PASAN POR EL GRUPO `api`.
|--------------------------------------------------------------------------
|
| Un módulo registra sus rutas con `loadRoutesFrom()` desde su provider, fuera
| del grupo `api` —por eso su prefijo lleva `api/` a mano—. Empujado sólo al
| grupo, `force_envelope` no llegaba a NINGUNA: medido en NetPizza, 43 de 292
| tests rojos con el flag prendido y el normalizador del proyecto sacado.
*/

test('🔴 con `force_envelope`, una ruta bajo `api/` FUERA del grupo `api` también sale con sobre', function () {
    armarMundoDelSobre($this, forzar: true);

    // Como la registra un módulo: sin el grupo, con el `api/` en el prefijo.
    Route::get('api/modulo/pedidos', fn () => new JsonResponse(['data' => ['id' => 9]], 200));

    $cuerpo = cuerpoDe($this, '/api/modulo/pedidos');

    expect($cuerpo['success'])->toBeTrue()
        ->and($cuerpo['data']['id'])->toBe(9);
});

test('CONTROL: fuera de `api/` el flag no toca nada', function () {
    armarMundoDelSobre($this, forzar: true);

    Route::get('interno/estado', fn () => new JsonResponse(['ok' => true], 200));

    expect(cuerpoDe($this, '/interno/estado'))->toBe(['ok' => true]);
});

test('CONTROL: sin el flag, la ruta de módulo sigue sin sobre', function () {
    armarMundoDelSobre($this, forzar: false);

    Route::get('api/modulo/pedidos', fn () => new JsonResponse(['data' => ['id' => 9]], 200));

    expect(cuerpoDe($this, '/api/modulo/pedidos'))->not->toHaveKey('success');
});
