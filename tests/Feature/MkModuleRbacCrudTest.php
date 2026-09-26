<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Mk\Director\Tests\Concerns\BootsGeneratedRbacModule;
use Mk\Director\Tests\Concerns\BootsHttpApp;
use Mk\Director\Tests\MkLaravelTestCase;

/**
 * El CRUD de los tres controllers de `mk:module --with-rbac`, por el Kernel.
 *
 * 🔴 Medido antes del fix: los 15 endpoints CRUD (index/show/store/update/
 * destroy de usuarios, roles y abilities) daban 500 — `TypeError`, porque los
 * tres declaraban `'service' => RbacService`, que no es un
 * `MkModuleServiceInterface`. Arreglar sólo eso los dejaba ABIERTOS: la Policy
 * del CRUD está apagada por defecto y las rutas no tenían middleware. Y con
 * `{modulo}.{usuarios}.update` se le cambiaba la contraseña al dueño.
 *
 * Se afirma el efecto en la base: que se creó, se editó o se borró — o que no.
 */
uses(MkLaravelTestCase::class, BootsHttpApp::class, BootsGeneratedRbacModule::class);

beforeEach(function () {
    $this->bootRbacModule();
    $this->seedRbacAbilities();

    $r = [
        'super' => $this->seedRbacRole('super-admin', []),
        'staff' => $this->seedRbacRole('staff', ['squad.squads.view']),
        'manager' => $this->seedRbacRole('manager', ['squad.squads.delete']),
        'editor' => $this->seedRbacRole('editor', ['squad.squads.viewAny', 'squad.squads.view', 'squad.squads.create', 'squad.squads.update', 'squad.squads.delete']),
        'role_editor' => $this->seedRbacRole('role-editor', ['squad.roles.update', 'squad.roles.delete']),
    ];
    $this->r = $r;
    $this->u = [
        'owner' => $this->seedRbacUser('owner', [$r['super']]),
        'editor' => $this->seedRbacUser('editor', [$r['editor']]),
        'role_editor' => $this->seedRbacUser('roleeditor', [$r['role_editor']]),
        'worker' => $this->seedRbacUser('worker', [$r['staff']]),
        'victim' => $this->seedRbacUser('victim', []),
    ];
});

afterEach(function () {
    $this->tearDownHttpApp();
});

// ─── Anda: los 15 endpoints, con un super-admin ───────────────────────────

test('un super-admin usa el CRUD de usuarios, roles y abilities, y escribe', function (string $prefix, string $table, array $create, array $update) {
    $actor = $this->u['owner'];

    [$status] = $this->rbacSend('GET', "/api/{$prefix}", [], $actor);
    expect($status)->toBe(200);

    [$status, $body] = $this->rbacSend('POST', "/api/{$prefix}", $create, $actor);
    expect($status)->toBe(201);
    $id = $body['data']['id'];
    expect(DB::table($table)->where('id', $id)->exists())->toBeTrue();

    [$status, $body] = $this->rbacSend('GET', "/api/{$prefix}/{$id}", [], $actor);
    expect($status)->toBe(200)->and($body['data']['id'])->toBe($id);

    [$status] = $this->rbacSend('PUT', "/api/{$prefix}/{$id}", $update, $actor);
    expect($status)->toBe(200)
        ->and(DB::table($table)->where('id', $id)->value('name'))->toBe($update['name']);

    [$status] = $this->rbacSend('DELETE', "/api/{$prefix}/{$id}", [], $actor);
    expect($status)->toBe(200)
        ->and(DB::table($table)->where('id', $id)->exists())->toBeFalse();
})->with([
    'usuarios' => ['squads', 'squad_users', ['name' => 'nuevo', 'email' => 'nuevo@squad.test', 'password' => 'secreto123'], ['name' => 'nuevo-editado']],
    'roles' => ['squad-roles', 'squad_roles', ['name' => 'cajero'], ['name' => 'cajero-editado']],
    'abilities' => ['squad-abilities', 'squad_abilities', ['name' => 'squad.reports.view'], ['name' => 'squad.reports.export']],
]);

// ─── La Policy corre: sin la ability, 403 y nada escrito ─────────────────

test('sin actor, el CRUD da 403 y no escribe', function () {
    [$status] = $this->rbacSend('GET', '/api/squads');
    expect($status)->toBe(403);

    [$status] = $this->rbacSend('POST', '/api/squads', ['name' => 'intruso', 'email' => 'i@squad.test', 'password' => 'secreto123']);
    expect($status)->toBe(403)
        ->and(DB::table('squad_users')->where('email', 'i@squad.test')->exists())->toBeFalse();

    [$status] = $this->rbacSend('DELETE', "/api/squads/{$this->u['victim']}");
    expect($status)->toBe(403)
        ->and(DB::table('squad_users')->where('id', $this->u['victim'])->exists())->toBeTrue();
});

test('sin la ability del endpoint, el CRUD da 403 y no escribe', function () {
    [$status] = $this->rbacSend('POST', '/api/squad-roles', ['name' => 'intruso'], $this->u['worker']);
    expect($status)->toBe(403)
        ->and(DB::table('squad_roles')->where('name', 'intruso')->exists())->toBeFalse();

    [$status] = $this->rbacSend('PUT', "/api/squads/{$this->u['victim']}", ['name' => 'pisado'], $this->u['worker']);
    expect($status)->toBe(403)
        ->and(DB::table('squad_users')->where('id', $this->u['victim'])->value('name'))->toBe('victim');
});

test('las abilities son de sólo lectura para quien no es super-admin', function () {
    [$status] = $this->rbacSend('POST', '/api/squad-abilities', ['name' => 'squad.x'], $this->u['editor']);

    expect($status)->toBe(403)
        ->and(DB::table('squad_abilities')->where('name', 'squad.x')->exists())->toBeFalse();
});

