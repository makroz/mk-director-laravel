<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit;

/**
 * Guard-rail: `not->toThrow` no vuelve a entrar a esta suite.
 *
 * 🔴 POR QUÉ. `toThrow` de Pest matchea por CLASE EXACTA, no por `instanceof`.
 * Medido en este repo:
 *
 *   expect(fn () => throw new RuntimeException('x'))->toThrow(Throwable::class)          → FALLA
 *   expect(fn () => throw new RuntimeException('x'))->not->toThrow(Throwable::class)     → PASA
 *   expect(fn () => throw new RuntimeException('x'))->not->toThrow(LogicException::class) → PASA
 *
 * De ahí salen dos trampas, y las dos estaban puestas en esta suite:
 *
 *  1. Con una clase PADRE (o `Throwable`, que es una interfaz y por lo tanto
 *     nada es "exactamente" eso), la negación NO PUEDE FALLAR NUNCA. Se lee
 *     como "esto no explota" y no comprueba absolutamente nada.
 *  2. Con la clase exacta sí mide, pero se TRAGA cualquier otra excepción. El
 *     test sigue verde jurando "pasa sin excepción" mientras el código revienta
 *     por otro lado. Comprobado inyectando un `LogicException`: la aserción
 *     vieja daba verde, la llamada pelada da rojo.
 *
 * LA FORMA CORRECTA es la más simple: llamar al método pelado. Si tira, el
 * test falla solo, con el stack de verdad. Y después afirmar que además HIZO
 * lo suyo — no explotar es la mitad del contrato.
 *
 * 🔴 SÍ, ESTE TEST LEE CÓDIGO FUENTE COMO TEXTO, que es justo lo que se le
 * critica a otros tests de esta suite. La diferencia no es menor: un LINT
 * tiene como SUJETO el texto del código. No usa el texto como sustituto pobre
 * del comportamiento — el texto ES lo que quiere prohibir.
 */
it('ningún test usa not->toThrow, que no afirma lo que parece', function () {
    $raiz = dirname(__DIR__);

    $infractores = [];

    $archivos = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($raiz));
    foreach ($archivos as $archivo) {
        if (! $archivo->isFile() || $archivo->getExtension() !== 'php') {
            continue;
        }
        if ($archivo->getFilename() === basename(__FILE__)) {
            continue;
        }

        foreach (file($archivo->getPathname()) as $n => $linea) {
            // Sólo código: una línea que empieza con comentario está
            // explicando el problema, no cometiéndolo. Este archivo mismo
            // nombra `not->toThrow` media docena de veces.
            $limpia = ltrim($linea);
            if (str_starts_with($limpia, '//') || str_starts_with($limpia, '*')) {
                continue;
            }

            if (str_contains($linea, 'not->toThrow')) {
                $infractores[] = str_replace($raiz.'/', '', $archivo->getPathname()).':'.($n + 1);
            }
        }
    }

    expect($infractores)->toBe([], implode("\n", array_merge(
        ['`not->toThrow` no afirma que algo no lanza. Reemplazalo por la llamada pelada'],
        ['más una aserción de lo que el método TENÍA que hacer. Ver el docblock de'],
        [basename(__FILE__).'. Infractores:'],
        $infractores,
    )));
});
