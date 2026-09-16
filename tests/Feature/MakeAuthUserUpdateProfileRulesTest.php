<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Mk\Director\Tests\Concerns\BootsHttpApp;
use Mk\Director\Tests\Concerns\RunsAuthUserScaffolder;
use Mk\Director\Tests\MkLaravelTestCase;

/**
 * `PATCH me` (el `updateProfile()` generado) no puede tocar columnas de control.
 *
 * Medido en el piloto NetPizza, por la cadena HTTP real: un admin BLOQUEADO con
 * un token vivo hizo `PATCH me {status: 1}` → 200, y quedó Activo en la base.
 * La regla generada era `'status' => ['sometimes', 'nullable', 'integer']`.
 * Además el campo de login salía `nullable` (el usuario se deja sin login, o un
 * 500 contra la columna NOT NULL) y, si el login no es `email`, sin `unique`
 * que ignore la fila propia (el CI de otro usuario → violación de unique en la
 * base en vez de un 422).
 *
 * Las reglas se EJECUTAN: se extrae el array de `$request->validate([...])` del
 * controller generado y se corre un Validator de verdad contra la tabla que
 * creó la migración generada. Una aserción sobre el texto no ve si la regla
 * `unique` apunta a la tabla correcta ni si ignora la fila propia.
 */
uses(MkLaravelTestCase::class, BootsHttpApp::class, RunsAuthUserScaffolder::class);

afterEach(function () {
    $this->tearDownHttpApp();
    $this->cleanScaffolderTempDirs();
});

/**
 * Genera el scope, corre su migración, siembra dos usuarios y devuelve el array
 * de reglas de `updateProfile()` evaluado para el PRIMERO.
 *
 * @param  array<string, mixed>  $args
 * @return array{0: array<string, mixed>, 1: string} [reglas, tabla]
 */
function updateProfileRulesFor(object $test, array $args, string $table, string $loginField): array
{
    [$exit, $output, $base] = $test->runScaffolderInTempDir($args);
    expect($exit)->toBe(0, $output);

    $module = "{$base}/app/Modules/{$args['scope']}";
    foreach (glob("{$module}/Database/Migrations/*.php") ?: [] as $migration) {
        (require $migration)->up();
    }

    foreach (['own' => 'propio', 'other' => 'ajeno'] as $id => $value) {
        DB::table($table)->insert(['id' => $id, 'name' => $id, $loginField => $value, 'password' => 'x', 'auth_scope' => strtolower($args['scope'])]);
    }

    $source = (string) file_get_contents("{$module}/Http/Controllers/AuthController.php");
    $method = substr($source, (int) strpos($source, 'function updateProfile('));
    $start = strpos($method, '$request->validate(') + strlen('$request->validate(');
    $depth = 0;
    for ($i = $start; $i < strlen($method); $i++) {
        $depth += ['[' => 1, ']' => -1][$method[$i]] ?? 0;
        if ($depth === 0 && $method[$i] === ']') {
            break;
        }
    }
    $rulesPhp = substr($method, $start, $i - $start + 1);

    // `$user` es la variable que usan las reglas generadas (`->ignore($user->getKey())`).
    $user = new class
    {
        public function getKey(): string
        {
            return 'own';
        }
    };

    return [eval("return {$rulesPhp};"), $table];
}

function profileValidationFails(array $rules, array $data): bool
{
    return Validator::make($data, $rules)->fails();
}

test('email login: status prohibido, login no nulo, único ignorando la fila propia', function () {
    [$rules] = updateProfileRulesFor($this, ['scope' => 'ProfEmail', '--no-crud' => true], 'prof_emails', 'email');

    expect(profileValidationFails($rules, ['status' => 1]))->toBeTrue('status tendría que ser prohibited');
    expect(profileValidationFails($rules, ['email' => null]))->toBeTrue('email null tendría que fallar');
    expect(profileValidationFails($rules, ['email' => 'ajeno@x.test']))->toBeFalse('control: un email libre pasa');
    DB::table('prof_emails')->where('id', 'other')->update(['email' => 'ocupado@x.test']);
    expect(profileValidationFails($rules, ['email' => 'ocupado@x.test']))->toBeTrue('el email de otro tendría que fallar');
    DB::table('prof_emails')->where('id', 'own')->update(['email' => 'mio@x.test']);
    expect(profileValidationFails($rules, ['email' => 'mio@x.test']))->toBeFalse('el email propio tiene que pasar');
    expect(profileValidationFails($rules, ['name' => 'Nuevo', 'phone' => '123']))->toBeFalse('control: editar el perfil pasa');
});

test('--login-field=ci: el CI es obligatorio y único ignorando la fila propia', function () {
    [$rules] = updateProfileRulesFor($this, ['scope' => 'ProfCi', '--no-crud' => true, '--login-field' => 'ci'], 'prof_cis', 'ci');

    expect(profileValidationFails($rules, ['ci' => null]))->toBeTrue('ci null tendría que fallar');
    expect(profileValidationFails($rules, ['ci' => 'ajeno']))->toBeTrue('el ci de otro usuario tendría que fallar (422, no violación de unique)');
    expect(profileValidationFails($rules, ['ci' => 'propio']))->toBeFalse('el ci propio tiene que pasar');
    expect(profileValidationFails($rules, ['ci' => 'nuevo']))->toBeFalse('control: un ci libre pasa');
    expect(profileValidationFails($rules, ['status' => 1]))->toBeTrue('status tendría que ser prohibited');
});

test('--no-status: sin columna, sin regla de status', function () {
    [$rules] = updateProfileRulesFor($this, ['scope' => 'ProfNoStatus', '--no-crud' => true, '--no-status' => true], 'prof_no_statuses', 'email');

    expect($rules)->not->toHaveKey('status');
    expect(profileValidationFails($rules, ['name' => 'Nuevo']))->toBeFalse();
});

test('columnas de control nunca son editables desde el perfil, aunque se declaren como profile fields', function () {
    [$rules] = updateProfileRulesFor($this, [
        'scope' => 'ProfTenant',
        '--no-crud' => true,
        '--multi-tenant' => true,
        '--profile-fields' => 'tenant_id,two_factor_secret,nickname',
    ], 'prof_tenants', 'email');

    foreach (['auth_scope', 'client_id', 'tenant_id', 'password', 'email_verified_at', 'remember_token', 'two_factor_secret'] as $column) {
        expect($rules)->not->toHaveKey($column);
    }
    // Contraprueba: los profile fields comunes SÍ están.
    expect($rules)->toHaveKey('nickname');
    expect($rules['status'])->toBe(['prohibited']);
});
