# Spec — mk:make:auth-user --login-field (R-PKG-009)

**Versión**: 0.1 (draft)
**Fecha**: 2026-06-24
**Rule**: R-G-033-A (triage GO)
**SDD**: `openspec/changes/2026-06-24-auth-user-login-field/`

---

## Requirement: LF-001 — Flag --login-field

**SHALL**: `php artisan mk:make:auth-user {Scope} --login-field=<field>` genera un scope con el campo de login custom.

**Default** (sin flag): `email` (backward compatible con v1.4.0).

**Casos válidos**:
- `email` (default, formato email)
- `ci` (cédula de identidad, Bolivia/España)
- `phone` (E.164 format: +591...)
- `username` (cualquier string, alphanumeric)
- `documento` (genérico, depende del país)
- Cualquier string custom del consumer

---

## Requirement: LF-002 — Stubs parametrizados

**SHALL**: los 4 stubs del scaffolder usan el campo configurable:

| Stub | Cambio |
|---|---|
| `auth-user.model.stub` | `$fillable` incluye `{{loginField}}`, propiedad `$loginField = '{{loginField}}'` |
| `auth-user.migration.stub` | columna con nombre `{{loginField}}` + `->unique()` |
| `auth-user.auth-controller.stub` | `where(config('mk_director.auth.login_field'), ...)` |
| `auth-user.routes.stub` | sin cambios |

---

## Requirement: LF-003 — AuthUser base agnóstico

**SHALL**: `Mk\Director\Auth\Models\AuthUser` provee:

```php
abstract class AuthUser extends Model
{
    protected string $loginField = 'email';

    public function getLoginField(): string
    {
        return $this->loginField;
    }

    public function scopeWhereLoginField(Builder $query, string $value): Builder
    {
        return $query->where($this->loginField, $value);
    }
}
```

**Uso** en controllers generados:
```php
$admin = Admin::query()
    ->whereLoginField($credentials[$admin->getLoginField()])
    ->where('auth_scope', Admin::AUTH_SCOPE)
    ->first();
```

---

## Requirement: LF-004 — Config global + env

**SHALL**: `config/mk_director.php` tiene:

```php
return [
    'auth' => [
        'login_field' => env('MK_LOGIN_FIELD', 'email'),
    ],
];
```

Y `.env` puede override:
```
MK_LOGIN_FIELD=ci
```

---

## Requirement: LF-005 — Constraints

**SHALL**:
- El campo DEBE ser `string` (no int, no json, no array).
- El campo DEBE tener constraint `unique` en la migration generada.
- Validación de formato específica (regex CI, formato email, etc.) es OPTATIVA — el consumer puede agregar via override del LoginRequest.

**SHALL NOT**:
- El paquete NO valida formato (eso es lógica de dominio del consumer).
- El paquete NO asume que el campo es email (backward compatibility es default, no asunción).

---

## Escenarios

### Scenario 1: Default email (BC)
```
Given corro `php artisan mk:make:auth-user Admin` (sin --login-field)
When el scaffolder termina
Then la migration tiene columna `email` unique
And el LoginRequest valida `email` como required|string
And el AuthController hace `where('email', ...)`
```

### Scenario 2: Campo custom ci (RETO)
```
Given corro `php artisan mk:make:auth-user Admin --login-field=ci`
When el scaffolder termina
Then la migration tiene columna `ci` unique
And el LoginRequest valida `ci` como required|string
And el AuthController hace `where('ci', ...)`
And el Model tiene `protected string $loginField = 'ci';`
```

### Scenario 3: BC preservada
```
Given v1.4.0 sin --login-field generaba con `email`
When instalo v1.5.0 y corro `mk:make:auth-user Admin` (sin flag)
Then el output es IDÉNTICO a v1.4.0 (byte-equal diff)
```

### Scenario 4: Multi-campo via env
```
Given `.env` tiene `MK_LOGIN_FIELD=phone`
And corro `mk:make:auth-user Customer --login-field=ci` (flag gana sobre env)
When el scaffolder termina
Then usa `ci` (el flag explícito)
```

---

## Anti-patterns

- ❌ Asumir formato `email` y agregar `'email' => 'required|email'` en LoginRequest — agnóstico.
- ❌ Asumir `int` para campos numéricos (CI, phone) — todo `string`.
- ❌ Hardcodear `email` en AuthController — usa `config()`.
- ❌ Asumir que el campo es único de un país (CI, SSN) — agnóstico.

---

## Cross-references

- SDD: `openspec/changes/2026-06-24-auth-user-login-field/`
- Companion: R-PKG-010 (AuthController stub con RBAC integration)
- Regla: `~/.makromania/agency/global/rules_orchestration.md` § R-G-033
