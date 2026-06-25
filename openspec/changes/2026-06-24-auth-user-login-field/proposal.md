# R-PKG-009 — mk:make:auth-user --login-field=<campo>

**Sprint ID**: `2026-06-24-auth-user-login-field`
**Parent**: `2026-06-24-dogfooding-model` (R-G-033)
**Branch**: `makromania/260624-2200--auth-user-login-field` (a crear)
**Base**: `origin/dev` @ v1.4.0
**Status**: design

---

## Why

`mk:make:auth-user {Scope}` actualmente hardcodea `email` como campo de login en:

1. **AuthUser base model** (`src/Auth/Models/AuthUser.php`) — asume `email` para authentication.
2. **AuthController generated** — `Admin::query()->where('email', ...)` hardcoded.
3. **LoginRequest generated** — validation rules con `'email' => 'required|email'`.
4. **Migration generated** — columna `email` siempre presente.

RETO necesita `ci` (cédula de identidad, Bolivia). Otros casos futuros:
- India: `aadhaar` (12 dígitos)
- China: `id_card` (18 caracteres)
- USA: `ssn` (formato XXX-XX-XXXX)
- genérico: `username` (cualquier string)
- OTP-only: `phone` (sin password, pero ese es scope aparte)

**Hipótesis**: el campo de login es un detalle de cada scope, no del framework. El paquete debe ser agnóstico al campo específico.

---

## What changes

### Nuevo flag `--login-field`

```bash
# Default (backward compatible): email
php artisan mk:make:auth-user Admin

# Campo custom
php artisan mk:make:auth-user Admin --login-field=ci
php artisan mk:make:auth-user Member --login-field=phone
php artisan mk:make:auth-user Customer --login-field=username
```

### Cambios en stubs generados

**Model** (`app/Modules/{Scope}/Models/{Scope}.php`):
```php
class Admin extends AuthUser
{
    // El campo `login_field` (default `email`) se almacena en `$loginField`
    // y la columna en DB se llama igual.
    protected string $loginField = 'ci';  // configurable via --login-field

    protected $fillable = ['name', 'ci', 'password'];  // depende del flag
}
```

**Migration** (`..._create_admins_table.php`):
```php
Schema::create('admins', function (Blueprint $table) {
    $table->id();
    $table->string('ci')->unique();  // columna llamada según --login-field
    $table->string('name');
    $table->string('password');
    // ...
});
```

**AuthController** (`Http/Controllers/AuthController.php`):
```php
public function login(LoginRequest $request): ...
{
    $credentials = $request->validated();
    $field = config('mk_director.auth.login_field', 'email');

    $admin = Admin::query()
        ->where($field, $credentials[$field])  // NO hardcoded `email`
        ->where('auth_scope', Admin::AUTH_SCOPE)
        ->first();
    // ...
}
```

**LoginRequest** (`Http/Requests/LoginRequest.php`):
```php
public function rules(): array
{
    return [
        config('mk_director.auth.login_field', 'email') => ['required', 'string'],
        'password' => ['required', 'string'],
    ];
}
```

### AuthUser base model

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

### Config global

`config/mk_director.php`:
```php
'auth' => [
    'login_field' => env('MK_LOGIN_FIELD', 'email'),  // default retrocompatible
],
```

### Constraints

- El campo DEBE ser `string` (no int, no json).
- El campo DEBE tener constraint `unique` en la migration.
- Validación de formato (email, regex, etc.) es responsabilidad del consumer via LoginRequest override.

---

## Scope

### In-scope
- Flag `--login-field=<field>`.
- Stubs actualizados (model, migration, controller, request).
- AuthUser base agnóstico.
- Tests Pest con múltiples campos (email, ci, phone, username).
- R-G-032 sync.

### Out-of-scope
- Validación de formato específica del campo (regex CI, formato email) — el consumer agrega via override.
- Múltiples campos de login (email O ci) — Q1.B, futuro.
- OAuth/SSO providers — scope aparte.

---

## Success criteria

1. `php artisan mk:make:auth-user Admin --login-field=ci` genera stubs con `ci` en lugar de `email`.
2. `php artisan mk:make:auth-user Admin` (sin flag) genera idéntico a v1.4.0 (BC).
3. Tests Pest verifican 4 campos diferentes (email, ci, phone, username).
4. RETO puede regenerar su Admin con `--login-field=ci` y login funciona.

---

## Anti-patterns

- ❌ Asumir formato del campo (email format, regex CI) — agnóstico, consumer customiza.
- ❌ Asumir que el campo se llama `email` en DB — usa el nombre del flag.
- ❌ Asumir que el campo es int, json, etc. — solo string.

---

## Cross-references

- Parent: `openspec/changes/2026-06-24-dogfooding-model/`
- Source: rama RETO huérfana + observación directa del paquete actual.
- Regla: `~/.makromania/agency/global/rules_orchestration.md` § R-G-033
