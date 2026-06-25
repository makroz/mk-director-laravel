# R-PKG-012: Profile fields with custom types (v1.6.0-rc1)

> **Sprint**: `2026-06-25-profile-fields-types`
> **Parent**: `2026-06-25-profile-fields-per-scope` (R-PKG-011)
> **Rule**: R-G-033-A (genérico)
> **Target release**: v1.6.0-rc1

---

## Contexto

R-PKG-011 (`v1.5.0-rc5`) introdujo `--profile-fields=name,dni,phone` pero **todos los campos son `string`**. Esto cubre el 90% de los casos (RETO Bolivia Admin con `name,dni,phone`), pero hay gaps reales:

- **Fechas**: `birthdate`, `registered_at`, `expires_at` necesitan `date` / `datetime`, no `string`. Sin esto, el consumer tiene que editar la migration + model a mano.
- **Números**: `age`, `score`, `price`, `count` necesitan `int` o `decimal`. Como string, no se pueden hacer queries con `>`, `<`, `SUM()`, etc. sin CAST.
- **Booleanos**: `active`, `verified`, `subscribed` necesitan `bool`. Como string, ocupa espacio y es ambiguo (`"0"` vs `"false"`).
- **Textos largos**: `biography`, `description`, `notes` necesitan `text` (sin max:255).
- **JSON**: `metadata`, `preferences`, `settings` necesitan `json`. Como string, hay que `json_decode` en cada lectura.

Cada consumer termina escribiendo esos tipos a mano en migration + model + validación. Es exactamente el patrón que R-PKG-011 quería eliminar.

## Goal

Extender `--profile-fields` para aceptar **tipos custom** con sintaxis `key:type`:

```bash
# v1.5.0-rc5 (BC): todos string
php artisan mk:make:auth-user Admin --profile-fields=name,dni,phone

# v1.6.0-rc1 (NEW): tipos custom
php artisan mk:make:auth-user Admin --profile-fields=name:string,birthdate:date,age:int,biography:text,active:bool
```

Default `string` cuando no se especifica tipo (BC con R-PKG-011).

## Scope

### In scope (v1.6.0-rc1)

8 tipos soportados, cada uno con migration column + model cast + validation rule:

| Tipo | Migration | Model cast | Validation rule |
|---|---|---|---|
| `string` | `$table->string('x')->nullable()` | (sin cast, default) | `['required', 'string', 'max:255']` |
| `text` | `$table->text('x')->nullable()` | (sin cast) | `['required', 'string']` |
| `int` | `$table->integer('x')->nullable()` | `'integer'` | `['required', 'integer']` |
| `decimal` | `$table->decimal('x', 8, 2)->nullable()` | `'decimal:2'` | `['required', 'numeric']` |
| `bool` | `$table->boolean('x')->nullable()` | `'boolean'` | `['required', 'boolean']` |
| `date` | `$table->date('x')->nullable()` | `'date'` | `['required', 'date']` |
| `datetime` | `$table->dateTime('x')->nullable()` | `'datetime'` | `['required', 'date']` |
| `json` | `$table->json('x')->nullable()` | `'array'` | `['required', 'array']` |

Interacción con flags existentes: ortogonal con `--login-field`, `--with-auth-rbac`, `--verify-email`. Combinables en cualquier subconjunto.

### Out of scope (futuros sprints)

- `file` / `avatar`: storage uploads con S3/R2/R2 pluggable → R-PKG-013+
- `enum`: Laravel 11+ `Rule::enum()`, requiere analizar impacto en MME → R-PKG-014+
- `decimal` con precisión custom (e.g. `decimal:10,4`): post-RC si RETO lo necesita
- `uuid` / `ulid`: PKs custom → ortogonal, R-PKG-015+
- Tipos array indexados (`int[]`, `string[]`): no es un caso de uso común en Laravel

## Dogfooding source

**RETO Bolivia Member scope** (futuro, post-rc5 → GA):

```bash
php artisan mk:make:auth-user Member \
  --login-field=email \
  --with-auth-rbac \
  --profile-fields=name:string,phone:string,birthdate:date,active:bool,registered_at:datetime,metadata:json
```

Sin R-PKG-012, RETO tendría que editar manualmente:
- Migration: agregar `birthdate DATE NULL`, `active TINYINT(1) NULL`, `registered_at DATETIME NULL`, `metadata JSON NULL`
- Model: agregar `'birthdate' => 'date'`, `'active' => 'boolean'`, `'registered_at' => 'datetime'`, `'metadata' => 'array'`
- Validation: `['required', 'date']`, `['required', 'boolean']`, etc.

**Cualquier app multi-vertical** también se beneficia: fintech (KYC dates + amounts), healthcare (medical history dates + booleans), social (bio text + metadata JSON), e-commerce (price decimal + stock int).

## R-G-033-A compliance

