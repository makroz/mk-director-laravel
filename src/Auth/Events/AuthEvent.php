<?php

declare(strict_types=1);

namespace Mk\Director\Auth\Events;

use Illuminate\Foundation\Events\Dispatchable;

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
 *
 * ## Privacidad / seguridad
 *
 * **NUNCA** se loggea el password (ni hasheado, ni plano). El payload se
 * sanitiza en el AuthController antes de emitir el evento. Ver
 * `R-PKG-010 ACR-004 anti-patterns`.
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
 * @see \Mk\Director\Plugins\Enterprise\MkAuditLoggerPlugin listener opcional.
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