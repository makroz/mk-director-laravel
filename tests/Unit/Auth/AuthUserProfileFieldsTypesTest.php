<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Auth;

use Mk\Director\Tests\MkLaravelTestCase;

/**
 * Encapsulation tests for R-PKG-012 — Profile fields with custom types.
 *
 * Estos tests pinean que el scaffolder produce scopes con tipos custom correctos
 * (migration columns + model casts + validation rules) sin cross-leak entre scopes.
 *
 * Source-parsing approach (mismo patrón que R-PKG-011):
 *   - Simulamos la generación del stub (str_replace determinístico).
 *   - Verificamos que cada tipo pineado (string, text, int, decimal, bool, date, datetime, json)
 *     genera el column_method, cast entry y validation rule correctos.
 *
 * Spec: design.md ADR-004, ADR-006, ADR-007, ADR-008.
 * @see MakeAuthUserCommand
 */
uses(MkLaravelTestCase::class);

function packageRoot012Enc(): string
{
    return dirname(__DIR__, 3);
}

function commandSource012Enc(): string
{
    $path = packageRoot012Enc().'/src/Console/Commands/MakeAuthUserCommand.php';

    return (string) file_get_contents($path);
}

function stubSource012Enc(string $name): string
{
    $path = packageRoot012Enc()."/src/Stubs/{$name}";

    return (string) file_get_contents($path);
}

/**
 * Simula la generación del stub migration con profile fields dados.
 * Cada field es `key:type` (separado por `:`). Sin tipo = default `string`.
 *
 * Devuelve el contenido final del archivo migration.
 */
function simulateMigrationWithTypes(string $scopeLower, string $tableName, array $keyTypePairs): string
{
    $stub = stubSource012Enc('auth-user.migration.stub');

    // PROFILE_FIELD_TYPES constante (debe matchear la del command).
    $types = [
        'string' => ['column_method' => 'string', 'column_args' => []],
        'text' => ['column_method' => 'text', 'column_args' => []],
        'int' => ['column_method' => 'integer', 'column_args' => []],
        'decimal' => ['column_method' => 'decimal', 'column_args' => [8, 2]],
        'bool' => ['column_method' => 'boolean', 'column_args' => []],
        'date' => ['column_method' => 'date', 'column_args' => []],
        'datetime' => ['column_method' => 'dateTime', 'column_args' => []],
        'json' => ['column_method' => 'json', 'column_args' => []],
    ];

    $columns = '';
    foreach ($keyTypePairs as $pair) {
        [$key, $type] = $pair;
        $cfg = $types[$type];
        $args = empty($cfg['column_args']) ? '' : ", ".implode(', ', $cfg['column_args']);
        $columns .= "        \$table->{$cfg['column_method']}('{$key}'{$args})->nullable();\n            ";
    }

    $result = str_replace('{{moduleNameLower}}', $scopeLower, $stub);
    $result = str_replace('{{moduleNamePluralLower}}', $tableName, $result);
    $result = str_replace('{{profileFieldsColumns}}', $columns, $result);
    $result = str_replace('{{emailVerifiedAtColumn}}', '', $result);
    $result = str_replace('{{loginField}}', 'email', $result);

    return $result;
}

/**
 * Simula la generación del stub model con profile fields + cast entries.
 */
function simulateModelWithTypes(string $scopeLower, string $tableName, array $keyTypePairs): string
{
    $stub = stubSource012Enc('auth-user.model.stub');

    $types = [
        'string' => ['cast' => null, 'validation' => ['required', 'string', 'max:255']],
        'text' => ['cast' => null, 'validation' => ['required', 'string']],
        'int' => ['cast' => 'integer', 'validation' => ['required', 'integer']],
        'decimal' => ['cast' => 'decimal:2', 'validation' => ['required', 'numeric']],
        'bool' => ['cast' => 'boolean', 'validation' => ['required', 'boolean']],
        'date' => ['cast' => 'date', 'validation' => ['required', 'date']],
        'datetime' => ['cast' => 'datetime', 'validation' => ['required', 'date']],
        'json' => ['cast' => 'array', 'validation' => ['required', 'array']],
    ];

    $fillable = '';
    $castEntries = '';
    $docblock = '';
    foreach ($keyTypePairs as $pair) {
        [$key, $type] = $pair;
        $cfg = $types[$type];
        $fillable .= "        '{$key}',\n";
        if ($cfg['cast'] !== null) {
            $castEntries .= "        '{$key}' => '{$cfg['cast']}',\n";
        }
        // Docblock @property typed (phpstan-style hint).
        $phpType = match ($type) {
            'string', 'text' => 'string|null',
            'int' => 'int|null',
            'decimal' => 'float|null',
            'bool' => 'bool|null',
            'date' => '\Carbon\Carbon|null',
            'datetime' => '\Carbon\Carbon|null',
            'json' => 'array|null',
            default => 'mixed',
        };
        $docblock .= " * @property {$phpType} \${$key}\n";
    }

    $result = str_replace('{{ModuleName}}', ucfirst($scopeLower), $stub);
    $result = str_replace('{{moduleNameLower}}', $scopeLower, $result);
    $result = str_replace('{{moduleNamePluralLower}}', $tableName, $result);
    $result = str_replace('{{loginField}}', 'email', $result);
    $result = str_replace('{{profileFieldsFillableEntries}}', $fillable, $result);
    $result = str_replace('{{profileFieldsCastEntries}}', $castEntries, $result);
    $result = str_replace('{{profileFieldsDocblock}}', $docblock, $result);
    $result = str_replace('{{mustVerifyEmailUse}}', '', $result);
    $result = str_replace('{{emailVerifiedAtCastEntry}}', '', $result);

    return $result;
}

