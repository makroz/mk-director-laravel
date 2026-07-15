<?php

declare(strict_types=1);

namespace Mk\Director\Auth\Services;

/**
 * Typed verdict returned by `EmailOtpService::verify()`.
 *
 * Spec: 2026-07-15-profile-edit-password-otp, ADR-2 (design.md).
 *
 * Lets `BaseAuthController` map each verdict to a distinct HTTP status
 * without leaking string comparisons:
 *   - Confirmed → 200 (password updated, code consumed).
 *   - Invalid   → 422 (wrong PIN, attempts incremented, generic message).
 *   - Expired   → 410/422 ("Código expirado").
 *   - Locked    → 423/429 ("Demasiados intentos").
 *   - NotFound  → 422 (no live code for the tuple, OR the code was already
 *                 consumed — both map to the SAME verdict so a second
 *                 confirm attempt with a stale plaintext code cannot be
 *                 used to distinguish "never existed" from "already used").
 */
enum OtpVerifyResult: string
{
    case Confirmed = 'confirmed';
    case Invalid = 'invalid';
    case Expired = 'expired';
    case Locked = 'locked';
    case NotFound = 'not_found';
}
