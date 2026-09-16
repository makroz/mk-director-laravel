<?php

declare(strict_types=1);

namespace Mk\Director\Auth\Services;

use Illuminate\Support\Carbon;
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
     *
     * Los tres parámetros opcionales existen para las credenciales de un solo
     * uso que NO son un PIN para leer por email (hoy: el desafío y el
     * enrolamiento de dos factores, `purpose` `login_2fa*`):
     *
     *  - `$plainCode`: un valor que arma el caller. Un desafío de login viaja
     *    en el body de una request, no lo tipea una persona, así que son 32
     *    bytes al azar en hexa y no seis dígitos adivinables.
     *  - `$ttlSeconds` / `$maxAttempts`: el TTL de 10 minutos y el tope de 5
     *    del bloque `otp.*` son los del PIN de email. Un desafío de login vive
     *    minutos, no diez, y su tope cuenta intentos de OTRA credencial (el
     *    código del autenticador).
     *
     * Sin parámetros, el comportamiento es exactamente el de antes.
     */
    public function issue(
        string $scope,
        string $purpose,
        string $identifier,
        ?string $plainCode = null,
        ?int $ttlSeconds = null,
        ?int $maxAttempts = null,
    ): OtpIssueResult {
        $length = $this->configInt('mk_director.auth.otp.length', 6);
        $ttlSeconds ??= $this->configInt('mk_director.auth.otp.ttl_seconds', 600);
        $maxAttempts ??= $this->configInt('mk_director.auth.otp.max_attempts', 5);

        $plainCode ??= $this->generatePin($length);
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
     *   4. Atomically reserves one attempt (`attempts + 1` only while not
     *      consumed and under the cap); if no row was updated, a concurrent
     *      submission took the last attempt → Locked.
     *   5. Hash mismatch → Invalid (generic — no hint about the correct value).
     *   6. Match → marks `consumed_at` only if still unconsumed; Confirmed only
     *      if THIS call updated the row, else NotFound (single-use under
     *      concurrency: of two parallel correct submissions, one wins).
     *
     * Los pasos 1..5 son {@see reserveAttempt()} y el 6 es {@see consume()};
     * este método es la composición de los dos, que es lo que necesita un PIN
     * de email (el PIN *es* la prueba, así que validarlo y gastarlo es un solo
     * acto). El flujo de dos factores los llama por separado — ver el docblock
     * de `reserveAttempt()`.
     */
    public function verify(string $scope, string $purpose, string $identifier, string $code): OtpVerifyResult
    {
        $verdict = $this->reserveAttempt($scope, $purpose, $identifier, $code);

        if ($verdict !== OtpVerifyResult::Confirmed) {
            return $verdict;
        }

        return $this->consume($scope, $purpose, $identifier, $code)
            ? OtpVerifyResult::Confirmed
            : OtpVerifyResult::NotFound;
    }

    /**
     * Los pasos 1..5 de {@see verify()} SIN consumir la fila: clasifica,
     * reserva un intento y compara el hash.
     *
     * 🔴 POR QUÉ ESTÁ PARTIDO EN DOS.
     *
     * `verify()` acopla «la credencial coincide» con «la credencial se gastó», y
     * eso es exactamente lo que necesita un PIN de email: el PIN *es* la prueba.
     * El desafío de dos factores no: la credencial que se valida acá es el
     * TICKET del login, y la prueba real es el código del autenticador, que se
     * mira después. Si el ticket se consumiera en la primera llamada, un dígito
     * mal tipeado mandaría al usuario a loguearse de nuevo — y el tope de
     * intentos no contaría nada, porque cada intento arrancaría con un ticket
     * nuevo.
     *
     * El intento se reserva ACÁ igual (el WHERE atómico de `verify()`), así que
     * el tope cuenta los intentos del código del autenticador, que es lo que
     * hay que limitar.
     */
    public function reserveAttempt(string $scope, string $purpose, string $identifier, string $code): OtpVerifyResult
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

        // 🔴 Las lecturas de arriba sólo CLASIFICAN; no autorizan. Entre el
        // SELECT y la escritura corre cualquier submission paralela, así que
        // cada escritura re-exige su condición en el WHERE y se cuenta cuántas
        // filas tocó: con 0, otra request ganó la carrera.
        //
        // El intento se reserva ANTES de comparar el hash. Si se incrementara
        // recién al fallar, N adivinanzas paralelas leerían todas
        // `attempts < max` y el tope no limitaría nada.
        $reserved = DB::table($this->table)
            ->where('id', $row->id)
            ->whereNull('consumed_at')
            ->where('attempts', '<', (int) $row->max_attempts)
            ->increment('attempts');

        if ($reserved === 0) {
            return OtpVerifyResult::Locked;
        }

        if (! Hash::check($code, (string) $row->code_hash)) {
            return OtpVerifyResult::Invalid;
        }

        return OtpVerifyResult::Confirmed;
    }

    /**
     * Gasta la credencial: marca `consumed_at` en la fila viva de la tupla.
     * `false` si no hay fila viva, si el código no es el de esa fila, o si otra
     * request ganó la carrera.
     *
     * El uso único se decide con el `WHERE consumed_at IS NULL` y la cuenta de
     * filas afectadas, no con la lectura previa: entre el SELECT y el UPDATE
     * corre cualquier submission paralela.
     *
     * 🔴 Pide el código y lo vuelve a comparar. Sin eso hay una ventana real:
     * `issue()` reemplaza la fila de la tupla y le pone un `id` NUEVO, así que
     * un consume que busque «la fila viva de la tupla» a secas puede quemar una
     * credencial recién emitida en vez de la que se validó.
     */
    public function consume(string $scope, string $purpose, string $identifier, string $code): bool
    {
        $row = DB::table($this->table)
            ->where('auth_scope', $scope)
            ->where('purpose', $purpose)
            ->where('identifier', $identifier)
            ->whereNull('consumed_at')
            ->orderByDesc('created_at')
            ->first();

        if (! $row || ! Hash::check($code, (string) $row->code_hash)) {
            return false;
        }

        return DB::table($this->table)
            ->where('id', $row->id)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]) === 1;
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
            Carbon::parse($row->created_at)->addSeconds($windowSeconds),
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
