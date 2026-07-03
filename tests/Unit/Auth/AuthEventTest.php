<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Auth;

use Mk\Director\Auth\Events\AuthEvent;
use Mk\Director\Tests\MkLaravelTestCase;

/**
 * Behavior tests for R-PKG-010 — `Mk\Director\Auth\Events\AuthEvent`.
 *
 * Spec: ACR-004 — Audit log automático.
 *
 * Contrato pineado acá:
 *   - AuthEvent es constructible con `type` (string) y `payload` (array).
 *   - Las propiedades son readonly (inmutables).
 *   - Default payload es array vacío.
 *   - La trait Dispatchable está aplicada (puede ser dispatched via `AuthEvent::dispatch()`).
 *   - Tipo string vacío está permitido (no se valida, queda como responsabilidad del caller).
 */
uses(MkLaravelTestCase::class);

test('AuthEvent can be instantiated with type and payload', function () {
    $event = new AuthEvent('auth.login.success', [
        'user_id' => 'abc-123',
        'ip' => '127.0.0.1',
    ]);

    expect($event->type)->toBe('auth.login.success');
    expect($event->payload)->toBe([
        'user_id' => 'abc-123',
        'ip' => '127.0.0.1',
    ]);
});

test('AuthEvent default payload is empty array (when not provided)', function () {
    $event = new AuthEvent('auth.logout');

    expect($event->type)->toBe('auth.logout');
    expect($event->payload)->toBe([]);
});

test('AuthEvent properties are readonly (cannot be reassigned)', function () {
    $event = new AuthEvent('auth.login.success', ['user_id' => '1']);

    // PHP throws Error on readonly property reassignment.
    expect(fn () => $event->type = 'changed')
        ->toThrow(\Error::class);

    expect(fn () => $event->payload = ['changed' => true])
        ->toThrow(\Error::class);
});

test('AuthEvent can be dispatched via Dispatchable trait (R-PKG-010 ACR-004)', function () {
    // The Dispatchable trait provides a static ::dispatch() method.
    // We just verify it's there (calling it would trigger Laravel event
    // system, which is outside the scope of a unit test).
    expect(method_exists(AuthEvent::class, 'dispatch'))->toBeTrue();
});

test('AuthEvent supports all documented types from R-PKG-010 ACR-004', function () {
    $documentedTypes = [
        'auth.login.success',
        'auth.login.failed',
        'auth.logout',
        'auth.refresh.success',
        'auth.password_reset.requested',
        'auth.password_reset.success',
    ];

    foreach ($documentedTypes as $type) {
        $event = new AuthEvent($type);
        expect($event->type)->toBe($type);
    }
});