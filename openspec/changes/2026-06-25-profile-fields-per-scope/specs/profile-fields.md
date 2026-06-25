# Spec: Profile fields per-scope + email verification opt-in

> **Sprint**: R-PKG-011
> **Spec ID**: MK-LAR-1.5.0-rc5.PF
> **Status**: draft (R-PKG-011 design phase)

## Overview

`mk:make:auth-user` accepts two new opt-in flags:
- `--profile-fields=field1,field2,field3` (CSV of additional columns)
- `--verify-email` (boolean toggle)

Both default to "off" — preserving BC with v1.5.0-rc4.

## Requirements

### REQ-1: Command accepts `--profile-fields` flag

The command MUST accept `--profile-fields=<csv>` where `<csv>` is a comma-separated list of PHP-valid identifiers.

```bash
php artisan mk:make:auth-user Admin --profile-fields=name,dni,phone
```

Each field MUST:
- Be a valid PHP identifier (`/^[a-zA-Z_][a-zA-Z0-9_]*$/`)
- Be unique within the CSV (no duplicates — fail-fast)
- NOT collide with reserved column names: `id`, `password`, `auth_scope`, `client_id`, `loginField`, `remember_token`, `created_at`, `updated_at`, `email_verified_at`

### REQ-2: Command accepts `--verify-email` flag

```bash
php artisan mk:make:auth-user Admin --verify-email
```

The flag MUST be boolean. Default: `false` (no verification).

### REQ-3: Profile fields per scope (MME/R-MK-001 strict)

Each scope MUST have its own table and model:
- `admins` table with Admin::$fillable including `--profile-fields`
- `members` table with Member::$fillable including `--profile-fields`

Profile fields MUST NOT be shared across scopes. A field declared in `Admin --profile-fields=dni` MUST NOT appear in `Member --profile-fields=phone`.

### REQ-4: Default BC preserved

Without either flag, the generated scope MUST be byte-identical to v1.5.0-rc4 generation:
- Model `$fillable` = `['name', '<loginField>', 'password', 'auth_scope', 'client_id']`
- Migration columns = `id, name, <loginField>, password, auth_scope, remember_token, timestamps`
- Routes = login/refresh/forgot/reset + protected logout/me
- No `/register`, no `PATCH /me`, no `/email/verify`

### REQ-5: Profile fields exposed via 3 endpoints

When `--profile-fields` is set:
- `GET /api/{scope}/auth/me` — returns the full user (including profile fields via standard serialization)
- `PATCH /api/{scope}/auth/me` — accepts profile fields as body, validates `required|string|max:255`, updates
- `POST /api/{scope}/auth/register` — accepts profile fields as body, validates same, creates user

Validation rules per profile field MUST be `['required', 'string', 'max:255']`. Consumers MUST override `register()` / `updateProfile()` for custom validation (regex for dni, date for birthdate, etc.).

### REQ-6: Email verification opt-in

When `--verify-email` is set:
- Migration includes `email_verified_at` timestamp column (nullable)
- Model `$casts` includes `'email_verified_at' => 'datetime'`
- Routes include:
  - `GET /api/{scope}/auth/email/verify/{id}/{hash}` (signed URL, public)
  - `POST /api/{scope}/auth/email/resend` (auth:scope required)
- `register()` sends `Illuminate\Auth\Notifications\VerifyEmail` queueable notification
- `verifyEmail()` marks `email_verified_at = now()` on successful signature validation
- `resendVerification()` re-dispatches the notification

When `--verify-email` is NOT set:
- NO `email_verified_at` column
- NO cast entry
- NO verification routes
- NO notification dispatch
- `register()` does NOT trigger verification

### REQ-7: Flags are orthogonal

The 4 flags (`--login-field`, `--with-auth-rbac`, `--profile-fields`, `--verify-email`) MUST be combinable in any subset:

