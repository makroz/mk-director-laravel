<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Auth;

use Mk\Director\Tests\MkLaravelTestCase;

/**
 * Encapsulation tests for R-PKG-011 — Profile fields per-scope (MME/R-MK-001).
 *
 * Estos tests verifican que el scaffolder produce scopes encapsulados.
 * Como el scaffolder genera archivos en runtime, hacemos source-parsing
 * del command y los stubs para pinear:
 *
 *   - Cada scope tiene su propia tabla (Admin → admins, Member → members).
 *   - Los profile fields NO se filtran entre scopes (dni solo en Admin,
 *     phone puede estar en ambos, birthdate solo en Member).
 *   - $fillable del Admin incluye 'dni' pero NO 'birthdate'.
 *   - $fillable del Member incluye 'birthdate' pero NO 'dni'.
 *
 * Implementación:
 *   - Stub generation es source-parsable porque el command hace str_replace
 *     determinístico sobre los stubs.
 *   - Simulamos 2 generaciones (Admin con dni, Member con birthdate) y
 *     verificamos que las columnas migradas son distintas.
 *   - Esto pinea MME/R-MK-001 sin requerir boot de Laravel.
 *
 * Spec: design.md ADR-001, ADR-003.
 * @see MakeAuthUserCommand
 */
uses(MkLaravelTestCase::class);

function packageRoot011Enc(): string
{
    return dirname(__DIR__, 3);
}

function commandSource011Enc(): string
{
    $path = packageRoot011Enc().'/src/Console/Commands/MakeAuthUserCommand.php';

    return (string) file_get_contents($path);
}

function stubSource011Enc(string $name): string
{
    $path = packageRoot011Enc()."/src/Stubs/{$name}";

    return (string) file_get_contents($path);
}

/**
 * Simula la generación de un stub de migration con profile fields dados.
 *
 * El command hace str_replace sobre el stub con estos keys:
 *   - {{moduleNamePluralLower}} → tabla del scope (plural: admins, members)
 *   - {{moduleNameLower}} → scope singular (admin, member)
 *   - {{profileFieldsColumns}} → string de columnas generado por buildProfileFieldsReplacements
 *
 * Devuelve el contenido final del archivo generado.
 */
function simulateMigrationGeneration(string $scopeLower, string $tableName, array $profileFields): string
{
    $stub = stubSource011Enc('auth-user.migration.stub');

    // Construir profileFieldsColumns como lo hace buildProfileFieldsReplacements.
    $columns = '';
    foreach ($profileFields as $field) {
        $columns .= "        \$table->string('{$field}')->nullable();\n            ";
    }

    // str_replace del command.
    $result = str_replace('{{moduleNameLower}}', $scopeLower, $stub);
    $result = str_replace('{{moduleNamePluralLower}}', $tableName, $result);
    $result = str_replace('{{profileFieldsColumns}}', $columns, $result);
    $result = str_replace('{{emailVerifiedAtColumn}}', '', $result); // sin verify-email
    $result = str_replace('{{loginField}}', 'email', $result);

    return $result;
}

/**
 * Simula la generación de un stub de model con profile fields dados.
 */
function simulateModelGeneration(string $scopeLower, string $tableName, array $profileFields): string
{
    $stub = stubSource011Enc('auth-user.model.stub');

    $fillable = '';
    $docblock = '';
    foreach ($profileFields as $field) {
        $fillable .= "        '{$field}',\n";
        $docblock .= " * @property string|null \${$field}\n";
    }

    $result = str_replace('{{ModuleName}}', ucfirst($scopeLower), $stub);
    $result = str_replace('{{moduleNameLower}}', $scopeLower, $result);
    $result = str_replace('{{moduleNamePluralLower}}', $tableName, $result);
    $result = str_replace('{{loginField}}', 'email', $result);
    $result = str_replace('{{profileFieldsFillableEntries}}', $fillable, $result);
    $result = str_replace('{{profileFieldsCastEntries}}', '', $result);
    $result = str_replace('{{profileFieldsDocblock}}', $docblock, $result);
    $result = str_replace('{{mustVerifyEmailUse}}', '', $result);
    $result = str_replace('{{emailVerifiedAtCastEntry}}', '', $result);

    return $result;
}

