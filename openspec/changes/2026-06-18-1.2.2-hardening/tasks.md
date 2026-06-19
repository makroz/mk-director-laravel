# 1.2.2-hardening — Implementation Tasks

**Total**: 9 tasks (T1.1, T1.2, T1.3, T2.1, T4.1, T4.2, T5.1, T6.1, T8.1) + T9.* discovery slot.
**Convención de commits**:
- `sec(laravel):` o `fix(security):` → DEBE cumplir R-NEW-001 (diff a src/ + test verde + CI pass).
- `chore(laravel):` → drift mecánico (Pint, baseline regenerate). NO se mezcla con `sec:`.
- `feat(laravel):` → nueva feature (mk:security-lint).
- `test(laravel):` → solo tests.
- `docs(laravel):` → solo docs.
- `perf(laravel):` → mejora de performance con medición.

---

## Track 1 — Runtime hardening (security/performance)

### T1.1 — DB::listen solo en writes + filter system tables

**Finding**: R4-004, R2-007
**Files**: `src/MkServiceProvider.php`
**Depends on**: —
**Commit prefix**: `perf(laravel):`

**Steps**:
1. Editar `registerGlobalCacheListener()` para filtrar por regex `^\s*(insert|update|delete)\b` (case insensitive).
2. Agregar array `$systemTables` con `['migrations', 'cache', 'cache_locks', 'sessions', 'password_resets', 'jobs', 'failed_jobs']`.
3. Wrap `Cache::tags()->flush()` con check `Cache::getStore() instanceof TaggableStore`. Si no, log warning y no hacer `Cache::clear()` (evita stampede con file driver).
4. Agregar test Pest `tests/Feature/MkServiceProviderCacheListenerTest.php`:
   - Read query (select) → NO invalida cache
   - Write query a tabla sistema (UPDATE cache SET ...) → NO invalida
   - Write query a tabla de modelo (UPDATE users SET ...) → SÍ invalida (si TaggableStore)
5. Correr `vendor/bin/pest tests/Feature/MkServiceProviderCacheListenerTest.php` → verde.
6. Commit.

**Done when**: `pest tests/Feature/MkServiceProviderCacheListenerTest.php` pasa; commit pusheado; diff revisado manualmente (regex no captura `REPLACE` o `TRUNCATE` — documentar limitación en docblock del método).

### T1.2 — SmartController guarded: WARN advisory

**Finding**: ninguno directo; observabilidad del audit.
**Files**: `src/Console/Commands/MkCheckCommand.php`, `docs/HARDENING_1.2.2.md`
**Depends on**: —
**Commit prefix**: `docs(laravel):`

**Steps**:
1. Editar `MkCheckCommand` para que, al encontrar un `SmartController`, imprima un WARN si la clase no tiene `middleware()` ni `__construct` con auth.
2. Heurística simple: `if (!preg_match('/(middleware|MkAuthenticate|hasRole)/i', $fileContent)) { warn(...); }`
3. Documentar en `docs/HARDENING_1.2.2.md` que `SmartController` no enforce auth por diseño (BC), y que apps deben agregar middleware.
4. Test Pest: `tests/Feature/MkCheckCommandTest.php` con stub controller sin auth → espera WARN.
5. Commit.

**Done when**: WARN aparece al correr `php artisan mk:check` contra un fixture con controller sin auth; test verde.

### T1.3 — BaseController debug gate por role/scope

**Finding**: R2-010
**Files**: `src/Controllers/BaseController.php`, `tests/Unit/BaseControllerDebugGateTest.php`
**Depends on**: —
**Commit prefix**: `sec(laravel):` (cumple R-NEW-001)

**Steps**:
1. Editar `getDebugData()`: agregar Gate 1 (config check, sin cambios) + Gate 2 (role check si `_debug=1`).
2. Test Pest: source-parsing del método (patrón del sprint anterior). NO Mockery chainable.
3. 3 escenarios: (a) config `debug=false` → retorna `[]`; (b) config `debug=true`, user sin `hasRole` → retorna `[]`; (c) config `debug=true`, user con `hasRole('super-admin')` → retorna debug data completo.
4. Commit (mensaje `sec(laravel):`).

**Done when**: tests verdes; el commit tiene diff a `src/Controllers/BaseController.php` (verificable con `git show --stat`); CHANGELOG entry con el claim de seguridad.

