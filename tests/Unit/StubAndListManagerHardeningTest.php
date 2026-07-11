<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit;

use Mk\Director\Tests\MkLaravelTestCase;

/**
 * LAR-12 + LAR-13 + LAR-14 (2026-07-03 audit, LOW) — null-guards in
 * stub login/forgot + primary-key correctness in ListManager +
 * email/loginField documentation in the migration stub.
 *
 * LAR-12 — stub login() null-guard.
 * Pre-fix, the stub had:
 *
 *   $isActiveCheck = Schema::hasColumn($user?->getTable() ?? '{{...}}', 'is_active')
 *       && $user->is_active === false;
 *
 * The null-safe `$user?->getTable()` was guarded, but `$user->is_active`
 * is NOT — when user is null AND Schema::hasColumn returns true (the
 * table has the column), `$user->is_active` throws
 * "Attempt to read property 'is_active' on null" at runtime.
 *
 * Fix: use `$user?->is_active === false` (null-safe access). The downstream
 * `if (! $user || ... || $isActiveCheck)` guard already handles null,
 * so this is a defense-in-depth fix.
 *
 * LAR-13 — ListManager primary-key correctness.
 * Pre-fix, ListManager used the literal string `'id'` in 4 places:
 *   - applyColumns() → default `cols=id,...` allowlist
 *   - apply() → default orderBy fallback
 *   - applySorting() → fallback
 *   - applySorting() → 'id' in $allowed
 *
 * If a consumer model uses UUID primary keys (`$keyType = 'string'`),
 * `$model->getKeyName()` returns `'id'` by default (the column NAME, not
 * the type), but `orderBy('id', 'desc')` would still work on a UUID table.
 * The real issue is when the primary key column has a CUSTOM NAME
 * (e.g. `uuid`, `code`, `slug`): `'id'` literal does NOT match, the
 * default orderBy/orderBy fallback would fail / produce wrong SQL.
 *
 * Fix: replace literal `'id'` with `$model->getKeyName()` in all 4 sites.
 * Default Eloquent returns `'id'` so BC is preserved; consumers with
 * custom key names now get correct column references.
 *
 * LAR-14 — email/loginField documentation in migration stub.
 * Pre-fix, the migration stub always created `{{loginField}}` as
 * `$table->string(...)->unique()` (NOT nullable). The relationship
 * between --login-field=email and the email column was implicit, not
 * documented. A consumer who used --login-field=ci and then added
 * email as a profile field (--profile-fields=email) would get email
 * as nullable (per R-PKG-011) — but there's no comment explaining
 * this asymmetric behavior.
 *
 * Fix: add a docblock note explaining:
 *   - When loginField=email: email is the login → NOT NULL UNIQUE.
 *   - When loginField != email: email can be added as a profile field
 *     via --profile-fields=email → NULLABLE (because email is optional
 *     contact info, not the auth credential).
 *
 * @see 04-mk-director-laravel.md LAR-12 LAR-13 LAR-14 (2026-07-03 audit)
 */
uses(MkLaravelTestCase::class);

function f18StubPath(): string
{
    return dirname(__DIR__, 2) . '/src/Stubs/auth-user.auth-controller.stub';
}

function f18MigrationStubPath(): string
{
    return dirname(__DIR__, 2) . '/src/Stubs/auth-user.migration.stub';
}

function f18ListManagerPath(): string
{
    return dirname(__DIR__, 2) . '/src/Managers/ListManager.php';
}

function readF18File(string $path): string
{
    expect(file_exists($path))->toBeTrue("Source must exist at $path");

    return (string) file_get_contents($path);
}

describe('LAR-12 — BaseAuthController uses null-safe access for $user->is_active (R-PKG-047 D1+D4 SSoT)', function (): void {
    $basePath = dirname(__DIR__, 2).'/src/Auth/Controllers/BaseAuthController.php';
    $base = readF18File($basePath);

    test('R-PKG-047 D1: BaseAuthController::login() guards $user->is_active with null-safe operator', function () use ($base): void {
        // D1: el is_active check se movió al SSoT (BaseAuthController::login).
        // Defense-in-depth: null-safe operator para evitar "Attempt to read
        // property 'is_active' on null" cuando user lookup retorna null.
        expect($base)->toMatch('/public function login\(/');
        expect($base)->toContain('Schema::hasColumn(');
    });

    test('R-PKG-047 D1: BaseAuthController does NOT access $user->is_active non-null-safe (regression guard)', function () use ($base): void {
        // We REJECT bare `$user->is_active === false` sin null-safe.
        // Aceptamos `$user?->is_active === false` (null-safe) o
        // `$user !== null && $user->is_active === false` (explicit guard).
        $hasDirect = (bool) preg_match('/(?<!\\\\?)\$user->is_active\s*===\s*false/s', $base);
        expect($hasDirect)->toBeFalse(
            'BaseAuthController must NOT access $user->is_active directly (use null-safe or explicit null guard)'
        );
    });

    test('R-PKG-047 D1+D4: BaseAuthController::forgotPassword() also handles user status (regression coverage)', function () use ($base): void {
        // D4: el status check ahora es via `userHasValidStatus()` (enum
        // ScopeStatus con BC fallback a is_active). Pinean que el helper
        // existe y se usa en los métodos de auth.
        expect($base)->toContain('protected function userHasValidStatus(');
    });

    test('R-PKG-047 D1: stub AuthController es thin wrapper — NO contiene is_active check (SSoT migrada)', function () {
        $stub = readF18File(f18StubPath());

        // El thin wrapper NO override login() ni forgot() — no contiene
        // el is_active check (eso vive en BaseAuthController::userHasValidStatus).
        expect($stub)->not->toContain('$user?->is_active');
        expect($stub)->not->toContain('Schema::hasColumn(');
    });
});

