<?php

declare(strict_types=1);

namespace Mk\Director\Auth\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Mk\Director\Plugins\Enterprise\MkAuditLoggerPlugin;

/**
 * AuthEvent — evento emitido por el AuthController cuando `--with-auth-rbac`
 * está habilitado.
 *
 * Consumido por `MkAuditLoggerPlugin` (si está activo) para generar
 * un audit log B2B inmutable de los eventos de autenticación.
 *
 * ## Tipos emitidos (R-PKG-010 ACR-004)
 *
 * | Tipo                          | Payload                                                     |
 * |-------------------------------|-------------------------------------------------------------|
 * | `auth.login.success`          | `{user_id, ip, user_agent, scope}`                          |
 * | `auth.login.failed`           | `{login_field_value, ip, user_agent}` (sin password)        |
 * | `auth.logout`                 | `{user_id, token_id}`                                       |
 * | `auth.refresh.success`        | `{user_id, ip}`                                             |
 * | `auth.password_reset.requested` | `{email, ip}` (login field value, sin importar nombre)    |
 * | `auth.password_reset.success` | `{user_id}`                                                 |
 * | `auth.password_change_code.requested` | `{scope, user_id, code, expires_at, ip}` ⚠️ ver abajo |
 * | `auth.password_changed`       | `{user_id, ip}`                                             |
 * | `auth.two_factor.enabled`     | `{scope, user_id, ip}`                                      |
 * | `auth.two_factor.disabled`    | `{scope, user_id, ip}`                                      |
 * | `auth.two_factor.challenge_failed` | `{scope, user_id, via, ip}` (`via`: `totp`\|`recovery_code`\|`setup`) |
 * | `auth.two_factor.recovery_code_used` | `{scope, user_id, remaining, ip}`                    |
 * | `auth.two_factor.reset`       | `{scope, user_id, actor}` (`mk:auth:two-factor-reset`)      |
 *
 * ## Verificación en dos pasos (§ 3.20)
 *
 * Los cinco eventos de arriba existen para que el consumer avise: un
 * `auth.two_factor.recovery_code_used` con `remaining` bajo es el momento de
 * mandar un email («usaste un código de recuperación, te quedan 2»), y un
 * `auth.two_factor.challenge_failed` repetido es lo que alimenta una alerta.
 * El paquete no manda nada: despacha y el consumer decide.
 *
 * ⚠️ **Ninguno lleva el secreto TOTP ni los códigos de recuperación.** El
 * secreto vive cifrado en la columna del scope y los códigos sólo hasheados;
 * los códigos en claro viajan UNA vez, en el body del response del
 * enrolamiento, y de ahí no los toma ningún evento.
 *
 * ## Privacidad / seguridad
 *
 * **NUNCA** se loggea el password (ni hasheado, ni plano). El payload se
 * sanitiza en el AuthController antes de emitir el evento. Ver
 * `R-PKG-010 ACR-004 anti-patterns`.
 *
 * ⚠️ **`auth.password_change_code.requested` transporta el PIN en claro**
 * (`payload['code']`). Es el ÚNICO punto donde el PIN existe en texto plano:
 * viaja SOLO para que el listener de email lo despache al usuario. Un listener
 * que consuma este evento **NUNCA** debe loggearlo, persistirlo, ni reenviarlo
 * a un canal de auditoría — sería equivalente a loggear una credencial. En DB
 * el código vive únicamente hasheado (bcrypt) en `verification_codes`.
 * El `MkAuditLoggerPlugin` solo audita eventos CRUD, no consume este evento.
 *
 * ## Uso
 *
 * ```php
 * use Mk\Director\Auth\Events\AuthEvent;
 *
 * // En AuthController (generado con --with-auth-rbac):
 * AuthEvent::dispatch('auth.login.success', [
 *     'user_id'    => $admin->id,
 *     'ip'         => $request->ip(),
 *     'user_agent' => $request->userAgent(),
 *     'scope'      => $admin->getAuthScope(),
 * ]);
 *
 * // Listener (consumer-side):
 * public function handle(AuthEvent $event): void
 * {
 *     Log::channel('audit')->info("Auth [{$event->type}]", $event->payload);
 * }
 * ```
 *
 * Spec: R-PKG-010 § ACR-004 — Audit log automático.
 *
 * @see MkAuditLoggerPlugin listener opcional.
 */
final class AuthEvent
{
    use Dispatchable;

    /**
     * @param  string  $type  Uno de los tipos listados en la docblock (ej: `auth.login.success`).
     * @param  array<string, mixed>  $payload  Datos del evento. El caller sanitiza passwords.
     */
    public function __construct(
        public readonly string $type,
        public readonly array $payload = [],
    ) {}
}