| Flags combination | Result |
|---|---|
| (none) | v1.5.0-rc4 behavior (BC) |
| `--login-field=ci` | Custom login field (R-PKG-009) |
| `--with-auth-rbac` | RBAC + rate limit + audit (R-PKG-010) |
| `--profile-fields=dni,phone` | Custom profile columns (R-PKG-011) |
| `--verify-email` | Email verification (R-PKG-011) |
| `--login-field=ci --with-auth-rbac --profile-fields=dni,phone --verify-email` | Full combo (RETO Bolivia case) |

### REQ-8: Validation errors are explicit

If `--profile-fields` contains invalid input:
- Invalid identifier: `El campo "{field}" no es un identificador PHP válido.`
- Duplicate: `Campo "{field}" duplicado en --profile-fields.`
- Reserved collision: `Campo "{field}" colisiona con columna reservada.`
- Empty CSV (only commas): `--profile-fields no puede estar vacío si se pasa el flag.`

## Scenarios

### SCENARIO-1: RETO Bolivia full combo

```bash
php artisan mk:make:auth-user Admin \
  --login-field=ci \
  --with-auth-rbac \
  --profile-fields=name,dni,phone \
  --verify-email
```

Expected:
- Table `admins` with columns: `id, name, ci, dni, phone, password, email_verified_at, auth_scope, remember_token, timestamps`
- Admin model $fillable: `['name', 'ci', 'dni', 'phone', 'password', 'auth_scope', 'client_id']`
- Admin model $casts: `['email_verified_at' => 'datetime', 'password' => 'hashed']`
- Routes: login (throttled 5,1) + refresh + forgot (3,1) + reset (3,1) + register + email/verify/{id}/{hash} + email/resend + protected logout (RBAC) + me GET (RBAC) + me PATCH
- AuthController: register sends VerifyEmail notification, verifyEmail handles signed URL, resendVerification throttled, me PATCH validates dni/phone as string

### SCENARIO-2: Default BC

```bash
php artisan mk:make:auth-user Customer
```

Expected:
- Table `customers` identical to v1.5.0-rc4 generation
- Customer model identical to v1.5.0-rc4
- Routes identical to v1.5.0-rc4 (no register, no PATCH /me, no verify)
- AuthController identical to v1.5.0-rc4

### SCENARIO-3: MME encapsulation

```bash
php artisan mk:make:auth-user Admin --profile-fields=dni,phone
php artisan mk:make:auth-user Member --profile-fields=phone,birthdate
```

Expected:
- `admins` table has `dni`, `phone`. Does NOT have `birthdate`.
- `members` table has `phone`, `birthdate`. Does NOT have `dni`.
- Admin::$fillable includes `dni`, `phone`. Does NOT include `birthdate`.
- Member::$fillable includes `phone`, `birthdate`. Does NOT include `dni`.

### SCENARIO-4: Verification opt-in

```bash
# Sin --verify-email
php artisan mk:make:auth-user Admin
# Verify email: NO endpoint, NO column, NO notification

# Con --verify-email
php artisan mk:make:auth-user Admin --verify-email
# Verify email: SI endpoint, SI column, SI notification en register
```

### SCENARIO-5: Validation errors

```bash
php artisan mk:make:auth-user Admin --profile-fields=name,dni,name
# ERROR: Campo "name" duplicado en --profile-fields.

php artisan mk:make:auth-user Admin --profile-fields=first-name
# ERROR: El campo "first-name" no es un identificador PHP válido.

php artisan mk:make:auth-user Admin --profile-fields=password
# ERROR: Campo "password" colisiona con columna reservada.
```

## Cross-references

- **R-PKG-009** (`--login-field`): profile fields complement, NOT replace. loginField is for auth, profile-fields are for non-auth attributes.
- **R-PKG-010** (`--with-auth-rbac`): rate limit / audit are orthogonal to profile fields.
- **R-PKG-007** (DiscoverAbilitiesCommand): no interaction — abilities operate on roles, not on profile fields.
- **R-RET-001** (Retrofit RETO Admin): R-PKG-011 enables phase 2+3 of R-RET-001 (re-scaffold Admin with `--profile-fields=name,dni,phone --verify-email`).