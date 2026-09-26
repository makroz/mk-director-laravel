<?php

declare(strict_types=1);

use Mk\Director\Plugins\Enterprise\MkMultiTenantPlugin;
use Mk\Director\Tests\Concerns\BootsGeneratedRbacModule;
use Mk\Director\Tests\Concerns\BootsHttpApp;
use Mk\Director\Tests\MkLaravelTestCase;

/**
 * `mk:module --with-rbac` con `MkMultiTenantPlugin`: asignar, revocar y
 * sincronizar sobre el usuario o el rol de OTRO tenant da 404 y no escribe.
 *
 * 🔴 Medido por el Kernel real: `assignRole`/`revokeRole`/`syncAbilities`
 * cargaban usuario y rol con `findOrFail()` pelado, sin los `beforeQuery` de
 * los plugins. Un actor del tenant 1 le asignaba su rol a un usuario del
 * tenant 2, le quitaba los suyos, y le daba a un usuario propio el rol de otro
 * tenant: 200 y escrito. Se afirma el efecto en la base, no sólo el código.
 */
uses(MkLaravelTestCase::class, BootsHttpApp::class, BootsGeneratedRbacModule::class);

beforeEach(function () {
    $this->bootRbacModule(['plugins' => [MkMultiTenantPlugin::class]], tenantColumns: true);
    $this->seedRbacAbilities();

    $this->ids = [
        'r_a_admin' => $this->seedRbacRole('a-admin', ['squad.squads.assignRole', 'squad.squads.revokeRole', 'squad.roles.syncAbilities', 'squad.squads.view', 'squad.roles.view'], 1),
        'r_a_staff' => $this->seedRbacRole('a-staff', ['squad.squads.view'], 1),
        'r_b' => $this->seedRbacRole('b-role', ['squad.squads.view', 'squad.squads.delete'], 2),
    ];
    $this->ids['actorA'] = $this->seedRbacUser('actora', [$this->ids['r_a_admin']], 1);
    $this->ids['userA2'] = $this->seedRbacUser('usera2', [], 1);
    $this->ids['userB'] = $this->seedRbacUser('userb', [$this->ids['r_b']], 2);
});

afterEach(function () {
    $this->tearDownHttpApp();
});

test('asignarle un rol propio a un usuario de otro tenant da 404 y no escribe', function () {
    [$status] = $this->rbacSend('POST', "/api/squads/{$this->ids['userB']}/roles/{$this->ids['r_a_staff']}", [], $this->ids['actorA']);

    expect($status)->toBe(404)
        ->and($this->userHasRbacRole($this->ids['userB'], $this->ids['r_a_staff']))->toBeFalse();
});

test('asignarle a un usuario propio el rol de otro tenant da 404 y no escribe', function () {
    [$status] = $this->rbacSend('POST', "/api/squads/{$this->ids['userA2']}/roles/{$this->ids['r_b']}", [], $this->ids['actorA']);

    expect($status)->toBe(404)
        ->and($this->userHasRbacRole($this->ids['userA2'], $this->ids['r_b']))->toBeFalse();
});

test('revocarle un rol a un usuario de otro tenant da 404 y el rol sigue', function () {
    [$status] = $this->rbacSend('DELETE', "/api/squads/{$this->ids['userB']}/roles/{$this->ids['r_b']}", [], $this->ids['actorA']);

    expect($status)->toBe(404)
        ->and($this->userHasRbacRole($this->ids['userB'], $this->ids['r_b']))->toBeTrue();
});

test('sincronizar las abilities del rol de otro tenant da 404 y no las cambia', function () {
    [$status] = $this->rbacSend('PUT', "/api/squad-roles/{$this->ids['r_b']}/abilities", ['abilities' => ['squad.squads.view']], $this->ids['actorA']);

    expect($status)->toBe(404)
        ->and($this->rbacRoleAbilities($this->ids['r_b']))->toBe(['squad.squads.delete', 'squad.squads.view']);
});

test('dentro del MISMO tenant asignar, revocar y sincronizar siguen andando', function () {
    [$status] = $this->rbacSend('POST', "/api/squads/{$this->ids['userA2']}/roles/{$this->ids['r_a_staff']}", [], $this->ids['actorA']);
    expect($status)->toBe(200)
        ->and($this->userHasRbacRole($this->ids['userA2'], $this->ids['r_a_staff']))->toBeTrue();

    [$status] = $this->rbacSend('PUT', "/api/squad-roles/{$this->ids['r_a_staff']}/abilities", ['abilities' => ['squad.squads.view', 'squad.roles.view']], $this->ids['actorA']);
    expect($status)->toBe(200)
        ->and($this->rbacRoleAbilities($this->ids['r_a_staff']))->toBe(['squad.roles.view', 'squad.squads.view']);

    [$status] = $this->rbacSend('DELETE', "/api/squads/{$this->ids['userA2']}/roles/{$this->ids['r_a_staff']}", [], $this->ids['actorA']);
    expect($status)->toBe(200)
        ->and($this->userHasRbacRole($this->ids['userA2'], $this->ids['r_a_staff']))->toBeFalse();
});

test('el show de un usuario de otro tenant da 404; el del propio anda', function () {
    [$status] = $this->rbacSend('GET', "/api/squads/{$this->ids['userB']}", [], $this->ids['actorA']);
    expect($status)->toBe(404);

    [$status] = $this->rbacSend('GET', "/api/squads/{$this->ids['userA2']}", [], $this->ids['actorA']);
    expect($status)->toBe(200);
});
