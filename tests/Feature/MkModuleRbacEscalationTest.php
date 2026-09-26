<?php

declare(strict_types=1);

use Mk\Director\Tests\Concerns\BootsGeneratedRbacModule;
use Mk\Director\Tests\Concerns\BootsHttpApp;
use Mk\Director\Tests\MkLaravelTestCase;

/**
 * `mk:module --with-rbac`: asignar, revocar y sincronizar no escalan.
 *
 * 🔴 Medido por el Kernel real, antes de `ModuleRbacGrantGuard`: con SÓLO
 * `assignRole` un actor se asignaba `super-admin` (200) y roles con abilities
 * que no tenía; con `revokeRole` le quitaba `super-admin` al dueño o se quitaba
 * su propio rol; con `roles.syncAbilities` reescribía SU rol con todas las
 * abilities, y las del rol `super-admin`. Se afirma el efecto en la base.
 *
 * Los casos del final miden que no cierra de más: quien tiene lo que concede
 * sigue concediendo, y un super-admin hace todo salvo tocar `super-admin`.
 */
uses(MkLaravelTestCase::class, BootsHttpApp::class, BootsGeneratedRbacModule::class);

beforeEach(function () {
    $this->bootRbacModule();
    $this->seedRbacAbilities();

    $r = [
        'super' => $this->seedRbacRole('super-admin', []),
        'assigner' => $this->seedRbacRole('assigner', ['squad.squads.assignRole']),
        'revoker' => $this->seedRbacRole('revoker', ['squad.squads.revokeRole']),
        'syncer' => $this->seedRbacRole('syncer', ['squad.roles.syncAbilities']),
        'manager' => $this->seedRbacRole('manager', ['squad.squads.delete', 'squad.squads.update', 'squad.roles.delete']),
        'staff' => $this->seedRbacRole('staff', ['squad.squads.view']),
        'lead' => $this->seedRbacRole('lead', ['squad.squads.assignRole', 'squad.squads.revokeRole', 'squad.roles.syncAbilities', 'squad.squads.view']),
    ];
    $this->r = $r;
    $this->u = [
        'owner' => $this->seedRbacUser('owner', [$r['super']]),
        'assigner' => $this->seedRbacUser('assigner', [$r['assigner']]),
        'revoker' => $this->seedRbacUser('revoker', [$r['revoker']]),
        'syncer' => $this->seedRbacUser('syncer', [$r['syncer']]),
        'lead' => $this->seedRbacUser('lead', [$r['lead']]),
        'worker' => $this->seedRbacUser('worker', [$r['staff']]),
        'victim' => $this->seedRbacUser('victim', []),
    ];
});

afterEach(function () {
    $this->tearDownHttpApp();
});

// ─── Escalada: 403 con el código, y nada escrito ─────────────────────────

test('con sólo assignRole nadie se asigna super-admin a sí mismo', function () {
    [$status, $body] = $this->rbacSend('POST', "/api/squads/{$this->u['assigner']}/roles/{$this->r['super']}", [], $this->u['assigner']);

    expect($status)->toBe(403)
        ->and($body['__extraData']['code'])->toBe('ERR_SELF_ACCESS_CHANGE')
        ->and($this->userHasRbacRole($this->u['assigner'], $this->r['super']))->toBeFalse();
});

test('con sólo assignRole no se le asigna super-admin a otro', function () {
    [$status, $body] = $this->rbacSend('POST', "/api/squads/{$this->u['victim']}/roles/{$this->r['super']}", [], $this->u['assigner']);

    expect($status)->toBe(403)
        ->and($body['__extraData']['code'])->toBe('ERR_ACCESS_NOT_HELD')
        ->and($this->userHasRbacRole($this->u['victim'], $this->r['super']))->toBeFalse();
});

test('no se asigna un rol con abilities que el actor no tiene', function () {
    [$status, $body] = $this->rbacSend('POST', "/api/squads/{$this->u['victim']}/roles/{$this->r['manager']}", [], $this->u['assigner']);

    expect($status)->toBe(403)
        ->and($body['__extraData']['code'])->toBe('ERR_ACCESS_NOT_HELD')
        ->and($this->userHasRbacRole($this->u['victim'], $this->r['manager']))->toBeFalse();
});

test('con revokeRole no se le quita super-admin al dueño', function () {
    [$status, $body] = $this->rbacSend('DELETE', "/api/squads/{$this->u['owner']}/roles/{$this->r['super']}", [], $this->u['revoker']);

    expect($status)->toBe(403)
        ->and($body['__extraData']['code'])->toBe('ERR_TARGET_OUTRANKS_ACTOR')
        ->and($this->userHasRbacRole($this->u['owner'], $this->r['super']))->toBeTrue();
});

test('nadie se revoca su propio rol', function () {
    [$status, $body] = $this->rbacSend('DELETE', "/api/squads/{$this->u['revoker']}/roles/{$this->r['revoker']}", [], $this->u['revoker']);

    expect($status)->toBe(403)
        ->and($body['__extraData']['code'])->toBe('ERR_SELF_ACCESS_CHANGE')
        ->and($this->userHasRbacRole($this->u['revoker'], $this->r['revoker']))->toBeTrue();
});

