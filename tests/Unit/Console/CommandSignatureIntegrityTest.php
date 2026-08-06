<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Console;

use Illuminate\Console\Command;
use Mk\Director\Tests\MkLaravelTestCase;
use ReflectionClass;

/**
 * Guard-rail transversal sobre las firmas (`$signature`) de TODOS los comandos
 * del paquete.
 *
 * Causa raíz que este test pinea: `Illuminate\Console\Parser` extrae los tokens
 * de la firma con una regex sobre `{...}`. NO distingue entre una llave que
 * abre un token real y una llave literal escrita DENTRO del texto de una
 * descripción. Si una descripción dice, por ejemplo, `/api/{manager}/{consumers}`,
 * el parser declara `manager` y `consumers` como ARGUMENTOS POSICIONALES
 * REQUERIDOS del comando — y encima trunca la descripción en la primera llave.
 *
 * Síntoma real (2026-08): `php artisan mk:make:auth-user Admin` moría con
 * `Not enough arguments (missing: "consumers")`.
 *
 * Dos aserciones, la segunda ataca la causa raíz de forma general:
 *  1. Cada comando declara EXACTAMENTE los argumentos esperados (lista explícita).
 *  2. Ninguna descripción dentro de `$signature` contiene llaves literales.
 */
uses(MkLaravelTestCase::class);

/**
 * Contrato explícito: comando => argumentos posicionales esperados, en orden.
 *
 * Si agregás un comando nuevo, sumalo acá. Si un comando aparece con
 * argumentos que no declaraste, es casi seguro una llave literal filtrada
 * en una descripción.
 *
 * @return array<string, list<string>>
 */
function mkExpectedCommandArguments(): array
{
    return [
        'mk:auth:create-super-admin' => [],
        'mk:discover-abilities' => [],
        'mk:dto' => ['name'],
        'mk:fix:sanctum-uuids' => [],
        'mk:generate-docs' => [],
        'mk:lint:boundaries' => [],
        'mk:make:auth-user' => ['scope'],
        'mk:migrate-is-active' => ['scope'],
        'mk:migrate-status-to-int' => ['scope'],
        'mk:module' => ['name'],
        'mk:prune-abilities' => [],
        'mk:security-lint' => [],
        'mk:service' => ['name'],
        'mk:skill:deploy' => ['name'],
        'mk:skill:list' => [],
        'mk:status' => [],
        'mk:update' => ['version'],
    ];
}

/**
 * Descubre todas las clases de comando del paquete por filesystem, para que
 * un comando nuevo entre al guard-rail sin que nadie tenga que acordarse.
 *
 * @return list<class-string<Command>>
 */
function mkAllCommandClasses(): array
{
    $dir = dirname(__DIR__, 3).'/src/Console/Commands';
    $classes = [];

    foreach ((array) glob($dir.'/*.php') as $file) {
        $class = 'Mk\\Director\\Console\\Commands\\'.basename((string) $file, '.php');

        if (! class_exists($class)) {
            continue;
        }

        $reflection = new ReflectionClass($class);

        if ($reflection->isAbstract() || ! $reflection->isSubclassOf(Command::class)) {
            continue;
        }

        $classes[] = $class;
    }

    sort($classes);

    return $classes;
}

/**
 * Extrae los bloques `{...}` de PRIMER NIVEL de una firma, respetando
 * profundidad. Laravel usa una regex non-greedy que no entiende anidamiento;
 * acá sí lo entendemos, justamente para poder DETECTAR el anidamiento.
 *
 * @return array{blocks: list<string>, maxDepth: int, balanced: bool}
 */
function mkParseSignatureBlocks(string $signature): array
{
    $blocks = [];
    $depth = 0;
    $maxDepth = 0;
    $balanced = true;
    $buffer = '';

    foreach (str_split($signature) as $char) {
        if ($char === '{') {
            $depth++;
            $maxDepth = max($maxDepth, $depth);

            if ($depth === 1) {
                $buffer = '';

                continue;
            }
        }

        if ($char === '}') {
            $depth--;

            if ($depth < 0) {
                $balanced = false;
                $depth = 0;

                continue;
            }

            if ($depth === 0) {
                $blocks[] = $buffer;

                continue;
            }
        }

        if ($depth >= 1) {
            $buffer .= $char;
        }
    }

    if ($depth !== 0) {
        $balanced = false;
    }

    return ['blocks' => $blocks, 'maxDepth' => $maxDepth, 'balanced' => $balanced];
}

test('todo comando del paquete declara exactamente los argumentos esperados', function () {
    $expected = mkExpectedCommandArguments();
    $actual = [];

    foreach (mkAllCommandClasses() as $class) {
        $command = new $class;

        $actual[(string) $command->getName()] = array_values(array_map(
            fn ($argument) => $argument->getName(),
            $command->getDefinition()->getArguments()
        ));
    }

    ksort($actual);
    ksort($expected);

    // Comparación total: atrapa tanto argumentos fantasma (llaves literales en
    // una descripción) como comandos nuevos sin entrada en el contrato.
    expect($actual)->toBe($expected);
});

test('ninguna descripcion de la firma contiene llaves literales', function () {
    $offenders = [];

    foreach (mkAllCommandClasses() as $class) {
        $reflection = new ReflectionClass($class);

        if (! $reflection->hasProperty('signature')) {
            continue;
        }

        $signature = (string) ($reflection->getDefaultProperties()['signature'] ?? '');

        if ($signature === '') {
            continue;
        }

        $parsed = mkParseSignatureBlocks($signature);

        if (! $parsed['balanced']) {
            $offenders[] = $reflection->getShortName().': llaves desbalanceadas en $signature';
        }

        if ($parsed['maxDepth'] > 1) {
            $offenders[] = $reflection->getShortName().': llaves anidadas en $signature (profundidad '.$parsed['maxDepth'].')';
        }

        foreach ($parsed['blocks'] as $block) {
            // Un token real es `nombre` o `nombre : descripcion`. Todo lo que
            // venga después del primer ` : ` es texto libre y NO puede traer
            // llaves: el parser de Laravel las tomaría como tokens de firma.
            $parts = explode(' : ', $block, 2);

            if (! isset($parts[1])) {
                continue;
            }

            $token = trim($parts[0]);
            $description = $parts[1];

            if (str_contains($description, '{') || str_contains($description, '}')) {
                $offenders[] = $reflection->getShortName().' → `'.$token.'`: la descripcion contiene llaves literales (usar <X> en vez de {X})';
            }
        }
    }

    expect($offenders)->toBe([]);
});
