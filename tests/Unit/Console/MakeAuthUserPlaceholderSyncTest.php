<?php

declare(strict_types=1);

use Mk\Director\Tests\MkLaravelTestCase;

/**
 * Los placeholders de `mk:make:auth-user` y los de sus stubs son el MISMO
 * conjunto.
 *
 * Dos formas de estar roto, y ninguna da error:
 *
 *  - Un placeholder que el comando arma y ningún stub usa es código muerto que
 *    se lee como vivo. Pasó: `rbacAudit*`, `verifyEmailMethods`,
 *    `registerVerifyEmailDispatch` y `rbacAuthorizeAbilityMethod` se seguían
 *    construyendo —con comentarios explicando lo que emitían— años después de
 *    que los stubs los dejaran de usar. Y uno de ellos, citado en un docblock de
 *    stub, se expandió y rompió el controller generado.
 *  - Un `{{placeholder}}` en un stub sin reemplazo sale LITERAL en el código del
 *    consumer: un parse error, o peor, un string que parece valor.
 *
 * Los dos conjuntos se DERIVAN (tokens del comando, texto de los stubs); nada
 * está escrito a mano acá, así que un placeholder nuevo de cualquier lado entra
 * solo en la comparación.
 */
uses(MkLaravelTestCase::class);

/** @return array<int, string> nombres de los `'{{x}}'` que el comando usa como string (no en comentarios) */
function placeholdersBuiltByCommand(): array
{
    $source = (string) file_get_contents(dirname(__DIR__, 3).'/src/Console/Commands/MakeAuthUserCommand.php');
    $names = [];
    foreach (token_get_all($source) as $token) {
        if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING && preg_match('/^[\'"]\{\{(\w+)\}\}[\'"]$/', $token[1], $m)) {
            $names[$m[1]] = true;
        }
    }

    return array_keys($names);
}

/** @return array<int, string> rutas (relativas a `src/Stubs`) de los stubs que el comando nombra */
function stubsReferencedByCommand(): array
{
    $source = (string) file_get_contents(dirname(__DIR__, 3).'/src/Console/Commands/MakeAuthUserCommand.php');
    $stubs = [];
    foreach (token_get_all($source) as $token) {
        if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING && preg_match('#^[\'"](?:/\.\./\.\./Stubs/)?([\w./-]+\.stub)[\'"]$#', $token[1], $m)) {
            $stubs[$m[1]] = true;
        }
    }

    return array_keys($stubs);
}

/** @return array<int, string> nombres de todos los `{{x}}` de los stubs que el comando usa */
function placeholdersUsedByStubs(): array
{
    $root = dirname(__DIR__, 3).'/src/Stubs';
    $names = [];
    foreach (stubsReferencedByCommand() as $relative) {
        $stub = "{$root}/{$relative}";
        preg_match_all('/\{\{(\w+)\}\}/', (string) file_get_contents($stub), $m);
        foreach ($m[1] as $name) {
            $names[$name] = true;
        }
    }

    return array_keys($names);
}

test('autoprueba: los dos lados se leyeron (sin esto, dos conjuntos vacíos serían "iguales")', function () {
    expect(placeholdersBuiltByCommand())->toContain('ModuleName', 'loginField', 'searchableColumns');
    expect(placeholdersUsedByStubs())->toContain('ModuleName', 'loginField', 'searchableColumns');
});

test('todo placeholder que arma el comando lo usa algún stub (no hay reemplazos muertos)', function () {
    $dead = array_values(array_diff(placeholdersBuiltByCommand(), placeholdersUsedByStubs()));
    sort($dead);

    expect($dead)->toBe([], 'reemplazos que ningún stub usa: '.implode(', ', $dead));
});

test('todo {{placeholder}} de un stub tiene reemplazo en el comando (nada sale literal)', function () {
    $missing = array_values(array_diff(placeholdersUsedByStubs(), placeholdersBuiltByCommand()));
    sort($missing);

    expect($missing)->toBe([], 'placeholders sin reemplazo: '.implode(', ', $missing));
});

test('todo stub del directorio auth-user lo genera el comando (no hay plantillas huérfanas)', function () {
    // Pasó: `auth-user/assign-access-request.stub` existía, el controller
    // generado importa `AssignAccessRequest`, y el comando nunca lo generaba —
    // `POST /{id}/access` moría con «Class not found». RETO y NetPizza lo
    // escribieron a mano.
    $root = dirname(__DIR__, 3).'/src/Stubs';
    $onDisk = array_map(
        fn (string $path) => substr($path, strlen($root) + 1),
        array_merge(glob("{$root}/auth-user.*.stub") ?: [], glob("{$root}/auth-user/*.stub") ?: []),
    );

    $orphans = array_values(array_diff($onDisk, stubsReferencedByCommand()));
    sort($orphans);

    expect($orphans)->toBe([], 'stubs que el comando nunca genera: '.implode(', ', $orphans));
});
