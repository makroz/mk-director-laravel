# 1.2.2-hardening — Proposal

**Sprint ID**: `2026-06-18-1.2.2-hardening`
**Branch**: `makromania/260618-1230--laravel-1.2.2-hardening`
**Source-of-truth repo**: `makroz/mk-director-laravel` (sub-repo bajo `projects/mk-director/packagist/mk-director-laravel/`)
**Base**: `origin/dev` @ `fa4a466` (PR #4 mergeado, tag `v1.2.0-rc1`)
**Mode**: RC + sandbox validation (NO publish a Packagist)

---

## Why

El sprint anterior `2026-06-17-laravel-4r-fixes` cerró 22 hallazgos críticos (10 P0 + 12 High) del audit 4R y dejó la base `1.2.0-rc1` razonablemente sana. Sin embargo, el mismo audit identificó **35 hallazgos Medium/Low** que no eran bloqueantes para el RC pero que sí son deuda técnica que crece con el uso:

- **Defensa en profundidad insuficiente** (R2-008, R2-009, R2-010): mass-assignment por `$guarded = []`, `MkMultiTenantPlugin::$tenantColumn` apunta a columnas sensibles, `BaseController::getDebugData` filtra EXPLAIN+bindings sin gate de role.
- **Sin tooling de calidad** (R1-003, R1-004): `phpstan` declarado en `composer.json:scripts` pero NO instalado; sin Pint/PHP-CS-Fixer → PSR-12 no se enforce en CI.
- **Performance leak residual** (R4-004): `DB::listen` corre en CADA query (incluyendo reads del sistema) → overhead innecesario en producción.
- **Riesgo cross-tenant residual** (R3-014): `role_user.user_id` sin FK a `auth_users.id` — silencio del motor de DB ante IDs huérfanos.

Estos 8 (los enumerados explícitamente en el scratchpad de la sesión anterior) son los que tocan este sprint. Los 27 restantes se agregan solo si emergen al leer código (regla T9.* discovery, ver tasks.md).

El objetivo NO es llegar a 1.2.2 production-ready. Es **endurecer lo que ya funciona** sin introducir regresiones y dejar el camino limpio para 1.3.0.

## What changes

- **Nuevo**: `mk:security-lint` (artisan command) que escanea `src/Models/**/*.php` y reporta: `$guarded = []`, modelos sin FK declarada, `$tenantColumn` apuntando a columnas fuera de whitelist.
- **Cancelado**: `phpstan`+`larastan` (R1-003) — Larastan 3.10.0 es la única versión compatible con Laravel 13 y crashea con `Undefined constant LARAVEL_VERSION`. Diferido a 1.3.0.
- **Nuevo**: Pint configurado (PSR-12) + scripts `composer.json` `lint` y `lint:fix`.
- **Modifica**: `MkServiceProvider::registerGlobalCacheListener` → filtra queries del sistema (migrations, cache table, info_schema) + solo actúa en writes (`insert`, `update`, `delete`).
- **Modifica**: `BaseController::getDebugData` → si `mk_director.debug=true`, exige `auth_scope in [super-admin, dev]`; si no, retorna array vacío (no leak).
- **Modifica**: Migration `role_user` → agregar FK `user_id` → `auth_users.id` con try/catch en `down()` para installs donde la tabla ya existe sin FK.
- **Docs**: `docs/HARDENING_1.2.2.md` con rationale de cada cambio + `CHANGELOG.md` con entries R-NEW-001 compliant (cada `sec:` claim tiene diff a `src/` + test verde).

## Scope

**In-scope** (7 hallazgos cerrados):
- R1-004 Pint + composer scripts
- R2-007/R4-004 DB::listen solo en writes + filter system tables (combinado)
- R2-008 `$guarded = []` detection (vía `mk:security-lint`)
- R2-009 `$tenantColumn` whitelist detection (vía `mk:security-lint`)
- R2-010 `BaseController::getDebugData` role-gated
- R3-014 `role_user.user_id` FK a `auth_users.id`
- T9.* discovery: cualquier Medium/Low adicional que aparezca al leer código (max 3 hallazgos, priorizados por criticidad)

**Out-of-scope** (diferido a 1.3.0):
- R1-001 ADR `Contracts/` vs `Api/` (requiere decisión arquitectónica previa)
- R1-003 phpstan (Larastan incompatible con Laravel 13 al 2026-06-18)
- R1-005 Test end-to-end de `LintBoundariesCommand` (refactor mayor)
- R2-001 reescritura de commit `6303844` (es git history, NO se reescribe)
- R2-002/R2-003 ya cerrados en sprint anterior (re-validar que no haya regresión)
- R2-004/R2-005 ya cerrados en sprint anterior (re-validar que no haya regresión)
- R3-013 ya cerrado (UPGRADE_1.2.md existe)
- R4-001/R4-002 ya cerrados (re-validar tests)
- R4-005/R4-006 ya cerrados (OpenApi cache + ModuleProviderRegistry)
- 27 Medium/Low adicionales del audit 4R (T9.* discovery los aborda si emergen; resto queda para 1.3.0)

## Strategy

1. **PR único a dev** (no stacked) — el sprint es cohesivo, todo va junto.
2. **Tag `v1.2.2-rc1` push pero NO publish** a Packagist — Mario corre `composer publish` después de validar en `apps/sandbox-laravel`.
3. **Worktree del sub-repo** se hace en `packagist/mk-director-laravel/` (no en `packages/mk-laravel/` que ya no existe).
4. **Cada commit atómico + R-NEW-001 compliant**: si el mensaje empieza con `sec:` o `fix(security)`, debe tener (a) diff real a `src/`, (b) test que falla sin el fix, (c) `pest` verde.
5. **CI gap fix**: el monorepo NO corre `pest` del sub-repo (lección del sprint anterior). Como parte de T8.1, agregar job `pest-laravel` en `.github/workflows/ci.yml` del monorepo.

## Success criteria

- 8 hallazgos cerrados con `pest` verde (mínimo 2 nuevos tests: uno para `mk:security-lint`, uno para `BaseController` debug gate).
- `composer lint` corre sin errores (Pint) — los diffs aplicados por Pint cuentan como "drift mecánico" y NO se mezclan con commits `sec:`/`fix(security):`.
- `composer analyse` corre (phpstan) y el baseline está commiteado.
- `CHANGELOG.md` actualizado con entries R-NEW-001 compliant.
- Tag `v1.2.2-rc1` pusheado a `origin/dev` (NO a Packagist).
- CI del monorepo incluye job `pest-laravel` y pasa.

## Out-of-sprint artifacts (creados por este proposal, NO son código de release)

- `openspec/changes/2026-06-18-1.2.2-hardening/` (esta carpeta)
- `openspec/changes/2026-06-18-1.2.2-hardening/specs/security-lint.md` (nuevo spec)
- Commit de gitlink update en monorepo (apunta a `fa4a466`)
