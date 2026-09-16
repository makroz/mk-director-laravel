<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Mk\Director\Auth\Services\TotpService;
use Mk\Director\Tests\MkLaravelTestCase;

/**
 * La aritmética del segundo factor, medida contra los vectores de la RFC 6238.
 *
 * 🔴 POR QUÉ CONTRA LOS VECTORES Y NO CONTRA SÍ MISMA.
 *
 * Un test que genera el código con `codeAt()` y después lo valida con
 * `verify()` pasa en verde con la implementación entera mal: si el contador se
 * arma con el timestamp en vez de con el paso, las dos mitades se equivocan
 * igual y coinciden. Lo único que mide de verdad es un código que produjo OTRO
 * implementador — la tabla del apéndice B de la RFC, con su secreto ASCII
 * `12345678901234567890` (SHA1, T0=0, paso de 30 s).
 *
 * Los códigos de la RFC son de 8 dígitos y acá se emiten de 6: son los 6
 * últimos del mismo valor (el truncado dinámico es el mismo; los dígitos salen
 * de un módulo 10^n).
 */
uses(MkLaravelTestCase::class);

/** El secreto de la RFC (20 bytes ASCII) en base32, que es como lo guarda el paquete. */
const RFC6238_SECRET_BASE32 = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

function totp(): TotpService
{
    return new TotpService;
}

test('base32: el secreto de la RFC decodifica a los 20 bytes ASCII, y vuelve', function () {
    expect(TotpService::base32Decode(RFC6238_SECRET_BASE32))->toBe('12345678901234567890');
    expect(TotpService::base32Encode('12345678901234567890'))->toBe(RFC6238_SECRET_BASE32);
});

test('base32: tolera minúsculas, espacios y el padding que pega un usuario al copiar', function () {
    expect(TotpService::base32Decode('gezdgnbv gy3tqojq=='))->toBe(TotpService::base32Decode('GEZDGNBVGY3TQOJQ'));
});

test('VECTORES RFC 6238: el código de cada instante es el de la tabla', function (int $timestamp, string $expected) {
    $step = intdiv($timestamp, 30);

    expect(totp()->codeAt(RFC6238_SECRET_BASE32, $step))->toBe($expected);
})->with([
    // [segundos unix, los 6 últimos dígitos del TOTP de 8 de la RFC]
    'T=59 (94287082)' => [59, '287082'],
    'T=1111111109 (07081804)' => [1111111109, '081804'],
    'T=1111111111 (14050471)' => [1111111111, '050471'],
    'T=1234567890 (89005924)' => [1234567890, '005924'],
    'T=2000000000 (69279037)' => [2000000000, '279037'],
    'T=20000000000 (65353130)' => [20000000000, '353130'],
]);

test('el código lleva ceros a la izquierda: seis caracteres SIEMPRE', function () {
    // Sin el `str_pad`, un valor como 5924 sale con 4 caracteres y el front
    // compara contra '005924' pidiendo un código que el server nunca emite.
    foreach (range(1, 400) as $step) {
        expect(strlen(totp()->codeAt(RFC6238_SECRET_BASE32, $step)))->toBe(6);
    }
});

test('currentStep divide por 30 el reloj, no lo copia', function () {
    expect(totp()->currentStep(1111111109))->toBe(37037036);
    expect(totp()->currentStep(59))->toBe(1);
    expect(totp()->currentStep(29))->toBe(0);
});

test('🔴 el reloj sale de now(), no de time(): un test tiene que poder congelarlo', function () {
    // Sin esto, un test del flujo completo es flaky por diseño: entre sellar el
    // paso aceptado y pedir el código siguiente puede cruzarse el borde de los
    // 30 segundos, y el código calculado queda fuera de la ventana. El rojo no
    // vuelve a aparecer al correr el archivo solo, que es lo peor que le puede
    // pasar a un test.
    Carbon::setTestNow(Carbon::createFromTimestamp(1111111109));

    try {
        expect(totp()->currentStep())->toBe(37037036);
        expect(totp()->codeAt(RFC6238_SECRET_BASE32, totp()->currentStep()))->toBe('081804');
        // Y con el reloj quieto, el mismo código sigue siendo el de ahora un
        // minuto «después» de la primera lectura.
        Carbon::setTestNow(Carbon::createFromTimestamp(1111111109));
        expect(totp()->verify(RFC6238_SECRET_BASE32, '081804'))->toBe(37037036);
    } finally {
        Carbon::setTestNow();
    }
});