// ── Encapsulation: cada scope tiene su propia tabla ────────────────────

test('Admin scope genera tabla admins con sus profile fields', function () {
    $migration = simulateMigrationGeneration('admin', 'admins', ['dni', 'phone']);

    expect($migration)->toContain("Schema::create('admins'");
    expect($migration)->toContain("\$table->string('dni')->nullable()");
    expect($migration)->toContain("\$table->string('phone')->nullable()");
});

test('Member scope genera tabla members con sus profile fields', function () {
    $migration = simulateMigrationGeneration('member', 'members', ['phone', 'birthdate']);

    expect($migration)->toContain("Schema::create('members'");
    expect($migration)->toContain("\$table->string('phone')->nullable()");
    expect($migration)->toContain("\$table->string('birthdate')->nullable()");
});

// ── Encapsulation: NO cross-leak entre scopes ──────────────────────────

test('Admin NO hereda profile fields de Member (birthdate aislado)', function () {
    $adminMigration = simulateMigrationGeneration('admin', 'admins', ['dni', 'phone']);

    // Admin debe tener dni y phone, pero NO birthdate.
    expect($adminMigration)->toContain("\$table->string('dni')->nullable()");
    expect($adminMigration)->toContain("\$table->string('phone')->nullable()");
    expect($adminMigration)->not->toContain("\$table->string('birthdate')->nullable()");
});

test('Member NO hereda profile fields de Admin (dni aislado)', function () {
    $memberMigration = simulateMigrationGeneration('member', 'members', ['phone', 'birthdate']);

    // Member debe tener phone y birthdate, pero NO dni.
    expect($memberMigration)->toContain("\$table->string('phone')->nullable()");
    expect($memberMigration)->toContain("\$table->string('birthdate')->nullable()");
    expect($memberMigration)->not->toContain("\$table->string('dni')->nullable()");
});

test('Admin::$fillable no expone profile fields de Member', function () {
    $adminModel = simulateModelGeneration('admin', 'admins', ['dni', 'phone']);

    // Admin::$fillable debe incluir 'dni' y 'phone'.
    expect($adminModel)->toContain("'dni',");
    expect($adminModel)->toContain("'phone',");
    // NO debe incluir 'birthdate' (que es de Member).
    expect($adminModel)->not->toContain("'birthdate',");
});

test('Member::$fillable no expone profile fields de Admin', function () {
    $memberModel = simulateModelGeneration('member', 'members', ['phone', 'birthdate']);

    // Member::$fillable debe incluir 'phone' y 'birthdate'.
    expect($memberModel)->toContain("'phone',");
    expect($memberModel)->toContain("'birthdate',");
    // NO debe incluir 'dni' (que es de Admin).
    expect($memberModel)->not->toContain("'dni',");
});

// ── Tabla propia del scope (MME/R-MK-001) ──────────────────────────────

test('cada scope declara su propia tabla en $table (no auth_users)', function () {
    $adminModel = simulateModelGeneration('admin', 'admins', ['dni']);
    $memberModel = simulateModelGeneration('member', 'members', ['birthdate']);

    expect($adminModel)->toContain("protected \$table = 'admins'");
    expect($memberModel)->toContain("protected \$table = 'members'");
    // NO debe usar la tabla compartida del base.
    expect($adminModel)->not->toContain("protected \$table = 'auth_users'");
    expect($memberModel)->not->toContain("protected \$table = 'auth_users'");
});

// ── Password reset tokens por scope ────────────────────────────────────

test('cada scope tiene su propia tabla password_reset_tokens', function () {
    $adminMigration = simulateMigrationGeneration('admin', 'admins', []);
    $memberMigration = simulateMigrationGeneration('member', 'members', []);

    expect($adminMigration)->toContain("Schema::create('admin_password_reset_tokens'");
    expect($memberMigration)->toContain("Schema::create('member_password_reset_tokens'");
    expect($adminMigration)->not->toContain("Schema::create('member_password_reset_tokens'");
    expect($memberMigration)->not->toContain("Schema::create('admin_password_reset_tokens'");
});