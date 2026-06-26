<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Console;

use Mk\Director\Tests\MkLaravelTestCase;

/**
 * Source-parsing tests for R-PKG-012 — `mk:make:auth-user --profile-fields=<csv>` con tipos custom.
 *
 * Contrato pineado acá:
 *   - Constante `PROFILE_FIELD_TYPES` con 8 tipos: string, text, int, decimal, bool, date, datetime, json.
 *   - Cada tipo tiene: column_method, column_args (opcional), cast (opcional), validation.
 *   - `resolveProfileFields()` retorna `array<string, string>` (key => type).
 *   - Sintaxis `key[:type]`: con `:` → tipo explícito; sin `:` → default `string` (BC).
 *   - Tipo desconocido → fail-fast con lista de válidos.
 *   - Tipos case-sensitive (lowercase only).
 *   - Stubs: cada tipo pineado en migración (column method), model (cast entry), controller (validation).
 *   - Ortogonalidad: combinable con --login-field, --with-auth-rbac, --verify-email.
 *
 * Spec: design.md ADR-001 a ADR-010.
 * @see MakeAuthUserCommand
 */
uses(MkLaravelTestCase::class);

function packageRoot012Pft(): string
{
    return dirname(__DIR__, 3);
}

function commandSource012Pft(): string
{
    $path = packageRoot012Pft().'/src/Console/Commands/MakeAuthUserCommand.php';
    expect(file_exists($path))->toBeTrue("MakeAuthUserCommand must exist at $path");

    return (string) file_get_contents($path);
}

function stubSource012Pft(string $name): string
{
    $path = packageRoot012Pft()."/src/Stubs/{$name}";
    expect(file_exists($path))->toBeTrue("Stub $name must exist at $path");

    return (string) file_get_contents($path);
}

// ── PROFILE_FIELD_TYPES constant ────────────────────────────────────────

test('command declares PROFILE_FIELD_TYPES constant with 8 types', function () {
    $source = commandSource012Pft();

    expect($source)->toMatch('/const\s+PROFILE_FIELD_TYPES\s*=/');

    // Los 8 tipos pineados en la constante.
    foreach (['string', 'text', 'int', 'decimal', 'bool', 'date', 'datetime', 'json'] as $type) {
        expect($source)->toContain("'{$type}'");
    }
});

test('PROFILE_FIELD_TYPES entries include column_method for each type', function () {
    $source = commandSource012Pft();

    // Cada tipo tiene un column_method. Buscamos el patrón `'string' => [ ... 'column_method' => ... ]`.
    expect($source)->toMatch("/'string'\s*=>\s*\[\s*['\"]column_method['\"]\s*=>\s*['\"]string['\"]/");
    expect($source)->toMatch("/'text'\s*=>\s*\[\s*['\"]column_method['\"]\s*=>\s*['\"]text['\"]/");
    expect($source)->toMatch("/'int'\s*=>\s*\[\s*['\"]column_method['\"]\s*=>\s*['\"]integer['\"]/");
    expect($source)->toMatch("/'decimal'\s*=>\s*\[\s*['\"]column_method['\"]\s*=>\s*['\"]decimal['\"]/");
    expect($source)->toMatch("/'bool'\s*=>\s*\[\s*['\"]column_method['\"]\s*=>\s*['\"]boolean['\"]/");
    expect($source)->toMatch("/'date'\s*=>\s*\[\s*['\"]column_method['\"]\s*=>\s*['\"]date['\"]/");
    expect($source)->toMatch("/'datetime'\s*=>\s*\[\s*['\"]column_method['\"]\s*=>\s*['\"]dateTime['\"]/");
    expect($source)->toMatch("/'json'\s*=>\s*\[\s*['\"]column_method['\"]\s*=>\s*['\"]json['\"]/");
});

test('PROFILE_FIELD_TYPES decimal entry has 8,2 precision args', function () {
    $source = commandSource012Pft();

    // decimal: [8, 2] en column_args.
    expect($source)->toMatch("/'decimal'\\s*=>\\s*\\[.*?'column_args'\\s*=>\\s*\\[8,\\s*2\\]/s");
});

test('PROFILE_FIELD_TYPES cast entries per type (string/text = null, rest = cast name)', function () {
    $source = commandSource012Pft();

    // string → cast = null (Laravel default).
    expect($source)->toMatch("/'string'\\s*=>\\s*\\[.*?'cast'\\s*=>\\s*null/s");
    // text → cast = null.
    expect($source)->toMatch("/'text'\\s*=>\\s*\\[.*?'cast'\\s*=>\\s*null/s");
    // int → cast = 'integer'.
    expect($source)->toMatch("/'int'\\s*=>\\s*\\[.*?'cast'\\s*=>\\s*'integer'/s");
    // decimal → cast = 'decimal:2'.
    expect($source)->toMatch("/'decimal'\\s*=>\\s*\\[.*?'cast'\\s*=>\\s*'decimal:2'/s");
    // bool → cast = 'boolean'.
    expect($source)->toMatch("/'bool'\\s*=>\\s*\\[.*?'cast'\\s*=>\\s*'boolean'/s");
    // date → cast = 'date'.
    expect($source)->toMatch("/'date'\\s*=>\\s*\\[.*?'cast'\\s*=>\\s*'date'/s");
    // datetime → cast = 'datetime'.
    expect($source)->toMatch("/'datetime'\\s*=>\\s*\\[.*?'cast'\\s*=>\\s*'datetime'/s");
    // json → cast = 'array' (Laravel moderno).
    expect($source)->toMatch("/'json'\\s*=>\\s*\\[.*?'cast'\\s*=>\\s*'array'/s");
});