test('con syncAbilities nadie reescribe su propio rol', function () {
    [$status, $body] = $this->rbacSend('PUT', "/api/squad-roles/{$this->r['syncer']}/abilities", ['abilities' => self::RBAC_ABILITIES], $this->u['syncer']);

    expect($status)->toBe(403)
        ->and($body['__extraData']['code'])->toBe('ERR_SELF_ACCESS_CHANGE')
        ->and($this->rbacRoleAbilities($this->r['syncer']))->toBe(['squad.roles.syncAbilities']);
});

test('con syncAbilities no se agrega a un rol lo que el actor no tiene', function () {
    // `manager` no lo tiene nadie: la regla que corta es la de lo concedido.
    $next = ['squad.roles.delete', 'squad.squads.delete', 'squad.squads.update', 'squad.squads.view'];
    [$status, $body] = $this->rbacSend('PUT', "/api/squad-roles/{$this->r['manager']}/abilities", ['abilities' => $next], $this->u['syncer']);

    expect($status)->toBe(403)
        ->and($body['__extraData']['code'])->toBe('ERR_ACCESS_NOT_HELD')
        ->and($this->rbacRoleAbilities($this->r['manager']))->toBe(['squad.roles.delete', 'squad.squads.delete', 'squad.squads.update']);
});

test('con syncAbilities no se toca el rol de alguien con más acceso', function () {
    [$status, $body] = $this->rbacSend('PUT', "/api/squad-roles/{$this->r['staff']}/abilities", ['abilities' => ['squad.squads.view', 'squad.roles.syncAbilities']], $this->u['syncer']);

    expect($status)->toBe(403)
        ->and($body['__extraData']['code'])->toBe('ERR_TARGET_OUTRANKS_ACTOR')
        ->and($this->rbacRoleAbilities($this->r['staff']))->toBe(['squad.squads.view']);
});

test('las abilities de super-admin no se sincronizan, ni siquiera un super-admin', function (string $actor) {
    [$status, $body] = $this->rbacSend('PUT', "/api/squad-roles/{$this->r['super']}/abilities", ['abilities' => ['squad.squads.view']], $this->u[$actor]);

    expect($status)->toBe(403)
        ->and($body['__extraData']['code'])->toBe('ERR_FIXED_ROLE')
        ->and($this->rbacRoleAbilities($this->r['super']))->toBe([]);
})->with(['syncer', 'owner']);

// ─── No cierra de más ─────────────────────────────────────────────────────

test('quien tiene las abilities de un rol se lo asigna y se lo quita a alguien con menos acceso', function () {
    [$status] = $this->rbacSend('POST', "/api/squads/{$this->u['victim']}/roles/{$this->r['staff']}", [], $this->u['lead']);
    expect($status)->toBe(200)
        ->and($this->userHasRbacRole($this->u['victim'], $this->r['staff']))->toBeTrue();

    [$status] = $this->rbacSend('DELETE', "/api/squads/{$this->u['worker']}/roles/{$this->r['staff']}", [], $this->u['lead']);
    expect($status)->toBe(200)
        ->and($this->userHasRbacRole($this->u['worker'], $this->r['staff']))->toBeFalse();
});

test('quien tiene lo que agrega sincroniza el rol de alguien con menos acceso', function () {
    [$status] = $this->rbacSend('PUT', "/api/squad-roles/{$this->r['staff']}/abilities", ['abilities' => ['squad.squads.view', 'squad.squads.assignRole']], $this->u['lead']);

    expect($status)->toBe(200)
        ->and($this->rbacRoleAbilities($this->r['staff']))->toBe(['squad.squads.assignRole', 'squad.squads.view']);
});

test('un super-admin asigna, revoca y sincroniza sin las reglas de rango', function () {
    [$status] = $this->rbacSend('POST', "/api/squads/{$this->u['victim']}/roles/{$this->r['super']}", [], $this->u['owner']);
    expect($status)->toBe(200)
        ->and($this->userHasRbacRole($this->u['victim'], $this->r['super']))->toBeTrue();

    [$status] = $this->rbacSend('POST', "/api/squads/{$this->u['assigner']}/roles/{$this->r['manager']}", [], $this->u['owner']);
    expect($status)->toBe(200)
        ->and($this->userHasRbacRole($this->u['assigner'], $this->r['manager']))->toBeTrue();

    [$status] = $this->rbacSend('DELETE', "/api/squads/{$this->u['lead']}/roles/{$this->r['lead']}", [], $this->u['owner']);
    expect($status)->toBe(200)
        ->and($this->userHasRbacRole($this->u['lead'], $this->r['lead']))->toBeFalse();

    [$status] = $this->rbacSend('PUT', "/api/squad-roles/{$this->r['manager']}/abilities", ['abilities' => ['squad.squads.view']], $this->u['owner']);
    expect($status)->toBe(200)
        ->and($this->rbacRoleAbilities($this->r['manager']))->toBe(['squad.squads.view']);
});