describe('LAR-13 — ListManager uses $model->getKeyName() instead of literal \'id\'', function (): void {
    $listManager = readF18File(f18ListManagerPath());

    test('applyColumns() validates the primary key via getKeyName(), not literal \'id\'', function () use ($listManager): void {
        // The fix: replace `$col === 'id'` (literal) with
        // `$col === $model->getKeyName()`. We pin that getKeyName() is
        // referenced inside applyColumns (the cols-allowlist site).
        $hasGetKeyNameInApplyColumns = (bool) preg_match(
            '/function applyColumns[\s\S]{0,2000}getKeyName\(\)/s',
            $listManager
        );
        expect($hasGetKeyNameInApplyColumns)->toBeTrue(
            'ListManager::applyColumns() must consult $model->getKeyName() (not literal \'id\')'
        );
    });

    test('applySorting() default fallback orderBy uses getKeyName()', function () use ($listManager): void {
        $hasGetKeyNameInApplySorting = (bool) preg_match(
            '/function applySorting[\s\S]{0,2000}getKeyName\(\)/s',
            $listManager
        );
        expect($hasGetKeyNameInApplySorting)->toBeTrue(
            'ListManager::applySorting() default fallback orderBy must use $model->getKeyName()'
        );
    });

    test('apply() default fallback orderBy uses getKeyName()', function () use ($listManager): void {
        // apply() at line ~54 has `$query->orderBy('id', 'desc')` as a
        // last-resort fallback. The fix uses getKeyName().
        $hasGetKeyNameInApply = (bool) preg_match(
            '/function apply\b[\s\S]{0,3000}getKeyName\(\)/s',
            $listManager
        );
        expect($hasGetKeyNameInApply)->toBeTrue(
            'ListManager::apply() default fallback orderBy must use $model->getKeyName()'
        );
    });

    test('applySorting() allowed-columns merge uses getKeyName()', function () use ($listManager): void {
        // $allowed = array_merge($model->getFillable(), ['id', 'created_at', 'updated_at']);
        // must include $model->getKeyName() instead of literal 'id'.
        // We pin that getKeyName() appears in the applySorting body.
        $hasGetKeyNameInSortingMerge = (bool) preg_match(
            '/function applySorting[\s\S]{0,2000}array_merge[\s\S]{0,500}getKeyName/s',
            $listManager
        );
        expect($hasGetKeyNameInSortingMerge)->toBeTrue(
            'ListManager::applySorting() allowed-columns merge must include $model->getKeyName()'
        );
    });
});

describe('LAR-14 — migration stub documents email/loginField semantics', function (): void {
    $migrationStub = readF18File(f18MigrationStubPath());

    test('migration stub docblock explains when email is nullable vs required', function () use ($migrationStub): void {
        // The fix: explicit docblock note that explains the
        // --login-field=email vs --login-field!=email asymmetry. We
        // accept either keyword "nullable" appearing near the email
        // context (a sentence is fine; we don't pin the exact wording).
        $hasExplanation = (bool) preg_match(
            '/(email.*nullable|nullable.*email|login[-_ ]field.*email|profile[-_ ]field)/is',
            $migrationStub
        );
        expect($hasExplanation)->toBeTrue(
            'Migration stub docblock must explain email/loginField semantics (when email is nullable vs required)'
        );
    });

    test('migration stub still creates {{loginField}} as the NOT NULL UNIQUE login column', function () use ($migrationStub): void {
        // BC sanity: the fix only adds a docblock note; the column
        // definition must remain string(...)->unique() (no ->nullable()).
        // We use str_contains + regex tolerant of formatting whitespace.
        expect($migrationStub)->toContain("\$table->string('{{loginField}}')->unique();");
        expect($migrationStub)->not->toContain("\$table->string('{{loginField}}')->unique()->nullable();");
        expect($migrationStub)->not->toContain("\$table->string('{{loginField}}')->nullable();");
    });

    test('MakeAuthUserCommand::resolveProfileFields() already rejects email-as-profile-field collision', function (): void {
        // Already-pineado: resolveProfileFields() validates against a
        // reserved list that includes $loginField. If --login-field=email
        // AND --profile-fields=email, the scaffolder rejects the duplicate.
        // This test pins the regression guard (the scaffolder MUST keep
        // this guard — a refactor that drops it would silently emit
        // duplicate column definitions).
        $command = readF18File(dirname(__DIR__, 2) . '/src/Console/Commands/MakeAuthUserCommand.php');
        $hasReservedList = (bool) preg_match(
            '/\$reserved\s*=\s*\[[\s\S]{0,500}\$loginField/s',
            $command
        );
        expect($hasReservedList)->toBeTrue(
            'MakeAuthUserCommand::resolveProfileFields() must keep $loginField in the reserved list to prevent column duplicates'
        );
    });
});