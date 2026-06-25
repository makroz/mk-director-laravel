# R-PKG-010 — AuthController stub con RBAC integration

**Sprint ID**: `2026-06-24-auth-controller-rbac-stub`
**Parent**: `2026-06-24-dogfooding-model` (R-G-033)
**Branch**: `makromania/260624-2210--auth-controller-rbac-stub` (a crear)
**Base**: `origin/dev` @ v1.4.0
**Status**: design

---

## Why

El `AuthController` que `mk:make:auth-user` genera hoy (v1.4.0, 187 LOC en sandbox) tiene 6 endpoints custom (login, refresh, logout, me, forgot, reset) pero **ninguno está protegido con RBAC**. RETO escribió manualmente 322 LOC de AuthController con ability checks, audit log y rate limiting. Esto debería venir en el stub.

**El problema**: cualquier consumer que quiera RBAC debe reimplementar la integración manualmente. Eso es exactamente el boilerplate que el paquete debería absorber.

---

## What changes

### AuthController stub mejorado (de 187 LOC → ~250 LOC)

Nuevos elementos:

1. **Constructor con `RbacService` inyectado** (opcional, solo si `rbac_enabled=true`).
2. **Ability checks en endpoints privados** (`/me`, `/logout`):
   ```php
   public function me(Request $request): AdminResource
   {
       $this->authorizeAbility('me', $request->user());  // configurable
       // ...
   }
   ```
3. **Rate limiting en `/login`** (default `throttle:5,1`, configurable).
4. **Audit log automático** vía `MkAuditLoggerPlugin` (si activo):
   - `auth.login.success` con `user_id`, `ip`, `user_agent`.
   - `auth.login.failed` con `email`, `ip`, `user_agent` (sin password).
   - `auth.logout` con `user_id`, `token_id`.
   - `auth.password_reset.requested` con `email`.
5. **Envelope estándar** (`sendResponse`/`sendError`) — ya en v1.4.0, mantener.

### Configuración

`config/mk_director.php`:
```php
'auth' => [
    'login_field' => env('MK_LOGIN_FIELD', 'email'),
    'rbac_enabled' => env('MK_AUTH_RBAC_ENABLED', false),  // BC: default false
    'abilities' => [
        'me' => null,           // null = sin check. O string: 'auth.me.read'
        'logout' => null,
    ],
    'rate_limits' => [
        'login' => '5,1',       // 5 attempts per minute
        'forgot' => '3,1',
        'reset' => '3,1',
    ],
],
```

### Flags del scaffolder

```bash
# Default (BC): sin RBAC, sin rate limit (excepto el default de Laravel)
php artisan mk:make:auth-user Admin

# Habilitar RBAC + rate limit
php artisan mk:make:auth-user Admin --with-auth-rbac
```

### Compatibilidad con v1.4.0

| v1.4.0 | v1.5.0 default | v1.5.0 con `--with-auth-rbac` |
|---|---|---|
| Sin RBAC checks | Sin RBAC checks (BC) | RBAC checks en `/me`, `/logout` |
| Sin rate limit | Sin rate limit (BC) | `throttle:5,1` en `/login` |
| Sin audit log | Sin audit log (BC) | Audit log de login attempts |
| Envelope estándar | Envelope estándar | Envelope estándar |

**BC preservada**: el comportamiento default es idéntico a v1.4.0. Los nuevos features son opt-in.

---

## Scope

### In-scope
- Modificar stub `auth-user.auth-controller.stub` con optional RBAC + rate limit + audit log.
- Flags `--with-auth-rbac`.
- Config global `mk_director.auth.*`.
- Tests Pest con RBAC on/off.
- R-G-032 sync.

### Out-of-scope
- Rate limit avanzado (per-IP, captcha) — scope futuro.
- OAuth providers — scope aparte.
- 2FA / TOTP — scope futuro.

---

## Success criteria

1. Stub generado con `--with-auth-rbac` tiene RBAC checks + rate limit + audit log.
2. Stub sin flag (default) es byte-equal a v1.4.0.
3. Tests Pest verifican ambos modos (BC + RBAC).
4. RETO puede regenerar AuthController con `--with-auth-rbac` y eliminar su impl manual.

---

## Anti-patterns

- ❌ Habilitar RBAC por default (rompe BC) — opt-in.
- ❌ Hardcodear abilities names — configurable.
- ❌ Audit log de passwords — NUNCA loggear passwords, ni hasheados.
- ❌ Rate limit muy agresivo (5/min puede bloquear usuarios reales) — configurable.

---

## Cross-references

- Parent: `openspec/changes/2026-06-24-dogfooding-model/`
- Depends on: R-PKG-009 (login-field)
- Source: rama RETO huérfana + observación directa.
- Regla: `~/.makromania/agency/global/rules_orchestration.md` § R-G-033
