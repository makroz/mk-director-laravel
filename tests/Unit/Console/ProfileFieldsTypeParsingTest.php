<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Console;

use Mk\Director\Tests\MkLaravelTestCase;

/**
 * Source-parsing + functional tests para R-PKG-047 D3 — FileStorage auto-wire via :file suffix.
 *
 * Patrón HALLAZGO-NEW-03: source-parsing para pinear INTENCIÓN del auto-wire
 * (detectFileFields + buildFileFieldsConfig helpers + 'file' type en
 * PROFILE_FIELD_TYPES + admin-controller.stub 'plugins' placeholder).
 * EFECTIVIDAD runtime pineada en AuthUserCompleteFlowE2ETest.php (D6 E2E test).
 */
uses(MkLaravelTestCase::class);

function packageRoot047D3(): string
{
    return dirname(__DIR__, 3);
}

function scaffolderSource047D3(): string
{
    return (string) file_get_contents(packageRoot047D3() . '/src/Console/Commands/MakeAuthUserCommand.php');
}

function adminControllerStub047D3(): string
{
    return (string) file_get_contents(
        packageRoot047D3() . '/src/Stubs/auth-user/admin-controller.stub',
    );
}

// ── F3.1 — 'file' type pineado en PROFILE_FIELD_TYPES ─────────────────────

it('D3: PROFILE_FIELD_TYPES incluye \'file\' type pineado', function () {
    $src = scaffolderSource047D3();
    expect($src)->toMatch("/'file'\\s*=>\\s*\\[\\s*'column_method'\\s*=>\\s*'string'/");
});

// ── F3.2 — detectFileFields() helper pineado ──────────────────────────────

it('D3: detectFileFields() helper pineado en el scaffolder', function () {
    expect(scaffolderSource047D3())->toContain('private function detectFileFields(array $profileFields): array');
});

it('D3: buildFileFieldsConfig() helper pineado (map request => column, IDENTITY post-FEEDBACK10)', function () {
    $src = scaffolderSource047D3();
    // El helper puede ser `private` o `protected` (FEEDBACK10 lo pineó
    // como protected para permitir testeo via reflection). Matcheamos
    // cualquiera de los dos.
    expect($src)->toMatch('/function buildFileFieldsConfig\(array \$fileFieldNames\): array/');
    // FEEDBACK10 (R-PKG-050, Mario 2026-07-10): convention es IDENTITY MAP,
    // no sufijo `_path`. La columna ES el field name (`avatar` no `avatar_path`).
    expect($src)->toContain("\$map[\$fieldName] = \$fieldName;");
    expect($src)->not->toContain("\$map[\$fieldName] = \$fieldName . '_path'");
});

// ── F3.3 — admin-controller.stub pino 'plugins' + 'plugins_config' keys ────
// FEEDBACK10 (RETO pilot, 2026-07-13): `plugins` ahora es la LISTA de clases
// (registrada per-controller por CRUDSmart) y la config vive en `plugins_config`
// (key que FileStoragePlugin lee vía getConfigValue('plugins_config.<name>')).

it('FEEDBACK10: admin-controller.stub pino \'plugins\' (lista) + \'plugins_config\' en $mkConfig', function () {
    $stub = adminControllerStub047D3();
    expect($stub)->toContain("'plugins'         => {{pluginsList}}");
    expect($stub)->toContain("'plugins_config'  => {{pluginsConfig}}");
    // La forma vieja conflacionada NO debe quedar.
    expect($stub)->not->toContain("'plugins'         => {{pluginsConfig}}");
});

it('FEEDBACK10: ambos placeholders pineados desde scaffolder con sus helpers', function () {
    $src = scaffolderSource047D3();
    expect($src)->toContain("'{{pluginsList}}' => \$this->buildPluginsListLiteral(");
    expect($src)->toContain("'{{pluginsConfig}}' => \$this->buildPluginsConfigLiteral(");
});

it('FEEDBACK10: buildPluginsListLiteral() registra FileStoragePlugin con file fields, [] sin ellos', function () {
    $src = scaffolderSource047D3();
    // Con file fields → lista con la clase del plugin.
    expect($src)->toContain('\Mk\Director\Plugins\FileStoragePlugin::class');
    // Firma del helper nuevo.
    expect($src)->toMatch('/function buildPluginsListLiteral\(\s*array\s+\$fileFieldNames\s*\)/');
});

// ── F3.4 — :file suffix se auto-detecta en resolveProfileFields() ──────────

it('D3: resolveProfileFields() marca is_file=true cuando type=file', function () {
    $src = scaffolderSource047D3();
    // El helper privado `$isFile` se computa en el loop foreach.
    expect($src)->toContain("\$isFile = \$type === 'file';");
    // El meta pinea 'is_file' => true.
    expect($src)->toContain("'is_file' => \$isFile,");
});
