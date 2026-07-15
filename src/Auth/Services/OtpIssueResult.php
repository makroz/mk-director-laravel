<?php

declare(strict_types=1);

namespace Mk\Director\Auth\Services;

use Illuminate\Support\Carbon;

/**
 * Result of `EmailOtpService::issue()`.
 *
 * `$plainCode` is the ONLY place the plaintext PIN exists outside the
 * caller's event payload — it is never persisted (the table holds only
 * `code_hash`) and MUST never be logged. See design.md ADR-2 + ADR-4 R4.
 */
final class OtpIssueResult
{
    public function __construct(
        public readonly string $plainCode,
        public readonly Carbon $expiresAt,
    ) {}
}