---

## Track 2 — Database integrity

### T2.1 — role_user FK a auth_users.id

**Finding**: R3-014
**Files**: `src/Auth/Database/Migrations/2026_06_18_000001_add_fk_role_user_to_auth_users.php` (NUEVO)
**Depends on**: —
**Commit prefix**: `fix(laravel):` (no `sec:` porque es schema integrity, no auth)

**Contexto** (validado leyendo `src/Auth/Database/Migrations/2026_06_10_000004_create_role_user_table.php`):
- Las migrations del sub-repo viven en `src/Auth/Database/Migrations/`, NO en `database/migrations/`. Se publican via `loadMigrationsFrom` en `MkServiceProvider::boot()`.
- `role_user` actual: `role_id` SÍ tiene `->constrained('roles')->cascadeOnDelete()`. `user_id` es `uuid` SIN FK (R3-014).
- `auth_users.id` es `uuid` (línea 22 del migration de auth_users), así que la FK es `->references('id')->on('auth_users')` con tipo UUID (NO `foreignId`).

**Steps**:
1. Crear nueva migration en `src/Auth/Database/Migrations/`: `2026_06_18_000001_add_fk_role_user_to_auth_users.php`.
2. En `up()`: `Schema::table('role_user', fn($t) => $t->foreign('user_id')->references('id')->on('auth_users')->cascadeOnDelete())`.
3. En `down()`: `try { dropForeign(['user_id']); } catch (\Throwable) { /* FK no existía */ }`.
4. Test Pest: source-parsing (lee la migration y verifica que existe el `foreign('user_id')`).
5. Commit.

**Done when**: migration up/down sin errores en sandbox (`sandbox-laravel`); test verde.

---

## Track 4 — Tooling & CI (agrupa R1-004; R1-003 diferido)

### ~~T4.1 — phpstan + baseline~~ (CANCELADO 2026-06-18)

**Finding original**: R1-003
**Status**: **CANCELADO** — Larastan 3.10.0 (única versión compatible con Laravel 13) crashea con `Undefined constant "Larastan\Larastan\LARAVEL_VERSION"` en `LarastanStubFilesExtension.php:25`. El bug es upstream (la constante se movió de `LARAVEL_VERSION` a `\Illuminate\Foundation\Application::VERSION` en Laravel 13).

**Decisión**: Diferir R1-003 a 1.3.0. Cuando Larastan publique release compatible con Laravel 13 (probable 3.11+), retomar.

**Acción para Mario**: si Larastan sigue roto en 1.3.0, evaluar como **opción B**: instalar solo `phpstan/phpstan` (sin Larastan) con `level: 0` para chequeos sintácticos. NO es la solución ideal pero permite cerrar R1-003 "existe la herramienta" sin esperar a Larastan.

**NO-OP en este sprint**: el `composer require`/`remove` ya se ejecutó y revirtió. `composer.lock` y `composer.json` están en estado limpio. Cero artefactos en este PR.

### T4.2 — Pint + scripts

**Finding**: R1-004
**Files**: `pint.json`, `composer.json`
**Depends on**: —
**Commit prefix**: `chore(laravel):`

**Steps**:
1. `composer require --dev laravel/pint:^1.17`.
2. Crear `pint.json` con preset `laravel` + rules `declare_strict_types` + `ordered_imports alpha`.
3. Agregar scripts `composer.json`: `"lint": "pint --test"`, `"lint:fix": "pint"`.
4. Correr `composer lint:fix` → produce N diffs.
5. **Commit separado**: `chore(laravel): apply pint formatting (mechanical drift)`. Documentar en el body que es drift mecánico y que cualquier fix de seguridad posterior debe ser un commit aparte.
6. Verificar `composer lint` → exit 0.

**Done when**: `composer lint` exit 0; ambos commits (pint config + apply) en la historia separados.

---

## Track 5 — Security tooling

### T5.1 — mk:security-lint command

**Finding**: R2-008, R2-009, R1-005 (indirecto)
**Files**: `src/Console/Commands/MkSecurityLintCommand.php` (NUEVO), `tests/Unit/SecurityLintCommandTest.php` (NUEVO)
**Depends on**: —
**Commit prefix**: `feat(laravel):`

**Steps**:
1. Crear `MkSecurityLintCommand` con signature `mk:security-lint`.
2. Implementar 3 checks (ver design.md):
   - `$guarded = []` detection
   - FK declaration check (heurística)
   - `$tenantColumn` whitelist
