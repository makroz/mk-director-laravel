<?php

declare(strict_types=1);

use Mk\Director\Auth\Concerns\HasAbilities;

/**
 * Coherencia del método de abilities entre los dos packs de generación.
 *
 * CONTEXTO (regresión real, detectada en un consumer):
 * El pack `auth-user` reusaba `module-rbac/policy-user.stub` para generar
 * `{Scope}Policy`. Ese stub llama a `hasAbility()`, método que define el
 * modelo de `module-rbac` (extiende `Authenticatable`, RBAC módulo-local).
 * Los modelos de `auth-user` extienden `AuthUser`, que expone `canMk()` y NO
 * tiene `hasAbility()`. Resultado: toda policy generada por `--with-crud`
 * quedaba con un `BadMethodCallException` latente.
 *
 * No explotó durante mucho tiempo porque el gate efectivo lo hace el
 * middleware `mk.ability:` per-route y las policies eran código muerto. El
 * primer `Gate::authorize()` sobre esos modelos habría sido un 500.
 *
 * Los tests que existían ANTES aseguraban el bug: verificaban que el stub
 * cruzado estuviera referenciado. Pasaban en verde con el defecto adentro.
 * Estos verifican el CONTRATO — que cada pack use el método que su propio
 * modelo base realmente expone.
 */
function stubPath(string $relative): string
{
    return dirname(__DIR__, 3).'/src/Stubs/'.$relative;
}

function stubSource(string $relative): string
{
    $path = stubPath($relative);

    if (! file_exists($path)) {
        test()->fail("Stub no encontrado: {$path}");
    }

    return (string) file_get_contents($path);
}

// ── El hecho de base: qué expone cada modelo ─────────────────────────────

test('el trait HasAbilities expone canMk() y NO hasAbility()', function () {
    // Se chequea el trait y no `AuthUser`: cargar la clase acá arrastra
    // `Laravel\Sanctum\HasApiTokens`, que no está en el contexto Unit. El
    // trait es igual de autoritativo — es de donde AuthUser saca el método.
    expect(method_exists(HasAbilities::class, 'canMk'))->toBeTrue()
        ->and(method_exists(HasAbilities::class, 'hasAbility'))->toBeFalse();
});

test('el modelo de module-rbac define su propio hasAbility()', function () {
    // Ese pack es autocontenido: extiende Authenticatable, tiene su propio
    // Role model y sus propias pivots. Su hasAbility() es correcto ahí.
    expect(stubSource('module-rbac/model-user.stub'))
        ->toContain('public function hasAbility(string $ability): bool');
});

// ── Pack auth-user: SOLO canMk ───────────────────────────────────────────

test('ningún policy stub de auth-user llama a hasAbility()', function (string $stub) {
    $src = stubSource("auth-user/{$stub}");

    expect($src)->not->toContain('$user->hasAbility(')
        ->and($src)->toContain('$user->canMk(');
})->with(['policy-user.stub', 'policy-role.stub', 'policy-ability.stub']);

test('auth-user tiene su propio policy-user.stub', function () {
    // La causa raíz fue el reuse cross-pack. El stub propio es lo que impide
    // que vuelva a pasar.
    expect(file_exists(stubPath('auth-user/policy-user.stub')))->toBeTrue();
});

test('el generador NO toma el policy-user stub de module-rbac', function () {
    $src = (string) file_get_contents(
        dirname(__DIR__, 3).'/src/Console/Commands/MakeAuthUserCommand.php'
    );

    expect($src)->toContain("'auth-user/policy-user.stub'")
        ->and($src)->not->toContain("'module-rbac/policy-user.stub'");
});

// ── Pack module-rbac: SOLO hasAbility ────────────────────────────────────

test('los policy stubs de module-rbac siguen usando hasAbility()', function (string $stub) {
    // Coherencia inversa: cambiarlos a canMk() los rompería, porque su modelo
    // base NO usa el trait HasAbilities del paquete.
    $src = stubSource("module-rbac/{$stub}");

    expect($src)->toContain('$user->hasAbility(')
        ->and($src)->not->toContain('$user->canMk(');
})->with(['policy-user.stub', 'policy-role.stub', 'policy-ability.stub']);

// ── Colisión de Gate::policy sobre modelos centrales ─────────────────────

test('el generador cede el registro de Role/Ability al primer scope manager', function () {
    $src = (string) file_get_contents(
        dirname(__DIR__, 3).'/src/Console/Commands/MakeAuthUserCommand.php'
    );

    // `Role`/`Ability` son una sola clase compartida. Dos scopes manager
    // registrando Gate::policy sobre ella no conviven: gana el que bootea
    // último y el before() de la policy ganadora typehintea SU modelo, así que
    // un actor del otro scope entra como tipo incompatible → TypeError.
    expect($src)->toContain('protected function centralPolicyOwner(string $basePath, string $scope): ?string')
        ->and($src)->toContain('$owner = $this->centralPolicyOwner($basePath, $scope);');
});