test('PROFILE_FIELD_TYPES validation rules per type (table-driven, nullable default R-PKG-014)', function () {
    $source = commandSource012Pft();

    // R-PKG-014 BUG-03 fix: validation default es 'nullable' (no 'required').
    // Para forzar required, override via --profile-fields-required=<csv>.

    // string → ['nullable', 'string', 'max:255'].
    expect($source)->toMatch("/'string'\\s*=>\\s*\\[.*?'validation'\\s*=>\\s*\\[\\s*'nullable'\\s*,\\s*'string'\\s*,\\s*'max:255'/s");
    // text → ['nullable', 'string'] (sin max).
    expect($source)->toMatch("/'text'\\s*=>\\s*\\[.*?'validation'\\s*=>\\s*\\[\\s*'nullable'\\s*,\\s*'string'\\s*\\]/s");
    // int → ['nullable', 'integer'].
    expect($source)->toMatch("/'int'\\s*=>\\s*\\[.*?'validation'\\s*=>\\s*\\[\\s*'nullable'\\s*,\\s*'integer'\\s*\\]/s");
    // decimal → ['nullable', 'numeric'].
    expect($source)->toMatch("/'decimal'\\s*=>\\s*\\[.*?'validation'\\s*=>\\s*\\[\\s*'nullable'\\s*,\\s*'numeric'\\s*\\]/s");
    // bool → ['nullable', 'boolean'].
    expect($source)->toMatch("/'bool'\\s*=>\\s*\\[.*?'validation'\\s*=>\\s*\\[\\s*'nullable'\\s*,\\s*'boolean'\\s*\\]/s");
    // date → ['nullable', 'date'].
    expect($source)->toMatch("/'date'\\s*=>\\s*\\[.*?'validation'\\s*=>\\s*\\[\\s*'nullable'\\s*,\\s*'date'\\s*\\]/s");
    // datetime → ['nullable', 'date'] (mismo que date, ADR-008).
    expect($source)->toMatch("/'datetime'\\s*=>\\s*\\[.*?'validation'\\s*=>\\s*\\[\\s*'nullable'\\s*,\\s*'date'\\s*\\]/s");
    // json → ['nullable', 'array'].
    expect($source)->toMatch("/'json'\\s*=>\\s*\\[.*?'validation'\\s*=>\\s*\\[\\s*'nullable'\\s*,\\s*'array'\\s*\\]/s");
});

// ── resolveProfileFields signature ──────────────────────────────────────

test('resolveProfileFields retorna array<string, array{type, unique}> (key => metadata)', function () {
    $source = commandSource012Pft();

    // R-PKG-014 BUG-09 fix: ahora retorna metadata con type + unique (no solo type).
    // Docblock + signature: array<string, array{type: string, unique: bool}>|null.
    expect($source)->toMatch('/@return\s+array<string,\s*array\{type:/');
    expect($source)->toContain("'type' => \$type");
    expect($source)->toContain("'unique' => \$unique");
});

test('resolveProfileFields splits on `:` for key:type syntax', function () {
    $source = commandSource012Pft();

    // Parser hace split por ':' con limit 2.
    expect($source)->toMatch("/\[\\\$key,\s*\\\$type\]\s*=\s*explode\(['\"]:['\"]/");
    expect($source)->toContain('explode(\':\', $item, 2)');
});

test('resolveProfileFields defaults to string when no type specified (BC)', function () {
    $source = commandSource012Pft();

    // Branch else: $type = 'string'.
    expect($source)->toMatch('/else\s*\{\s*\\$key\s*=\s*trim\(\\$item\);\s*\\$type\s*=\s*[\'"]string[\'"]/s');
});

test('resolveProfileFields rejects unknown type with list of valid types', function () {
    $source = commandSource012Pft();

    // Fail-fast con error específico listando tipos válidos.
    expect($source)->toMatch('/no\s+soportado|soportados|valid|válid/i');
    expect($source)->toMatch('/array_key_exists\(\\$type,\s*self::PROFILE_FIELD_TYPES/');
    // Lista todos los 8 tipos válidos en el mensaje de error (implode).
    expect($source)->toMatch('/implode\([\'"], [\'"],\s*array_keys\(self::PROFILE_FIELD_TYPES\)/');
});

