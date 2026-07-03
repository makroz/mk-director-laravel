<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Console;

use Mk\Director\Tests\MkLaravelTestCase;

/**
 * Source-parsing tests for R-PKG-014 MEJORA-04 — `mk:auth:create-super-admin --roles`.
 *
 * Pinea que el flag --roles acepta CSV de roles y siembra los 4 predefinidos.
 */
uses(MkLaravelTestCase::class);

function packageRootRoles014(): string
{
    return dirname(__DIR__, 3);
}

test('command signature incluye --roles option', function () {
    $path = packageRootRoles014().'/src/Console/Commands/AuthCreateSuperAdminCommand.php';
    $source = (string) file_get_contents($path);

    expect($source)->toMatch('/--roles=\s*:/');
});

test('command tiene roleAbilitiesMap() con los 4 roles predefinidos', function () {
    $path = packageRootRoles014().'/src/Console/Commands/AuthCreateSuperAdminCommand.php';
    $source = (string) file_get_contents($path);

    expect($source)->toContain('protected function roleAbilitiesMap');

    // Verifica que los 4 roles están en el map.
    expect($source)->toContain("'super-admin'");
    expect($source)->toContain("'admin'");
    expect($source)->toContain("'editor'");
    expect($source)->toContain("'viewer'");
});

test('super-admin tiene ability wildcard *', function () {
    $path = packageRootRoles014().'/src/Console/Commands/AuthCreateSuperAdminCommand.php';
    $source = (string) file_get_contents($path);

    // super-admin -> ['*']
    expect($source)->toMatch("/'super-admin'\s*=>\s*\[\s*'\*'\s*\]/");
});

test('admin tiene CRUD completo de admins', function () {
    $path = packageRootRoles014().'/src/Console/Commands/AuthCreateSuperAdminCommand.php';
    $source = (string) file_get_contents($path);

    // Verificamos que admin tiene los 5 abilities esperados. La source tiene
    // interpolación PHP (e.g. "{$scope}.{$resource}.viewAny") así que usamos
    // el final del nombre como substring (".viewAny", ".view", etc).
    expect($source)->toContain("'admin' => [");

    $adminPos = strpos($source, "'admin' => [");
    $editorPos = strpos($source, "'editor' => [");
    $adminBlock = substr($source, (int) $adminPos, (int) ($editorPos - $adminPos));

    // Las abilities usan interpolación PHP (e.g. "{$scope}.{$resource}.viewAny")
// que se expande a "admin.admins.viewAny" en runtime. Para pinear el source
// sin que PHP expanda las variables en el string de búsqueda, usamos
// concat con chr(123)/chr(125) para emular { y }.
$openBrace = chr(123);
$closeBrace = chr(125);
foreach ([
    $openBrace.'$scope'.$closeBrace.'.'.$openBrace.'$resource'.$closeBrace.'.viewAny',
    $openBrace.'$scope'.$closeBrace.'.'.$openBrace.'$resource'.$closeBrace.'.view',
    $openBrace.'$scope'.$closeBrace.'.'.$openBrace.'$resource'.$closeBrace.'.create',
    $openBrace.'$scope'.$closeBrace.'.'.$openBrace.'$resource'.$closeBrace.'.update',
    $openBrace.'$scope'.$closeBrace.'.'.$openBrace.'$resource'.$closeBrace.'.delete',
] as $abilitySuffix) {
    expect($adminBlock)->toContain($abilitySuffix);
}
});

test('editor no tiene delete ni create', function () {
    $path = packageRootRoles014().'/src/Console/Commands/AuthCreateSuperAdminCommand.php';
    $source = (string) file_get_contents($path);

    // Extraemos el bloque entre 'editor' => [ y el siguiente role ('viewer' => [).
    $editorStart = strpos($source, "'editor' => [");
    $viewerPos = strpos($source, "'viewer' => [");
    expect($editorStart)->toBeInt();
    expect($viewerPos)->toBeInt();

    $editorBlock = substr($source, (int) $editorStart, (int) ($viewerPos - $editorStart));

    expect($editorBlock)->not->toContain('.delete');
    expect($editorBlock)->not->toContain('.create');
});

test('viewer solo tiene view abilities', function () {
    $path = packageRootRoles014().'/src/Console/Commands/AuthCreateSuperAdminCommand.php';
    $source = (string) file_get_contents($path);

    $viewerStart = strpos($source, "'viewer' => [");
    $returnPos = strpos($source, '];', (int) $viewerStart);
    expect($viewerStart)->toBeInt();
    expect($returnPos)->toBeInt();

    $viewerBlock = substr($source, (int) $viewerStart, (int) ($returnPos - $viewerStart));

    expect($viewerBlock)->toContain('viewAny');
    expect($viewerBlock)->toContain('.view"');
    expect($viewerBlock)->not->toContain('.create');
    expect($viewerBlock)->not->toContain('.update');
    expect($viewerBlock)->not->toContain('.delete');
});

test('handle() itera sobre $rolesToSeed y asigna roles + abilities', function () {
    $path = packageRootRoles014().'/src/Console/Commands/AuthCreateSuperAdminCommand.php';
    $source = (string) file_get_contents($path);

    // Verifica que handle() parsea el CSV de --roles.
    expect($source)->toMatch('/explode\(\s*\'\s*,\s*\',\s*\$rolesRaw\s*\)/');

    // Verifica que itera asignando role + abilities.
    expect($source)->toMatch('/foreach\s*\(\s*\$rolesToSeed\s+as\s+\$roleName\s*\)\s*\{[^}]*assignRole/s');
});