test('VENTANA: entra el paso anterior, el actual y el siguiente; el de más allá no', function () {
    $service = totp();
    $now = 1111111109;      // paso 37037036
    $step = $service->currentStep($now);

    foreach ([-1, 0, 1] as $offset) {
        $code = $service->codeAt(RFC6238_SECRET_BASE32, $step + $offset);
        expect($service->verify(RFC6238_SECRET_BASE32, $code, null, $now))
            ->toBe($step + $offset, "el offset {$offset} tendría que entrar");
    }

    foreach ([-2, 2] as $offset) {
        $code = $service->codeAt(RFC6238_SECRET_BASE32, $step + $offset);
        expect($service->verify(RFC6238_SECRET_BASE32, $code, null, $now))
            ->toBeNull("el offset {$offset} está fuera de la ventana y entró");
    }
});

test('REPLAY: un paso ya aceptado no se acepta de nuevo, ni uno anterior', function () {
    $service = totp();
    $now = 1111111109;
    $step = $service->currentStep($now);
    $code = $service->codeAt(RFC6238_SECRET_BASE32, $step);

    // Sin `lastStep` entra (es el mismo código de la línea de abajo: lo único
    // que cambia entre las dos aserciones es el último paso aceptado).
    expect($service->verify(RFC6238_SECRET_BASE32, $code, null, $now))->toBe($step);

    expect($service->verify(RFC6238_SECRET_BASE32, $code, $step, $now))->toBeNull();

    // Y el anterior de la ventana también queda quemado: es un paso más viejo.
    $previous = $service->codeAt(RFC6238_SECRET_BASE32, $step - 1);
    expect($service->verify(RFC6238_SECRET_BASE32, $previous, $step, $now))->toBeNull();

    // El siguiente sí: es un paso posterior al último aceptado.
    $next = $service->codeAt(RFC6238_SECRET_BASE32, $step + 1);
    expect($service->verify(RFC6238_SECRET_BASE32, $next, $step, $now))->toBe($step + 1);
});

test('un código que no es el del secreto no entra, y la basura tampoco', function () {
    $service = totp();
    $now = 1111111109;

    expect($service->verify(RFC6238_SECRET_BASE32, '000000', null, $now))->toBeNull();
    expect($service->verify(RFC6238_SECRET_BASE32, '', null, $now))->toBeNull();
    expect($service->verify(RFC6238_SECRET_BASE32, 'abcdef', null, $now))->toBeNull();
    // Un secreto vacío no puede validar nada (un scope sin enrolar).
    expect($service->verify('', '287082', null, 59))->toBeNull();
});

test('el código se compara con hash_equals, no con == (un 0e… no matchea otro)', function () {
    // `'0e123' == '0e456'` es TRUE en PHP: dos strings numéricos en notación
    // científica se comparan como números. Un código de 6 dígitos que arranque
    // con `0e` no existe (son dígitos), pero el `==` también hace que '287082'
    // y ' 287082' o '287082.0' coincidan. La comparación va estricta.
    $source = (string) file_get_contents(dirname(__DIR__, 3).'/src/Auth/Services/TotpService.php');

    expect($source)->toContain('hash_equals');
});

test('el secreto generado son 20 bytes al azar en base32, y no se repite', function () {
    $service = totp();
    $secret = $service->generateSecret();

    expect($secret)->toMatch('/^[A-Z2-7]{32}$/');
    expect(strlen(TotpService::base32Decode($secret)))->toBe(20);
    expect($service->generateSecret())->not->toBe($secret);
});