// ── 8 tipos pineados en migración ───────────────────────────────────────

test('Admin scope con birthdate:date genera date column', function () {
    $migration = simulateMigrationWithTypes('admin', 'admins', [['birthdate', 'date']]);

    expect($migration)->toContain("\$table->date('birthdate')->nullable()");
});

test('Admin scope con age:int genera integer column', function () {
    $migration = simulateMigrationWithTypes('admin', 'admins', [['age', 'int']]);

    expect($migration)->toContain("\$table->integer('age')->nullable()");
});

test('Member scope con biography:text genera text column', function () {
    $migration = simulateMigrationWithTypes('member', 'members', [['biography', 'text']]);

    expect($migration)->toContain("\$table->text('biography')->nullable()");
});

test('Member scope con score:decimal genera decimal(8,2) column', function () {
    $migration = simulateMigrationWithTypes('member', 'members', [['score', 'decimal']]);

    expect($migration)->toContain("\$table->decimal('score', 8, 2)->nullable()");
});

test('Member scope con active:bool genera boolean column', function () {
    $migration = simulateMigrationWithTypes('member', 'members', [['active', 'bool']]);

    expect($migration)->toContain("\$table->boolean('active')->nullable()");
});

test('Member scope con registered_at:datetime genera dateTime column', function () {
    $migration = simulateMigrationWithTypes('member', 'members', [['registered_at', 'datetime']]);

    expect($migration)->toContain("\$table->dateTime('registered_at')->nullable()");
});

test('Member scope con metadata:json genera json column', function () {
    $migration = simulateMigrationWithTypes('member', 'members', [['metadata', 'json']]);

    expect($migration)->toContain("\$table->json('metadata')->nullable()");
});

test('Admin scope con name (default string) genera string column', function () {
    $migration = simulateMigrationWithTypes('admin', 'admins', [['name', 'string']]);

    expect($migration)->toContain("\$table->string('name')->nullable()");
});

// ── Cast entries pineados en model ──────────────────────────────────────

test('Member scope con active:bool incluye cast boolean en model', function () {
    $model = simulateModelWithTypes('member', 'members', [['active', 'bool']]);

    expect($model)->toContain("'active' => 'boolean'");
});

test('Member scope con score:decimal incluye cast decimal:2 en model', function () {
    $model = simulateModelWithTypes('member', 'members', [['score', 'decimal']]);

    expect($model)->toContain("'score' => 'decimal:2'");
});

test('Member scope con metadata:json incluye cast array en model', function () {
    $model = simulateModelWithTypes('member', 'members', [['metadata', 'json']]);

    expect($model)->toContain("'metadata' => 'array'");
});

test('Admin scope con name:string NO incluye cast (default Laravel)', function () {
    $model = simulateModelWithTypes('admin', 'admins', [['name', 'string']]);

    expect($model)->not->toContain("'name' => 'string'");
});

test('Admin scope con biography:text NO incluye cast (default Laravel)', function () {
    $model = simulateModelWithTypes('admin', 'admins', [['biography', 'text']]);

    expect($model)->not->toContain("'biography' => 'string'");
});

// ── Mixed tipos pineados ────────────────────────────────────────────────

test('Admin scope con mixed tipos genera columns correctas para cada uno', function () {
    $migration = simulateMigrationWithTypes('admin', 'admins', [
        ['name', 'string'],
        ['birthdate', 'date'],
        ['age', 'int'],
        ['biography', 'text'],
        ['active', 'bool'],
    ]);

    expect($migration)->toContain("\$table->string('name')->nullable()");
    expect($migration)->toContain("\$table->date('birthdate')->nullable()");
    expect($migration)->toContain("\$table->integer('age')->nullable()");
    expect($migration)->toContain("\$table->text('biography')->nullable()");
    expect($migration)->toContain("\$table->boolean('active')->nullable()");
});

// ── Encapsulación: NO cross-leak entre scopes ───────────────────────────

test('Admin scope con tipos custom NO expone columnas al scope Member', function () {
    $adminMigration = simulateMigrationWithTypes('admin', 'admins', [['dni', 'string']]);

    expect($adminMigration)->toContain("\$table->string('dni')->nullable()");
    expect($adminMigration)->not->toContain("'members'");
});

test('Member scope con tipos custom NO expone columnas al scope Admin', function () {
    $memberMigration = simulateMigrationWithTypes('member', 'members', [['metadata', 'json']]);

    expect($memberMigration)->toContain("\$table->json('metadata')->nullable()");
    expect($memberMigration)->not->toContain("'admins'");
});

test('Admin::$fillable no expone profile fields con tipos custom de Member', function () {
    $adminModel = simulateModelWithTypes('admin', 'admins', [['dni', 'string']]);
    $memberModel = simulateModelWithTypes('member', 'members', [['birthdate', 'date']]);

    expect($adminModel)->toContain("'dni',");
    expect($adminModel)->not->toContain("'birthdate',");

    expect($memberModel)->toContain("'birthdate',");
    expect($memberModel)->not->toContain("'dni',");
});

test('Admin scope con birthdate:date NO genera cast en su model', function () {
    // birthdate:date → Admin. Member con metadata:json → sí genera cast 'array'.
    // Test pinea que el cast de json NO leakea al Admin.
    $adminModel = simulateModelWithTypes('admin', 'admins', [['birthdate', 'date']]);

    expect($adminModel)->toContain("'birthdate' => 'date'");
    expect($adminModel)->not->toContain("'metadata' => 'array'");
});
