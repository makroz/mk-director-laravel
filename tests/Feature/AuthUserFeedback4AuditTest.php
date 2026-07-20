<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Feature;

use Illuminate\Console\OutputStyle;
use Mk\Director\Console\Commands\MakeAuthUserCommand;
use Mk\Director\Tests\MkLaravelTestCase;
use ReflectionMethod;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * Audit-driven regression tests para el feedback del piloto RETO — corrida 4
 * (`.makromania/projects/reto/feedbacks/FEEDBACK4.md`).
 *
 * Pinea los hallazgos backend N7–N16. Patrón: reflection sobre métodos protected
 * del command + parsing de stubs (no e2e — eso requiere un app Laravel completo).
 * Si un bug vuelve, el test falla.
 */
uses(MkLaravelTestCase::class);

function feedback4Command(): MakeAuthUserCommand
{
    $command = new MakeAuthUserCommand;
    $command->setOutput(new OutputStyle(new StringInput(''), new NullOutput));

    return $command;
}

function feedback4Invoke(string $method, array $args): mixed
{
    $command = feedback4Command();
    $ref = new ReflectionMethod($command, $method);
    $ref->setAccessible(true);

    return $ref->invoke($command, ...$args);
}

function feedback4Stub(string $relative): string
{
    return (string) file_get_contents(dirname(__DIR__, 2).'/src/Stubs/'.$relative);
}

// ─── N7 — dedup de columnas siempre-emitidas ──────────────────────────────

test('N7: resolveProfileFields omite status cuando --with-status está activo', function () {
    $result = feedback4Invoke('resolveProfileFields', ['status,phone', 'email', true]);

    expect($result)->toHaveKey('phone');
    expect($result)->not->toHaveKey('status');

    // Sin --with-status, `status` es un field normal (no lo emite el flag).
    $noStatus = feedback4Invoke('resolveProfileFields', ['status', 'email', false]);
    expect($noStatus)->toHaveKey('status');
});

test('FEEDBACK10: resolveProfileFields NO omite photo_path (no es alwaysEmitted post-refactor)', function () {
    // Pre-FEEDBACK10: `photo_path` estaba en `$alwaysEmitted` (se pineaba
    // SIEMPRE en migración + $fillable). Post-FEEDBACK10 (R-PKG-050):
    // `photo_path` ya NO es alwaysEmitted. Si el consumer lo declara via
    // `--profile-fields="photo_path:file"`, el scaffolder lo trata como un
    // file field normal (columna `photo_path`, accessor `getPhotoPathUrlAttribute`).
    //
    // Idem `full_name,phone,photo_path` → `photo_path` se queda en el map
    // (NO se omite silenciosamente), con type=file + is_file=true.
    $result = feedback4Invoke('resolveProfileFields', ['full_name,phone,photo_path:file', 'email', false]);

    expect($result)->toHaveKeys(['full_name', 'phone', 'photo_path']);
    expect($result['photo_path']['type'])->toBe('file');
    expect($result['photo_path']['is_file'])->toBeTrue();
});

// ─── N11 — DTO named args camelCase ───────────────────────────────────────

test('N11: buildProfileFieldsFromRequest emite named args camelCase', function () {
    $fields = ['full_name' => ['type' => 'string', 'unique' => false]];
    $out = feedback4Invoke('buildProfileFieldsFromRequest', [$fields]);

    // El named arg DEBE ser camelCase (matchea el param del constructor), la KEY
    // del input HTTP sigue snake_case (columna real).
    expect($out)->toContain("fullName: \$request->input('full_name'),");
    expect($out)->not->toContain('full_name:');
});

test('N11: buildProfileFieldsFromArray emite named args camelCase', function () {
    $fields = ['photo_path' => ['type' => 'string', 'unique' => false]];
    $out = feedback4Invoke('buildProfileFieldsFromArray', [$fields]);

    expect($out)->toContain("photoPath: \$data['photo_path'] ?? null,");
    expect($out)->not->toContain('photo_path:');
});

// ─── N12 — FormRequests validan TODOS los profile fields ──────────────────

test('N12: buildProfileFieldRules emite regla para fields NO-unique', function () {
    $fields = ['full_name' => ['type' => 'string', 'unique' => false]];
    $rules = feedback4Invoke('buildProfileFieldRules', [$fields, [], 'admins']);

    // Antes solo se emitían reglas para fields unique → validated() descartaba
    // full_name y el CRUD nunca lo persistía.
    expect($rules['store'])->toContain("'full_name' => ['nullable', 'string'],");
    expect($rules['update'])->toContain("'full_name' => ['sometimes', 'nullable', 'string'],");
});

