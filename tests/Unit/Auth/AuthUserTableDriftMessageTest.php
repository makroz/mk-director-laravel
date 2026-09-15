<?php

declare(strict_types=1);

use Mk\Director\Auth\Models\AuthUser;
use Mk\Director\Tests\MkLaravelTestCase;

/**
 * El error de "no pineaste $table" no puede AFIRMAR cuál es la tabla.
 *
 * Adivinaba `snake(pluralStudly(clase))`: para `Operador` decía «pineá
 * `operadors`», mientras la migración del scope (generada con
 * `--plural=operadores`) crea `operadores`. Un mensaje de error que manda a
 * escribir la tabla equivocada es peor que uno que no dice nada. Y recomendaba
 * `--with-crud --force`, dos flags que el comando ya no tiene.
 */
uses(MkLaravelTestCase::class);

final class OperadorSinTable extends AuthUser {}

test('el mensaje no afirma una tabla: nombra el default y avisa de --plural', function () {
    try {
        new OperadorSinTable;
        $message = null;
    } catch (LogicException $e) {
        $message = $e->getMessage();
    }

    expect($message)->not->toBeNull();
    expect($message)->not->toContain('protected $table = "operador_sin_tables"');
    expect($message)->toContain('--plural=');
    expect($message)->not->toContain('--with-crud --force');
});
