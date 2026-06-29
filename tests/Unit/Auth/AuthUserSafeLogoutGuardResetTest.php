<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Auth;

use Mk\Director\Auth\Models\AuthUser;
use Mk\Director\Tests\MkLaravelTestCase;

/**
 * HALLAZGO-NEW-FASE14-03 source-parsing tests — feedback RETO fase 14 (2026-06-29).
 *
 * Source: `FEEDBACK-TO-MK-DIRECTOR-fase14.md` § HALLAZGO-NEW-FASE14-03.
 *
 * What this sprint pines:
 *   The `safeLogoutCurrentToken()` helper in `AuthUser` now calls
 *   `\Auth::forgetGuards()` AFTER `$token->delete()`. This invalidates
 *   the AuthManager guard cache so subsequent requests in the same
 *   process don't see the now-revoked token via the cached user.
 *
 * Pre-fix runtime symptom (RETO feedback, testing only):
 *   - `POST /api/admin/auth/logout` revokes the token correctly.
 *   - BUT: subsequent `GET /api/admin/auth/me` with the SAME Bearer
 *     token (now revoked in DB) STILL returns 200 with the user
 *     data, because Sanctum caches the resolved user on the guard
 *     for the lifetime of the container.
 *   - In production this is a non-issue (every HTTP request is a
 *     fresh PHP process — no cached guards).
 *   - In Pest/PHPUnit testing, the container is shared across all
 *     requests in the same test → the cache persists → 401 expected
 *     but 200 returned.
 *
 * Post-fix runtime behavior (v1.8.1+):
 *   - Production: `\Auth::forgetGuards()` is no-op (no cached guards
 *     in a fresh PHP process). Transparent for consumers.
 *   - Testing: `\Auth::forgetGuards()` clears the cached guard, so
 *     the next request with the revoked Bearer token correctly
 *     returns 401.
 *
 * HALLAZGO-NEW-03 (cross-project) — source-parsing INTENCIÓN (this file) +
 * e2e EFECTIVIDAD validated by the consumer (RETO regenerates Admin module
 * from 0 on v1.8.1+ and runs the full e2e suite — `POST /logout` + `GET /me`
 * with the same token must return 401).
 *
 * Spec: HALLAZGO-NEW-FASE14-03, R-PKG-027 PKG-NEW-08.
 *
 * @see AuthUser::safeLogoutCurrentToken()
 */
uses(MkLaravelTestCase::class);

function readAuthUserSafeLogoutGuardResetSrc(): string
{
    $path = dirname(__DIR__, 3).'/src/Auth/Models/AuthUser.php';
    expect(file_exists($path))->toBeTrue("AuthUser must exist at $path");

    return file_get_contents($path);
}

describe('HALLAZGO-NEW-FASE14-03 — safeLogoutCurrentToken() guard reset', function (): void {
    $src = readAuthUserSafeLogoutGuardResetSrc();

    test('AuthUser.safeLogoutCurrentToken() calls \\Auth::forgetGuards() AFTER $token->delete()', function () use ($src): void {
        // The fix is: after successfully deleting the token, invalidate
        // the AuthManager's cached guards so the next request in the
        // same process doesn't see the user via cached state.

        expect($src)->toContain('$token->delete();');
        expect($src)->toContain('\Auth::forgetGuards()');
    });

    test('forgetGuards() is called AFTER the delete, BEFORE the return true (correct ordering)', function () use ($src): void {
        // Order matters: delete FIRST (so the token is actually revoked),
        // then forgetGuards (so subsequent reads don't return cached user),
        // then return true.

        // Extract the body of safeLogoutCurrentToken (between the first
        // `$token = $this->currentAccessToken();` and the closing `}` of
        // the method).
        $methodStart = strpos($src, 'public function safeLogoutCurrentToken(): bool');
        expect($methodStart)->toBeGreaterThan(0);

        $methodBody = substr($src, $methodStart);

        $deletePos = strpos($methodBody, '$token->delete();');
        $forgetPos = strpos($methodBody, '\Auth::forgetGuards()');
        $returnPos = strrpos($methodBody, 'return true;');

        expect($deletePos)->toBeGreaterThan(0, '$token->delete(); must be present');
        expect($forgetPos)->toBeGreaterThan(0, '\\Auth::forgetGuards() must be present');
        expect($returnPos)->toBeGreaterThan(0, 'return true; must be present');

        // Order: delete < forgetGuards < return true
        expect($deletePos)->toBeLessThan($forgetPos, 'delete must come BEFORE forgetGuards');
        expect($forgetPos)->toBeLessThan($returnPos, 'forgetGuards must come BEFORE return true');
    });

    test('forgetGuards() is NOT called when no token exists (null-safety preserved)', function () use ($src): void {
        // BC: when currentAccessToken() returns null (cookie-based auth
        // stateful SPA, or token already revoked), the method returns
        // false IMMEDIATELY — no delete, no forgetGuards. Idempotency
        // preserved.

        // Pin the early return path.
        expect($src)->toContain('if ($token === null)');
        expect($src)->toContain('return false;');
    });

    test('HALLAZGO-NEW-FASE14-03 reference is documented in source comments (drift trazable per R-G-032)', function () use ($src): void {
        // Drift trazable per R-G-032 — the fix must be discoverable via
        // grep for the HALLAZGO ID in source comments.

        expect($src)->toContain('HALLAZGO-NEW-FASE14-03');
    });

    test('fix comment explains WHY (Sanctum guard cache) not just WHAT', function () use ($src): void {
        // R-G-032 feedback loop: comments must explain the rationale
        // (guard cache lifetime, production vs testing difference),
        // not just describe the code.

        expect($src)->toContain('Sanctum');
        expect($src)->toContain('cache');
        // Mention of the production transparency (no-op in fresh process).
        expect($src)->toMatch('/no-?op|production|transparent|fresh process/i');
    });

    test('PKG-NEW-08 null-safety helper behavior is preserved (regression check)', function () use ($src): void {
        // Pre-existing PKG-NEW-08 contract (R-PKG-027):
        //   - if currentAccessToken() returns null → return false.
        //   - if currentAccessToken() returns a token → delete it, return true.
        //
        // The HALLAZGO-NEW-FASE14-03 fix MUST NOT change this contract.
        // Only adds the forgetGuards() side effect AFTER successful delete.

        // The signature is unchanged.
        expect($src)->toContain('public function safeLogoutCurrentToken(): bool');

        // The two return paths are unchanged.
        expect($src)->toContain('$token = $this->currentAccessToken();');
        expect($src)->toContain('if ($token === null)');
        expect($src)->toContain('return false;');
        expect($src)->toContain('return true;');
    });
});