test('N12: required + tipo se reflejan en las reglas', function () {
    $fields = [
        'full_name' => ['type' => 'string', 'unique' => false],
        'age' => ['type' => 'int', 'unique' => false],
    ];
    $rules = feedback4Invoke('buildProfileFieldRules', [$fields, ['full_name' => true], 'admins']);

    expect($rules['store'])->toContain("'full_name' => ['required', 'string'],");
    expect($rules['store'])->toContain("'age' => ['nullable', 'integer'],");
});

test('N12: field unique conserva la regla unique (store + update ignore)', function () {
    $fields = ['ci' => ['type' => 'string', 'unique' => true]];
    $rules = feedback4Invoke('buildProfileFieldRules', [$fields, [], 'admins']);

    expect($rules['store'])->toContain("'unique:admins,ci'");
    expect($rules['update'])->toContain("Rule::unique('admins', 'ci')->ignore(\$id)");
});

// ─── N9/N10 — status threadeado end-to-end ────────────────────────────────

test('buildStatusCrudReplacements threadea status (enum int-backed, default ON)', function () {
    // Revert 2026-07-19: status es int-backed (ver el docblock de ScopeStatus).
    // El command pinea los 4 cases canónicos como array indexado
    // ['Active', 'Inactive', 'Blocked', 'Pending'].
    $repl = feedback4Invoke('buildStatusCrudReplacements', [true, ['Active', 'Inactive', 'Blocked', 'Pending'], 'Admin', 'admin']);

    // Resource expone status (int) + status_label. El label es lo único que
    // le permite a la UI mostrar algo legible sin conocer los números.
    expect($repl['{{statusResourceEntry}}'])
        ->toContain("'status' => \$this->status?->value,")
        ->toContain("'status_label' => \$this->status?->label(),");

    // Requests validan status contra el enum (Rule::enum) y con la regla de
    // tipo alineada: con `'string'` y un enum int-backed, Rule::enum nunca
    // matchearía y el alta fallaría con un mensaje que no dice nada.
    expect($repl['{{statusRequestRuleStore}}'])
        ->toContain('Rule::enum(')
        ->toContain("'integer'")
        ->not->toContain("'string'");

    // Factory default + state methods por cada estado no-default.
    expect($repl['{{statusFactoryDefault}}'])->toContain('::default()->value');
    expect($repl['{{factoryStateMethods}}'])
        ->toContain('public function inactive(): static')
        ->toContain('public function blocked(): static')
        ->toContain('public function pending(): static')
        ->not->toContain('is_active'); // D4: ya no usa la columna legacy.

    // DTO threadeado con el tipo correcto. Éste es el assert que cazó que el
    // revert había quedado a medias: la columna ya era entera y el DTO seguía
    // declarando `?string`.
    expect($repl['{{statusDtoParam}}'])->toContain('public ?int $status = null,');
    expect($repl['{{statusDtoFromRequest}}'])->toContain('(int) $request->input(\'status\')');
});

test('R-PKG-047 D2: sin --with-status los placeholders son vacíos (BC) y factory usa inactive() legacy', function () {
    $repl = feedback4Invoke('buildStatusCrudReplacements', [false, [], 'Admin', 'admin']);

    expect($repl['{{statusResourceEntry}}'])->toBe('');
    expect($repl['{{statusRequestRuleStore}}'])->toBe('');
    expect($repl['{{statusDtoParam}}'])->toBe('');
    // BC: sin status, el factory conserva el inactive() legacy (escribe is_active=false).
    expect($repl['{{factoryStateMethods}}'])->toContain('public function inactive(): static');
});

// ─── N13 — setExtraData no selecciona roles.description ────────────────────

test('N13: admin-controller.stub setExtraData NO selecciona roles.description', function () {
    $stub = feedback4Stub('auth-user/admin-controller.stub');

    // La tabla `roles` del paquete NO tiene `description` → select con description
    // reventaba con SQLSTATE 42703 en el primer GET ?__extraData=1.
    expect($stub)->toContain("->get(['id', 'name']),");
    expect($stub)->not->toContain("->get(['id', 'name', 'description'])");
});

// ─── N15 — cache tags: array SÍ soporta tags ──────────────────────────────

test('N15: cacheStoreSupportsTags reconoce array/apc como taggable', function () {
    expect(feedback4Invoke('cacheStoreSupportsTags', ['array']))->toBeTrue();
    expect(feedback4Invoke('cacheStoreSupportsTags', ['apc']))->toBeTrue();
    expect(feedback4Invoke('cacheStoreSupportsTags', ['redis']))->toBeTrue();
    // Los que NO soportan tags (extienden Store pelado).
    expect(feedback4Invoke('cacheStoreSupportsTags', ['file']))->toBeFalse();
    expect(feedback4Invoke('cacheStoreSupportsTags', ['database']))->toBeFalse();
});
