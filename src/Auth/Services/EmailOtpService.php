<?php

declare(strict_types=1);

namespace Mk\Director\Auth\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * EmailOtpService — reusable email-OTP primitive: generate, hash, store,
 * expire, throttle and single-use-consume verification codes for any
 * `purpose` (today: `password_change`; future: `login_2fa`, `email_verify`).
 *
 * Spec: 2026-07-15-profile-edit-password-otp
 *   - Domain: email-otp-verification (spec.md).
 *   - ADR-1 (data model) + ADR-2 (this service's API) in design.md.
 *
 * [SECURITY] Scope-agnostic, table-name-agnostic. The plaintext PIN is
 * returned ONCE from `issue()` (inside `OtpIssueResult`) so the caller can
 * hand it to the `AuthEvent` bus for email delivery — it is NEVER persisted
 * (only `code_hash` is stored, via `Hash::make`) and NEVER logged by this
 * class.
 *
 * Registered as a singleton in `AuthServiceProvider`, resolved by
 * `BaseAuthController::emailOtpService()` — mirrors `TokenIssuer`.
 */
class EmailOtpService
{
    public function __construct(
        private readonly string $table = 'verification_codes',
    ) {}

    /**
     * Generates a numeric PIN, hashes + persists it (replacing any live
     * code for the same `(auth_scope, purpose, identifier)` tuple), and
     * returns the PLAINTEXT once.
     */
    public function issue(string $scope, string $purpose, string $identifier): OtpIssueResult
    {
        $length = $this->configInt('mk_director.auth.otp.length', 6);
        $ttlSeconds = $this->configInt('mk_director.auth.otp.ttl_seconds', 600);
        $maxAttempts = $this->configInt('mk_director.auth.otp.max_attempts', 5);

        $plainCode = $this->generatePin($length);
        $expiresAt = now()->addSeconds($ttlSeconds);

        DB::table($this->table)->updateOrInsert(
            [
                'auth_scope' => $scope,
                'purpose' => $purpose,
                'identifier' => $identifier,
            ],
            [
                'id' => (string) Str::uuid(),
                'code_hash' => Hash::make($plainCode),
                'attempts' => 0,
                'max_attempts' => $maxAttempts,
                'expires_at' => $expiresAt,
                'consumed_at' => null,
                'created_at' => now(),
            ],
        );

        return new OtpIssueResult($plainCode, $expiresAt);
    }

    /**
     * Verifies a submitted code against the live record for the tuple.
     *
     * Order of checks (mirrors the HTTP-status mapping in
     * `BaseAuthController::confirmPasswordCode()`):
     *   1. No row, or the row was already consumed → NotFound (do not
     *      distinguish "never existed" from "already used" — anti-oracle).
     *   2. Expired → Expired, even if the submitted code is correct.
     *   3. Attempts already at/over cap → Locked, even if correct.
     *   4. Hash mismatch → increments `attempts`, returns Invalid
     *      (generic — no hint about the correct value).
     *   5. Match → marks `consumed_at`, returns Confirmed (single-use).
     */
    public function verify(string $scope, string $purpose, string $identifier, string $code): OtpVerifyResult
    {
        $row = DB::table($this->table)
            ->where('auth_scope', $scope)
            ->where('purpose', $purpose)
            ->where('identifier', $identifier)
            ->orderByDesc('created_at')
            ->first();

        if (! $row || $row->consumed_at !== null) {
            return OtpVerifyResult::NotFound;
        }

        if (now()->greaterThan($row->expires_at)) {
            return OtpVerifyResult::Expired;
        }

        if ((int) $row->attempts >= (int) $row->max_attempts) {
            return OtpVerifyResult::Locked;
        }

        if (! Hash::check($code, (string) $row->code_hash)) {
            DB::table($this->table)->where('id', $row->id)->increment('attempts');

            return OtpVerifyResult::Invalid;
        }

        DB::table($this->table)->where('id', $row->id)->update(['consumed_at' => now()]);

        return OtpVerifyResult::Confirmed;
    }

    /**
     * Request-side throttle guard: has this identifier requested a code
     * too recently for the same `purpose`?
     *
     * Defense-in-depth over the route-level `throttle:` middleware
     * (ADR-2). `issue()` replaces the live row on every call (single row
     * per tuple, ADR-1), so this reads as "was the last issued code
     * created within the throttle window" — effectively a max-1-per-window
     * guard given the storage shape. `throttle.max` is read for forward
     * compatibility with a future multi-request counter store; the current
     * single-row-per-tuple table only supports the window check.
     */
    public function isRequestThrottled(string $scope, string $purpose, string $identifier): bool
    {
        $windowSeconds = $this->configInt('mk_director.auth.otp.throttle.window_seconds', 600);

        $row = DB::table($this->table)
            ->where('auth_scope', $scope)
            ->where('purpose', $purpose)
            ->where('identifier', $identifier)
            ->orderByDesc('created_at')
            ->first();

        if (! $row || ! $row->created_at) {
            return false;
        }

        return now()->lessThan(
            \Illuminate\Support\Carbon::parse($row->created_at)->addSeconds($windowSeconds),
        );
    }

    /**
     * Maintenance: deletes expired or already-consumed rows. Intended to
     * be called from a future scheduled command (not wired in this
     * change — see design.md ADR-1 "plus a plain index on expires_at for
     * a future prune command").
     */
    public function prune(): int
    {
        return DB::table($this->table)
            ->where(function ($query) {
                $query->where('expires_at', '<', now())
                    ->orWhereNotNull('consumed_at');
            })
            ->delete();
    }

    private function generatePin(int $length): string
    {
        $max = (10 ** $length) - 1;

        return str_pad((string) random_int(0, $max), $length, '0', STR_PAD_LEFT);
    }

    private function configInt(string $key, int $default): int
    {
        $value = config($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }
}
