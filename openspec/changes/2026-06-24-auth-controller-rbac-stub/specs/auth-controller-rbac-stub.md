# Spec — AuthController stub con RBAC (R-PKG-010)

**Versión**: 0.1 (draft)
**Fecha**: 2026-06-24
**Rule**: R-G-033-A (triage GO)
**SDD**: `openspec/changes/2026-06-24-auth-controller-rbac-stub/`

---

## Requirement: ACR-001 — Flag --with-auth-rbac

**SHALL**: `php artisan mk:make:auth-user {Scope} --with-auth-rbac` genera AuthController con RBAC integration, rate limiting y audit log.

**Default** (sin flag): idéntico a v1.4.0 (sin RBAC, sin rate limit, sin audit log).

---

## Requirement: ACR-002 — Ability checks en endpoints privados

**SHALL** (cuando RBAC enabled): los endpoints `/me` y `/logout` verifican abilities del user antes de proceder.

**Configuración**:
```php
// config/mk_director.php
'auth' => [
    'abilities' => [
        'me' => 'auth.me.read',         // ability requerida
        'logout' => 'auth.logout',       // ability requerida
    ],
],
```

**Comportamiento default**: si `auth.abilities.me = null`, NO se hace check (modo BC).

**Implementación**:
```php
public function me(Request $request): AdminResource
{
    $this->authorizeAbility('me', $request->user());
    // ... resto del método
}

protected function authorizeAbility(string $endpoint, $user): void
{
    $ability = config("mk_director.auth.abilities.{$endpoint}");
    if ($ability === null) {
        return;  // BC mode: sin check
    }
    if (! $user || ! $user->hasAbility($ability)) {
        throw new AuthorizationException("Missing ability: {$ability}");
    }
}
```

---

## Requirement: ACR-003 — Rate limiting en /login

**SHALL** (cuando RBAC enabled): el endpoint `/login` tiene middleware `throttle:{limit},{minutes}`.

**Configuración default**:
```php
'rate_limits' => [
    'login' => '5,1',     // 5 attempts per minute
    'forgot' => '3,1',    // 3 attempts per minute
    'reset' => '3,1',
],
```

**Implementación en routes**:
```php
Route::post('login', [AuthController::class, 'login'])
    ->middleware('throttle:' . config('mk_director.auth.rate_limits.login', '5,1'));
```

---

## Requirement: ACR-004 — Audit log automático

**SHALL** (cuando `MkAuditLoggerPlugin` activo): cada evento de auth se loggea.

**Eventos emitidos**:
- `auth.login.success` con payload `{user_id, ip, user_agent, scope}`.
- `auth.login.failed` con payload `{login_field_value, ip, user_agent}` (sin password).
- `auth.logout` con payload `{user_id, token_id}`.
- `auth.refresh.success` con payload `{user_id, ip}`.
- `auth.password_reset.requested` con payload `{email, ip}`.
- `auth.password_reset.success` con payload `{user_id}`.

**Implementación** (en AuthController):
```php
public function login(LoginRequest $request): ...
{
    // ... validar credentials ...
    if ($failed) {
        event(new AuthEvent('auth.login.failed', ['email' => $credentials['email'], 'ip' => $request->ip()]));
        return $this->sendError('Invalid credentials', 401);
    }
    event(new AuthEvent('auth.login.success', ['user_id' => $admin->id, 'ip' => $request->ip()]));
    // ...
}
```

**`AuthEvent` class** vive en el paquete (`Mk\Director\Auth\Events\AuthEvent`).

---

## Escenarios

### Scenario 1: Default (BC)
```
Given corro `php artisan mk:make:auth-user Admin` (sin --with-auth-rbac)
When el scaffolder termina
Then el AuthController NO tiene ability checks
And NO tiene rate limit middleware
And NO emite audit events
And es byte-equal al AuthController de v1.4.0
```

### Scenario 2: Con RBAC
```
Given corro `php artisan mk:make:auth-user Admin --with-auth-rbac`
And config tiene `auth.abilities.me = 'auth.me.read'`
When el scaffolder termina y corre el server
And un admin sin ability 'auth.me.read' hace GET /api/admin/auth/me
Then recibe 403 Forbidden
And el evento `auth.login.failed` se emite si las credenciales son inválidas
```

### Scenario 3: Rate limit activo
```
Given RBAC enabled con rate_limit `5,1`
When hago 6 requests POST /api/admin/auth/login en 1 minuto
Then los primeros 5 retornan 401 (credenciales inválidas)
And el sexto retorna 429 Too Many Requests
```

### Scenario 4: Audit log
```
Given MkAuditLoggerPlugin activo
When un admin hace login exitoso
Then se emite el evento `auth.login.success`
And el listener de audit log lo persiste
```

---

## Anti-patterns

- ❌ Loggear passwords (ni hasheados) en audit events — NUNCA.
- ❌ Habilitar RBAC por default — rompe BC.
- ❌ Rate limit global (no por scope) — debe ser configurable.
- ❌ Ability check solo en /me — debe ser configurable por endpoint.
- ❌ Audit log sin opt-out — debe poder deshabilitarse por scope.

---

## Cross-references

- SDD: `openspec/changes/2026-06-24-auth-controller-rbac-stub/`
- Depends on: R-PKG-009 (login-field configurable)
- Companion: R-PKG-008 (mk:module --with-rbac)
- Source: rama RETO huérfana + observación directa del paquete.
- Regla: `~/.makromania/agency/global/rules_orchestration.md` § R-G-033
