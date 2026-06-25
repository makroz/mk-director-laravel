# Spec: Profile fields with custom types

> **Sprint**: R-PKG-012
> **Spec ID**: MK-LAR-1.6.0-rc1.PFT
> **Status**: draft (R-PKG-012 design phase)

## Overview

Extender `--profile-fields` para aceptar tipos custom via sintaxis `key:type`:

```bash
# v1.5.0-rc5 (BC): todos string
--profile-fields=name,dni,phone

# v1.6.0-rc1: tipos custom
--profile-fields=name:string,birthdate:date,age:int,biography:text,active:bool,registered_at:datetime,metadata:json
```

Default `string` cuando no se especifica tipo (BC con R-PKG-011).

## Requirements

### REQ-1: 8 tipos soportados

Tipos válidos (lowercase, lista cerrada):
- `string` (default, BC con R-PKG-011)
- `text`
- `int`
- `decimal` (precision 8, scale 2 por default)
- `bool`
- `date`
- `datetime`
- `json` (cast como `array`)

Cualquier otro tipo se rechaza con error explícito.

### REQ-2: Sintaxis `key:type`

Cada item del CSV puede ser:
- `key` (sin tipo) → default `string`
- `key:type` → tipo explícito

```bash
--profile-fields=name,age:int,biography:text,active:bool
# name → string (default)
# age → int
# biography → text
# active → bool
```

### REQ-3: Migration column por tipo

Cada tipo genera el column method apropiado en la migration:

| Tipo | Column method | Args | Nullable |
|---|---|---|---|
| `string` | `string` | — | sí |
| `text` | `text` | — | sí |
| `int` | `integer` | — | sí |
| `decimal` | `decimal` | `8, 2` | sí |
| `bool` | `boolean` | — | sí |
| `date` | `date` | — | sí |
| `datetime` | `dateTime` | — | sí |
| `json` | `json` | — | sí |

### REQ-4: Model cast por tipo

Cada tipo genera la entry de cast apropiada:

| Tipo | Cast | Notas |
|---|---|---|
| `string` | (sin cast) | Laravel default |
| `text` | (sin cast) | Laravel default |
| `int` | `integer` | |
| `decimal` | `decimal:2` | Laravel scale-based |
| `bool` | `boolean` | |
| `date` | `date` | Carbon date |
| `datetime` | `datetime` | Carbon datetime |
| `json` | `array` | Laravel moderno |

### REQ-5: Validation rule por tipo

Cada tipo genera el validation rule apropiado en `register()` y `updateProfile()`:

| Tipo | Validation rule |
|---|---|
| `string` | `['required', 'string', 'max:255']` |
| `text` | `['required', 'string']` |
| `int` | `['required', 'integer']` |
| `decimal` | `['required', 'numeric']` |
| `bool` | `['required', 'boolean']` |
| `date` | `['required', 'date']` |
| `datetime` | `['required', 'date']` |
| `json` | `['required', 'array']` |

### REQ-6: Default BC preservado

Sin flag `--profile-fields`, comportamiento idéntico a v1.5.0-rc5 (sin profile fields).

Con `--profile-fields=name,dni` (sin tipos), se interpretan como `name:string,dni:string`. Output idéntico a v1.5.0-rc5.

### REQ-7: Validación de tipos desconocidos

Si el tipo no está en la lista cerrada, el scaffolder rechaza con error:

```
El tipo "foo" no está soportado. Tipos válidos: string, text, int, decimal, bool, date, datetime, json.
```

### REQ-8: Ortogonalidad con flags existentes

Los 5 flags del command son combinables:

- `--login-field`
- `--with-auth-rbac`
- `--profile-fields` (con o sin tipos)
- `--verify-email`

Cualquier subset de los 5 es válido (excepto `--verify-email` con `--login-field != email` per ADR-009 de R-PKG-011).

## Scenarios

### SCENARIO-1: RETO Bolivia Member scope (futuro, post-rc5 → GA)

```bash
php artisan mk:make:auth-user Member \
  --login-field=email \
  --with-auth-rbac \
  --profile-fields=name:string,phone:string,birthdate:date,active:bool,registered_at:datetime,metadata:json
```

Expected:
- Tabla `members` con columnas: `id, name, email, phone, birthdate (DATE NULL), active (TINYINT NULL), registered_at (DATETIME NULL), metadata (JSON NULL), password, email_verified_at (si --verify-email), auth_scope, remember_token, timestamps`.
- Member model `$casts`: `email_verified_at => datetime, birthdate => date, active => boolean, registered_at => datetime, metadata => array, password => hashed`.
- Member model `$fillable`: `['name', 'email', 'phone', 'birthdate', 'active', 'registered_at', 'metadata', 'password', 'auth_scope', 'client_id']`.
- Validation en `register()` y `updateProfile()`: cada field con su rule apropiado.

### SCENARIO-2: Default BC

```bash
php artisan mk:make:auth-user Admin
# Output: idéntico a v1.5.0-rc5.

php artisan mk:make:auth-user Admin --profile-fields=name,dni,phone
# Output: idéntico a R-PKG-011 (todos string).
```

### SCENARIO-3: Mixed tipos (con y sin tipo)

```bash
php artisan mk:make:auth-user Admin --profile-fields=name,age:int,active:bool
# name → string (default)
# age → int
# active → bool
```

### SCENARIO-4: Tipo desconocido

```bash
php artisan mk:make:auth-user Admin --profile-fields=score:varchar
# ERROR: El tipo "varchar" no está soportado. Tipos válidos: string, text, int, decimal, bool, date, datetime, json.
```

### SCENARIO-5: Ortogonalidad con verify-email

```bash
php artisan mk:make:auth-user Admin \
  --profile-fields=birthdate:date,age:int \
  --verify-email
# Ambos flags activos, sin interacción inesperada.
```

## Cross-references

- **R-PKG-011** (`--profile-fields` base): R-PKG-012 es extensión backward-compatible. Default `string` cuando no se especifica tipo.
- **R-PKG-009** (`--login-field`): ortogonal.
- **R-PKG-010** (`--with-auth-rbac`): ortogonal.
- **R-RET-001** (Retrofit RETO Admin/Member): R-PKG-012 habilita Member scope con tipos custom (birthdate, active, registered_at, metadata).