test('la URI otpauth lleva el secreto, el emisor y los parámetros del algoritmo', function () {
    $uri = totp()->otpauthUri(RFC6238_SECRET_BASE32, 'mesero@pizzeria.test', 'NetPizza Consola');

    expect($uri)->toStartWith('otpauth://totp/');
    expect($uri)->toContain('secret='.RFC6238_SECRET_BASE32);
    expect($uri)->toContain('algorithm=SHA1');
    expect($uri)->toContain('digits=6');
    expect($uri)->toContain('period=30');
    // El emisor va en la etiqueta Y en el parámetro: hay apps que leen uno y
    // apps que leen el otro.
    expect($uri)->toContain('issuer=NetPizza%20Consola');
    expect($uri)->toContain('NetPizza%20Consola:mesero%40pizzeria.test');
});

test('los códigos de recuperación: ocho, distintos, y guardados sólo hasheados', function () {
    $service = totp();
    $plain = $service->generateRecoveryCodes();

    expect($plain)->toHaveCount(8);
    expect(array_unique($plain))->toHaveCount(8);
    foreach ($plain as $code) {
        expect($code)->toMatch('/^[0-9a-f]{10}$/');
    }

    $hashes = $service->hashRecoveryCodes($plain);
    expect($hashes)->toHaveCount(8);
    foreach ($plain as $code) {
        expect($hashes)->not->toContain($code);
    }
});

test('un código de recuperación se gasta UNA vez y se va de la lista', function () {
    $service = totp();
    $plain = $service->generateRecoveryCodes();
    $hashes = $service->hashRecoveryCodes($plain);

    $remaining = $service->consumeRecoveryCode($hashes, $plain[3]);
    expect($remaining)->toBeArray();
    expect($remaining)->toHaveCount(7);

    // El mismo código contra la lista que quedó: ya no está.
    expect($service->consumeRecoveryCode($remaining, $plain[3]))->toBeNull();

    // Los otros siete siguen sirviendo.
    expect($service->consumeRecoveryCode($remaining, $plain[0]))->toHaveCount(6);
});

test('🔴 los códigos de recuperación son STRINGS, siempre — incluso el que sale todo en dígitos', function () {
    // El bug que esto pinea: deduplicar usando el código como CLAVE de un array
    // hace que PHP convierta a `int` toda clave que sea un string numérico. Un
    // código de 10 caracteres hexa sale todo en dígitos ~1 vez de cada 100, o
    // sea 7 de cada 100 tandas de ocho: el response entregaba un número donde el
    // contrato dice string, y el test daba un rojo intermitente.
    //
    // 300 tandas son 2.400 códigos: con esa cantidad, que aparezca al menos uno
    // todo en dígitos es una certeza práctica (y corre en milisegundos). Un
    // `expect` sobre una sola tanda sería el mismo rojo intermitente al revés.
    $service = totp();
    $allDigits = 0;

    foreach (range(1, 300) as $ignored) {
        foreach ($service->generateRecoveryCodes() as $code) {
            expect($code)->toBeString();
            expect($code)->toMatch('/^[0-9a-f]{10}$/');

            if (ctype_digit($code)) {
                $allDigits++;
            }
        }
    }

    // Contraprueba: si no apareció ninguno todo en dígitos, este test no midió
    // el caso que tiene que medir y hay que subir las tandas.
    expect($allDigits)->toBeGreaterThan(0, 'no salió ningún código todo en dígitos: el test no midió nada');
});

test('un código de recuperación inventado no gasta nada', function () {
    $service = totp();
    $hashes = $service->hashRecoveryCodes($service->generateRecoveryCodes());

    expect($service->consumeRecoveryCode($hashes, 'deadbeef00'))->toBeNull();
    expect($service->consumeRecoveryCode($hashes, ''))->toBeNull();
    expect($service->consumeRecoveryCode([], 'deadbeef00'))->toBeNull();
});