// ─── Editar y borrar usuarios pasa por la guarda ─────────────────────────

test('con update no se le cambia la contraseña al dueño', function () {
    [$status, $body] = $this->rbacSend('PUT', "/api/squads/{$this->u['owner']}", ['password' => 'tomada123'], $this->u['editor']);

    expect($status)->toBe(403)
        ->and($body['__extraData']['code'])->toBe('ERR_TARGET_OUTRANKS_ACTOR')
        ->and(DB::table('squad_users')->where('id', $this->u['owner'])->value('password'))->toBe('hash-original');
});

test('con delete no se borra a alguien con más acceso', function () {
    [$status, $body] = $this->rbacSend('DELETE', "/api/squads/{$this->u['owner']}", [], $this->u['editor']);

    expect($status)->toBe(403)
        ->and($body['__extraData']['code'])->toBe('ERR_TARGET_OUTRANKS_ACTOR')
        ->and(DB::table('squad_users')->where('id', $this->u['owner'])->exists())->toBeTrue();
});

test('con update y delete se edita y se borra a alguien con menos acceso, y uno se edita a sí mismo', function () {
    [$status] = $this->rbacSend('PUT', "/api/squads/{$this->u['worker']}", ['name' => 'worker-editado'], $this->u['editor']);
    expect($status)->toBe(200)
        ->and(DB::table('squad_users')->where('id', $this->u['worker'])->value('name'))->toBe('worker-editado');

    [$status] = $this->rbacSend('PUT', "/api/squads/{$this->u['editor']}", ['name' => 'editor-editado'], $this->u['editor']);
    expect($status)->toBe(200)
        ->and(DB::table('squad_users')->where('id', $this->u['editor'])->value('name'))->toBe('editor-editado');

    [$status] = $this->rbacSend('DELETE', "/api/squads/{$this->u['victim']}", [], $this->u['editor']);
    expect($status)->toBe(200)
        ->and(DB::table('squad_users')->where('id', $this->u['victim'])->exists())->toBeFalse();
});

// ─── Editar y borrar roles pasa por la guarda ────────────────────────────

test('super-admin no se renombra ni se borra, ni siquiera un super-admin', function (string $actor) {
    [$status, $body] = $this->rbacSend('PUT', "/api/squad-roles/{$this->r['super']}", ['name' => 'renombrado'], $this->u[$actor]);
    expect($status)->toBe(403)
        ->and($body['__extraData']['code'])->toBe('ERR_FIXED_ROLE')
        ->and(DB::table('squad_roles')->where('id', $this->r['super'])->value('name'))->toBe('super-admin');

    [$status, $body] = $this->rbacSend('DELETE', "/api/squad-roles/{$this->r['super']}", [], $this->u[$actor]);
    expect($status)->toBe(403)
        ->and($body['__extraData']['code'])->toBe('ERR_FIXED_ROLE')
        ->and(DB::table('squad_roles')->where('id', $this->r['super'])->exists())->toBeTrue();
})->with(['role_editor', 'owner']);

test('ningún rol se renombra a super-admin', function () {
    [$status, $body] = $this->rbacSend('PUT', "/api/squad-roles/{$this->r['manager']}", ['name' => 'super-admin'], $this->u['owner']);

    expect($status)->toBe(403)
        ->and($body['__extraData']['code'])->toBe('ERR_FIXED_ROLE')
        ->and(DB::table('squad_roles')->where('id', $this->r['manager'])->value('name'))->toBe('manager');
});

test('no se edita el rol de alguien con más acceso', function () {
    [$status, $body] = $this->rbacSend('PUT', "/api/squad-roles/{$this->r['staff']}", ['name' => 'pisado'], $this->u['role_editor']);

    expect($status)->toBe(403)
        ->and($body['__extraData']['code'])->toBe('ERR_TARGET_OUTRANKS_ACTOR')
        ->and(DB::table('squad_roles')->where('id', $this->r['staff'])->value('name'))->toBe('staff');
});

test('se edita y se borra un rol sin nadie con más acceso y con lo que el actor tiene, y super-admin acepta su propio nombre', function () {
    [$status] = $this->rbacSend('PUT', "/api/squad-roles/{$this->r['manager']}", ['name' => 'gerente'], $this->u['role_editor']);
    expect($status)->toBe(200)
        ->and(DB::table('squad_roles')->where('id', $this->r['manager'])->value('name'))->toBe('gerente');

    // Borrar un rol es quitarle sus abilities: se borra el que sólo tiene lo
    // que el actor tiene. `manager` (con `squads.delete`) no.
    [$status, $body] = $this->rbacSend('DELETE', "/api/squad-roles/{$this->r['manager']}", [], $this->u['role_editor']);
    expect($status)->toBe(403)
        ->and($body['__extraData']['code'])->toBe('ERR_ACCESS_NOT_HELD')
        ->and(DB::table('squad_roles')->where('id', $this->r['manager'])->exists())->toBeTrue();

    $temp = $this->seedRbacRole('temporal', ['squad.roles.update']);
    [$status] = $this->rbacSend('DELETE', "/api/squad-roles/{$temp}", [], $this->u['role_editor']);
    expect($status)->toBe(200)
        ->and(DB::table('squad_roles')->where('id', $temp)->exists())->toBeFalse();

    // Un formulario manda el objeto entero: el mismo `name` no es renombrar.
    [$status] = $this->rbacSend('PUT', "/api/squad-roles/{$this->r['super']}", ['name' => 'super-admin', 'description' => 'Todo'], $this->u['owner']);
    expect($status)->toBe(200)
        ->and(DB::table('squad_roles')->where('id', $this->r['super'])->value('description'))->toBe('Todo');
});