3. Tests Pest: source-parsing con fixtures (3 modelos: clean, con `$guarded = []`, con FK faltante).
4. Commit (mensaje `feat(laravel):`).

**Done when**: comando corre contra `src/Models/`, exit 0 si clean, exit 1 si hay errors (WARN no falla); test verde.

---

## Track 6 — Documentation

### T6.1 — Docs: HARDENING_1.2.2.md + CHANGELOG (R-NEW-001)

**Finding**: ninguno (R-NEW-001 process)
**Files**: `docs/HARDENING_1.2.2.md` (NUEVO), `CHANGELOG.md` (modifica)
**Depends on**: T1.1, T1.2, T1.3, T2.1, T4.1, T4.2, T5.1 (todos los commits previos)
**Commit prefix**: `docs(laravel):`

**Steps**:
1. Crear `docs/HARDENING_1.2.2.md` con secciones:
   - "Por qué este sprint" (link a auditoría 4R)
   - "Cambios incluidos" (T1.1, T1.3, T2.1, T4.1, T4.2, T5.1)
   - "Cambios NO incluidos" (1.3.0 backlog)
   - "Cómo revertir" (cada cambio con `git revert <sha>`)
   - "Limitaciones conocidas" (T1.1 regex no captura REPLACE/TRUNCATE; T1.3 requiere `hasRole` en User model)
2. Editar `CHANGELOG.md` con section `## [1.2.2-rc1] - 2026-06-18`:
   - Cada cambio con link al finding ID
   - Los `sec:`/`fix(security):` entries DEBEN mencionar el test que cubre el fix (R-NEW-001)
3. Commit.

**Done when**: docs commiteados; CHANGELOG entries revisados manualmente para R-NEW-001 compliance (cada claim `sec:` tiene su test ID).

---

## Track 8 — Verification & Release

### T8.1 — Verify + tag v1.2.2-rc1 + PR

**Finding**: cierre del sprint
**Files**: `.github/workflows/ci.yml` del monorepo (NUEVO job `pest-laravel`)
**Depends on**: T6.1
**Commit prefix**: `chore(monorepo):` (para el CI job) + tag annotation

**Steps**:
1. Correr `vendor/bin/pest` en el sub-repo → verde.
2. Correr `composer lint` → exit 0.
3. Correr `composer analyse` → verde (con baseline).
4. En el monorepo, agregar job `pest-laravel` en `.github/workflows/ci.yml` que ejecute `cd packagist/mk-director-laravel && ./vendor/bin/pest`. Este job cierra el CI gap que detectó el sprint `4r-fixes` (el monorepo no corría `pest` del sub-repo).
5. Commit del CI job en el monorepo.
6. Tag local: `git tag -a v1.2.2-rc1 -m "Release candidate for hardening sprint"`.
7. Push tag: `git push origin v1.2.2-rc1` (en el sub-repo).
8. **NO** `composer publish` — Mario corre ese comando después de validar en `apps/sandbox-laravel`.
9. Crear PR contra `dev` con:
   - Title: `chore(laravel): v1.2.2-rc1 hardening sprint`
   - Body: link a este SDD change, lista de findings cerrados, R-NEW-001 compliance matrix, test coverage delta.

**Done when**:
- `pest` verde (mínimo 2 tests nuevos).
- `composer lint` exit 0.
- `composer analyse` exit 0 (con baseline).
- Tag `v1.2.2-rc1` pusheado a `origin/dev` (NO a Packagist).
- PR abierto contra `dev`.
- CI del monorepo corre `pest-laravel` job y pasa.

---

## T9.* Discovery (max 3 hallazgos adicionales)

**Trigger**: durante la implementación de cualquier task T1.*-T6.*, si el código revela un Medium/Low adicional del audit 4R que:
- Es un fix de < 30 líneas
- Tiene un test obvio
- No introduce BC break
- Cabría en el mismo PR

**Proceso**:
1. Crear T9.N con descripción + finding ID.
2. Implementar + test + commit.
3. Máximo 3 tareas T9.* — si emergen más, se difieren a 1.3.0.

**Anti-abuso**: si T9.* se vuelve scope creep, abortar y mover a `openspec/changes/2026-07-XX-1.3.0/` backlog.