- **≥ 2 dominios bounded**: cualquier app que tenga `date`/`datetime`/`int`/`bool` en su modelo (prácticamente todas). ✓
- **Testeable sin RETO**: source-parsing tests del command + stubs (mismo patrón que R-PKG-009/010/011). ✓
- **Doc sin RETO**: "Profile fields with custom types" genérico. RETO solo en "Dogfooding source" (interno). ✓

## Decisiones preliminares (a detallar en design.md)

| ID | Pregunta | Recomendación |
|---|---|---|
| **D1** | ¿Sintaxis `--profile-fields=name:string,birthdate:date` (extensión) o `--profile-fields-types=...` (flag nuevo)? | Extensión de `--profile-fields`. Default `string` cuando no se especifica tipo (BC). |
| **D2** | ¿Tipos custom comparten flag con `--profile-fields` o son flags separados? | Mismo flag. Un solo CSV parseado. |
| **D3** | ¿Tipos case-sensitive? | Sí, lowercase. `String` o `STRING` se rechazan. |
| **D4** | ¿Validación de tipos desconocidos? | Fail-fast con error explícito. Lista cerrada: `string`, `text`, `int`, `decimal`, `bool`, `date`, `datetime`, `json`. |
| **D5** | ¿Decimal con precisión custom? | No en v1.6.0-rc1. Default `8,2`. Post-RC si RETO necesita. |
| **D6** | ¿JSON como string o como array? | Default `array` cast (Laravel moderno). Consumer puede override. |
| **D7** | ¿Validación runtime por tipo? | Sí, table-driven en `register()` y `updateProfile()`. Consumer puede override. |
| **D8** | ¿Date / datetime usan `'date'` rule o más estricto (`'date_format:Y-m-d'`)? | `'date'` loose. Consumer puede override para strict format. |
| **D9** | ¿Interacción con R-PKG-011 (`--profile-fields`)? | Extensión backward-compatible. `--profile-fields=name,dni` (sin tipos) sigue funcionando (default `string`). |
| **D10** | ¿Interacción con `--with-auth-rbac` / `--login-field` / `--verify-email`? | Totalmente ortogonales. 5 flags combinables. |

## Plan

1. ✅ SDD completo (proposal + state.yaml v1 + tasks + specs + design)
2. ⏳ Branch `makromania/260625-1938--r-pkg-012-profile-fields-types` desde `origin/dev`
3. ⏳ T1.1: Extender `MakeAuthUserCommand` signature (no nuevo flag, solo parser más rico)
4. ⏳ T1.2: Nuevo método `resolveProfileFieldType(string $key, string $type)` con validación de tipos
5. ⏳ T1.3: Reemplazar `buildProfileFieldsReplacements()` para usar la table de tipos
6. ⏳ T1.4: Actualizar stubs (migration: tipo de columna, model: cast entry, controller: validation rule)
7. ⏳ T1.5: Tests Pest (MakeAuthUserProfileFieldsTypesTest + extension de AuthUserProfileFieldsTest)
8. ⏳ T1.6: R-G-032 sync packagist-side (CHANGELOG, DEVELOPER_GUIDE § 3.11, README)
9. ⏳ T1.7: R-G-032 sync monorepo-side (GETTING_STARTED, AUTH, API_REFERENCE_LARAVEL)
10. ⏳ T1.8: Skill reference 14 NEW (`references/14-profile-fields-types.md`) + SKILL.md index
11. ⏳ Commit atómico + push + 3 PRs coordinados
12. ⏳ Tag `v1.6.0-rc1` + Packagist verify + state.yaml v3 closed
13. ⏳ Cron watch + memory entry + Telegram

## Cross-repo coordination (3 PRs)

- **makroz/mk-director-laravel#XX** — feat + tests + R-G-032 locations 1-3 + 4 + 4b
- **makroz/MK-Director#XX** — monorepo docs sync (locations 6-8)
- **mariogfos/humandirector#XX** — skill reference 14 (`references/14-profile-fields-types.md`) + SKILL.md index update (locations 4 + 4b)

## Riesgos y mitigación

| ID | Riesgo | Mitigación |
|---|---|---|
| R-RISK-PKG-012-001 | Tipo desconocido (`--profile-fields=name:foo`) | Fail-fast en `resolveProfileFieldType()` con lista cerrada. |
| R-RISK-PKG-012-002 | BC break: app que asume `--profile-fields` solo keys | Extensión backward-compatible (sin `:` = `string`). |
| R-RISK-PKG-012-003 | Cast incorrecto (e.g. `json` como `string` en lugar de `array`) | Tests source-parsing pinean cada tipo con su cast específico. |
| R-RISK-PKG-012-004 | Validation rule incorrecto (e.g. `date` como `string`) | Tests pinean cada tipo con su validation rule. |
| R-RISK-PKG-012-005 | Ortogonalidad rota con R-PKG-011 (`--profile-fields` simple) | Source-parsing test que verifica parsing de ambos formatos. |