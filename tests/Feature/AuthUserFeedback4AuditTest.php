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

test('N7: resolveProfileFields omite photo_path (se emite siempre)', function () {
    // photo_path se pinea SIEMPRE en migración + $fillable. Si el usuario lo pasa
    // en --profile-fields (como el ejemplo canónico de la doc), NO debe emitirse
    // dos veces → la migración Postgres abortaba con "column specified twice".
    $result = feedback4Invoke('resolveProfileFields', ['full_name,photo_path,phone', 'email', false]);

    expect($result)->toHaveKeys(['full_name', 'phone']);
    expect($result)->not->toHaveKey('photo_path');
});

test('N7: resolveProfileFields omite status cuando --with-status está activo', function () {
    $result = feedback4Invoke('resolveProfileFields', ['status,phone', 'email', true]);

    expect($result)->toHaveKey('phone');
    expect($result)->not->toHaveKey('status');

    // Sin --with-status, `status` es un field normal (no lo emite el flag).
    $noStatus = feedback4Invoke('resolveProfileFields', ['status', 'email', false]);
    expect($noStatus)->toHaveKey('status');
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

test('N9: buildStatusCrudReplacements threadea status cuando está activo', function () {
    $repl = feedback4Invoke('buildStatusCrudReplacements', [true, ['Active' => 1, 'Inactive' => 2, 'Suspended' => 3], 'Admin', 'admin']);

    // Resource expone status + status_label.
    expect($repl['{{statusResourceEntry}}'])
        ->toContain("'status' => \$this->status?->value,")
        ->toContain("'status_label' => \$this->status?->label(),");

    // Requests validan status contra el enum.
    expect($repl['{{statusRequestRuleStore}}'])->toContain('Rule::enum(');

    // Factory default + N10: state method por estado no-default.
    expect($repl['{{statusFactoryDefault}}'])->toContain('::default()->value');
    expect($repl['{{factoryStateMethods}}'])
        ->toContain('public function inactive(): static')
        ->toContain('public function suspended(): static')
        ->not->toContain('is_active'); // N10: ya no usa la columna inexistente.

    // DTO threadeado.
    expect($repl['{{statusDtoParam}}'])->toContain('public ?int $status = null,');
});

test('N9: sin --with-status los placeholders son vacíos (BC) y factory usa inactive() legacy', function () {
    $repl = feedback4Invoke('buildStatusCrudReplacements', [false, [], 'Admin', 'admin']);

    expect($repl['{{statusResourceEntry}}'])->toBe('');
    expect($repl['{{statusRequestRuleStore}}'])->toBe('');
    expect($repl['{{statusDtoParam}}'])->toBe('');
    // BC: sin status, el factory conserva el inactive() legacy.
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