test('resolveProfileFields is case-sensitive (lowercase only)', function () {
    $source = commandSource012Pft();

    // Tipos lowercase only — no normalización a lowercase (rechaza String, STRING, etc.).
    // La validación contra PROFILE_FIELD_TYPES sin strtolower implícito.
    expect($source)->toMatch('/array_key_exists\(\\$type,\s*self::PROFILE_FIELD_TYPES/');
    expect($source)->not->toMatch('/strtolower\(\\$type\)/');
});

// ── buildProfileFieldsReplacements table-driven ─────────────────────────

test('buildProfileFieldsReplacements genera column method por tipo', function () {
    $source = commandSource012Pft();

    // El método usa $config['column_method'] para construir el call.
    expect($source)->toMatch('/\\$config\[[\'"]column_method[\'"]\]/');

    // Genera column_args cuando existen (decimal: 8, 2).
    expect($source)->toMatch('/\\$config\[[\'"]column_args[\'"]\]/');
});

test('buildProfileFieldsReplacements genera cast entry por tipo (skip si null)', function () {
    $source = commandSource012Pft();

    // Cast se incluye solo si no es null (string/text son default Laravel, sin cast).
    expect($source)->toMatch('/if\s*\(\s*\$config\[[\'"]cast[\'"]\]\s*!==\s*null\s*\)/');
    // Y $config['cast'] se interpola en el output del cast entry.
    expect($source)->toContain("\$config['cast']");
});

test('buildProfileFieldsReplacements pasa validation rules al rulesPhp', function () {
    $source = commandSource012Pft();

    // El builder usa $config['validation'] y lo pasa al rulesPhp.
    expect($source)->toMatch('/\\$config\[[\'"]validation[\'"]\]/');

    // R-PKG-014 BUG-03 fix: validation rules ahora se aplican con override
    // required/nullable por field. Ya NO es asignación literal — la regla se
    // modifica in-place cuando el field está en --profile-fields-required.
    // Pineamos el código que modifica la regla in-place:
    expect($source)->toMatch('/isset\(\$requiredFields\[\$key\]\)\s*&&\s*\$rules\[0\]\s*===\s*\'nullable\'/');
    expect($source)->toMatch('/\$rules\[0\]\s*=\s*\'required\'/');
});

// ── Stubs parametrizados ────────────────────────────────────────────────

test('auth-user.migration.stub usa {{profileFieldsColumns}} placeholder', function () {
    $stub = stubSource012Pft('auth-user.migration.stub');

    expect($stub)->toContain('{{profileFieldsColumns}}');
});

test('auth-user.model.stub usa {{profileFieldsCastEntries}} placeholder', function () {
    $stub = stubSource012Pft('auth-user.model.stub');

    expect($stub)->toContain('{{profileFieldsCastEntries}}');
});

test('auth-user.model.stub declara @property por tipo (typed)', function () {
    $stub = stubSource012Pft('auth-user.model.stub');

    // Docblock @property entries — pinear que el placeholder existe
    // (el contenido del placeholder cambia por tipo, pineado en encapsulación tests).
    expect($stub)->toContain('{{profileFieldsDocblock}}');
});

// ── Ortogonalidad con flags existentes ──────────────────────────────────

test('command signature mantiene los 5 flags combinables', function () {
    $source = commandSource012Pft();

    expect($source)->toContain('--login-field=');
    expect($source)->toContain('--with-auth-rbac');
    expect($source)->toContain('--profile-fields=');
    expect($source)->toContain('--verify-email');
});

test('--profile-fields con tipos no afecta --login-field validation rule', function () {
    $source = commandSource012Pft();

    // La lógica de loginFieldValidationRule no depende del profile fields.
    expect($source)->toContain('{{loginFieldValidationRule}}');
});

test('--profile-fields con tipos es ortogonal con --verify-email', function () {
    $source = commandSource012Pft();

    // email_verified_at cast entry sigue dependiendo solo de $verifyEmail.
    expect($source)->toMatch("/\\x27\\{\\{emailVerifiedAtCastEntry\\}\\}\\x27\\s*=>\\s*\\\$verifyEmail/");
});

// ── BC verification ─────────────────────────────────────────────────────

test('BC: --profile-fields=name (sin tipo) genera migration con string column', function () {
    $source = commandSource012Pft();

    // Branch else del parser: $type = 'string' (default).
    // El builder usa la config de 'string' type → column_method = 'string'.
    expect($source)->toMatch('/else\s*\{\s*\\$key\s*=\s*trim\(\\$item\);\s*\\$type\s*=\s*[\'"]string[\'"]/s');
});

test('BC: lista cerrada de tipos válida en scope (string con scope check)', function () {
    $source = commandSource012Pft();

    // Lista cerrada pineada para validación fail-fast.
    expect($source)->toMatch('/array_key_exists\(\\$type,\s*self::PROFILE_FIELD_TYPES/');
});
