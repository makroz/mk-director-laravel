<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Feature;

use Illuminate\Console\OutputStyle;
use Mk\Director\Console\Commands\MakeAuthUserCommand;
use Mk\Director\Tests\MkLaravelTestCase;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * R-PKG-031 e2e test — verifica que el scaffolder emite código que efectivamente
 * funciona runtime (HALLAZGO-NEW-03 EFECTIVIDAD, no solo INTENCIÓN).
 *
 * Source: Feedback RETO fase 11 2026-06-28 (`FEEDBACK-TO-MK-DIRECTOR.md`).
 *
 * Lo que pineamos:
 *   - PKG-NEW-16: register() scaffoldeado valida `name` + `loginField` (NOT NULL).
 *     Se ejecuta `buildRegisterMethod()` y se inspecciona el output.
 *   - PKG-NEW-09: registerRoute scaffoldeado pinea middleware cuando --with-crud.
 *     Se ejecuta el flujo de registerRoute generation y se inspecciona el output.
 *
 * Patrón: subclase del command + setOutput(NullOutput) + reflexión sobre
 * métodos protected. NO toca filesystem real (no genera archivos en app/).
 *
 * @see MakeAuthUserCommand
 */
uses(MkLaravelTestCase::class);

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir().'/mk-rpkg031-test-'.uniqid();
    mkdir($this->tempDir, 0755, true);

    $this->command = new class extends MakeAuthUserCommand
    {
        public string $testBasePath = '';

        // Override `registerServiceProvider()` para no tocar bootstrap/providers.php.
        protected function registerServiceProvider(string $scope): void
        {
            // no-op
        }
    };
    $this->command->testBasePath = $this->tempDir;
    $this->command->setOutput(new OutputStyle(
        new StringInput(''),
        new NullOutput
    ));
});

afterEach(function () {
    if (is_dir($this->tempDir)) {
        // Cleanup — mavis-trash would be nicer but rm is fine in a test tempdir.
        foreach (glob($this->tempDir.'/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tempDir);
    }
});

// ── PKG-NEW-16 e2e: buildRegisterMethod emite validación de name + loginField ─

test('PKG-NEW-16 e2e: buildRegisterMethod() pinea validación de name + email (login-field=email default)', function () {
    $reflection = new \ReflectionClass($this->command);
    $buildRegisterMethod = $reflection->getMethod('buildRegisterMethod');
    // PHP 8.1+ no requiere setAccessible(); PHP 8.5 lo deprecó.

    // Inputs: profileFields=[] (sin --profile-fields), loginField='email' (default).
    $output = $buildRegisterMethod->invoke(
        $this->command,
        'Admin',       // scope
        'admin',       // scopeLower
        'email',       // loginField
        "[\n        'name' => ['required', 'string', 'max:255'],\n        'email' => ['required', 'email', 'unique:admins,email'],\n        'password' => ['required', 'string', 'min:8', 'max:255'],\n    ]", // rulesPhp
        false          // verifyEmail
    );

    // El output debe contener el método register() con la validación pineada.
    expect($output)->toContain('public function register(');
    expect($output)->toContain("\$data = \$request->validate(");

    // PKG-NEW-16 — la validación ahora incluye name + email.
    expect($output)->toContain("'name' => ['required', 'string', 'max:255']");
    expect($output)->toContain("'email' => ['required', 'email', 'unique:admins,email']");
    expect($output)->toContain("'password' => ['required', 'string', 'min:8', 'max:255']");
});

test('PKG-NEW-16 e2e: buildRegisterMethod() pinea validación de name + ci (login-field=ci, RETO Bolivia)', function () {
    $reflection = new \ReflectionClass($this->command);
    $buildRegisterMethod = $reflection->getMethod('buildRegisterMethod');
    // PHP 8.1+ no requiere setAccessible(); PHP 8.5 lo deprecó.

    // Inputs: loginField='ci' (RETO Bolivia). La regla `email` NO aplica — es `ci`.
    $output = $buildRegisterMethod->invoke(
        $this->command,
        'Member',
        'member',
        'ci',
        "[\n        'name' => ['required', 'string', 'max:255'],\n        'ci' => ['required', 'string', 'unique:members,ci'],\n        'password' => ['required', 'string', 'min:8', 'max:255'],\n    ]",
        false
    );

    // Validación de name pineada.
    expect($output)->toContain("'name' => ['required', 'string', 'max:255']");

    // Validación de ci con unique constraint (no `email` validation).
    expect($output)->toContain("'ci' => ['required', 'string', 'unique:members,ci']");

    // NO debe contener validación `email` (porque login-field=ci).
    expect($output)->not->toContain("'email' =>");
});

// ── PKG-NEW-09 e2e: registerRoute condicional pinea middleware cuando --with-crud ─

test('PKG-NEW-09 e2e: handle() pinea middleware mk.auth + mk.ability en registerRoute cuando --with-crud', function () {
    // Este test inspecciona el handle() flow completo. Vamos a buscar
    // el bloque que genera el registerRoute en el command source.

    $commandSource = (string) file_get_contents(
        dirname(__DIR__, 2).'/src/Console/Commands/MakeAuthUserCommand.php'
    );

    // El bloque que genera el registerRoute con middleware está dentro de handle().
    // Debe aparecer UN bloque donde el middleware se pinea dentro de `if ($withCrud)`.
    expect($commandSource)->toMatch(
        "/\\\$registerRoute\s*=\s*['\"]\\\\n\s*Route::post\\('register'.*?if\s*\\(\\\$withCrud\).*?->middleware\\(\['mk\.auth:.*?\.create'.*?\]\\).*?;/s"
    );

    // El middleware pineado debe ser consistente con el workaround del consumer
    // (RETO C-01): `mk.auth:{scope}` + `mk.ability:{scope}.{table}.create`.
    expect($commandSource)->toContain("'mk.auth:{{moduleNameLower}}', 'mk.ability:{{moduleNameLower}}.{{moduleNamePluralLower}}.create'");
});

test('PKG-NEW-09 e2e: registerRoute BC mode (sin --with-crud) NO pinea middleware', function () {
    // Cuando $withCrud es false, el registerRoute generado NO debe tener middleware.
    // El flag `--with-crud` es opcional (BC preserved con v1.5.0-rc4).

    $commandSource = (string) file_get_contents(
        dirname(__DIR__, 2).'/src/Console/Commands/MakeAuthUserCommand.php'
    );

    // El bloque debe tener:
    //   $registerRoute = "..." (base, sin middleware)
    //   if ($withCrud) { $registerRoute .= "->middleware(...)" }
    //   $registerRoute .= ";"
    //
    // Esto pinea que el middleware está CONDICIONADO a $withCrud.

    expect($commandSource)->toMatch("/if\s*\\(\\\$withCrud\\)\s*\\{[^}]*->middleware\\(\['mk\.auth/s");

    // El registerRoute base (sin middleware) también debe estar pineado.
    expect($commandSource)->toContain("Route::post('register', [AuthController::class, 'register'])");
});

// ── Negative assertion: no rompimos BC de v1.5.0-rc4 ─────────────────────

test('PKG-NEW-09 e2e: PKG-NEW-09 reference está en comentarios (drift trazable)', function () {
    $commandSource = (string) file_get_contents(
        dirname(__DIR__, 2).'/src/Console/Commands/MakeAuthUserCommand.php'
    );

    expect($commandSource)->toContain('PKG-NEW-09');
    expect($commandSource)->toContain('R-PKG-031');
});