# 1.2.2-hardening — Technical Design

## Architecture overview

El sprint no cambia la arquitectura general de mk-director. Es **endurecimiento de superficie**: agrega tooling, ajusta gates existentes, y agrega un nuevo spec. No se introducen nuevas abstracciones, no se mueven carpetas, no se renombran APIs.

```
mk-director-laravel/
├── src/
│   ├── Console/Commands/
│   │   ├── MkCheckCommand.php        (existente — sin cambios)
│   │   └── MkSecurityLintCommand.php (NUEVO — T5.1)
│   ├── Controllers/
│   │   └── BaseController.php        (MODIFICA — T1.3)
│   └── MkServiceProvider.php         (MODIFICA — T1.1)
├── database/migrations/
│   └── *_create_role_user_table.php  (MODIFICA — T2.1)
├── docs/
│   └── HARDENING_1.2.2.md            (NUEVO — T6.1)
├── pint.json                         (NUEVO — T4.2)
├── phpstan.neon.dist                 (NUEVO — T4.1)
└── composer.json                     (MODIFICA — T4.1, T4.2 scripts)
```

## T1.1 — DB::listen solo en writes + filter system tables

**Estado real** (validado leyendo `src/MkServiceProvider.php:107-129`):

```php
protected function registerGlobalCacheListener()
{
    if (!config('mk_director.features.auto_cache', false)) {
        return;  // ⭐ gate: solo corre si auto_cache está habilitado
    }

    DB::listen(function ($query) {
        // BUG 1: str_contains($query->sql, 'cache') matchea queries legítimos
        //        de la tabla cache (cache_locks, etc.) — no solo queries que leen cache
        if (str_contains($query->sql, 'cache')) {
            return;
        }

        // Detecta writes pero NO filtra system tables
        if (preg_match('/(update|delete|insert into)\s+`?(\w+)`?/i', $query->sql, $matches)) {
            $table = $matches[2];
            Cache::tags([$table . '_all'])->flush();  // ⚠ tag por tabla — wildcard
            // ⚠ no diferencia writes reales de los del sistema (migrations, jobs, sessions)
        }
    });
}
```

**Problemas reales a corregir** (vs. lo que la auditoría 4R sugería):

1. **Falta filtro de system tables** (R2-007): el listener invalida cache cuando un cron update la tabla `jobs` o cuando una migration toca `auth_users`. Eso borra cache que no tenía nada que ver.
2. **El regex matchea `INSERT INTO cache ...` pero ya retorna antes** por la línea 115. O sea: el filtro `str_contains('cache')` se ejecuta ANTES del write detection → efectivamente un write a `cache` no se procesa (bien), pero un SELECT a `cache` (línea de query log) tampoco (también bien, pero por accidente).
3. **El tag `{$table}_all` no es canon**: no se usa en ninguna otra parte del código. La cache real probablemente se taggea con algo distinto. Mitigación: mantener el patrón actual (no cambiar naming de tags) y solo agregar el system-tables filter.

**Fix**:

```php
protected function registerGlobalCacheListener()
{
    if (!config('mk_director.features.auto_cache', false)) {
        return;
    }

    $systemTables = [
        'migrations', 'cache', 'cache_locks', 'sessions',
        'password_resets', 'password_reset_tokens',
        'jobs', 'job_batches', 'failed_jobs',
        'telescope_entries', 'telescope_monitoring',
    ];

    DB::listen(function ($query) use ($systemTables) {
        // 1. Filtrar system tables PRIMERO (incluye cache)
        foreach ($systemTables as $table) {
            if (str_contains($query->sql, $table)) {
                return;
            }
        }

        // 2. Solo actuar en writes
        if (preg_match('/(update|delete|insert into)\s+`?(\w+)`?/i', $query->sql, $matches)) {
            $table = $matches[2];
            Cache::tags([$table . '_all'])->flush();

            if (config('mk_director.debug', false)) {
                Log::info("MK-Director: Cache flushed for table [{$table}] due to write operation.");
            }
        }
    });
}
```

**Riesgo**: el regex actual no captura `REPLACE`, `TRUNCATE`, stored procedures. Misma limitación que el código existente — no es regresión. Documentar en docblock.

## T1.2 — SmartController guarded: cerrar admin endpoints (WARN)

**Status**: NO se modifica código en este sprint. Se agrega un **WARN** en el output de `mk:check` para que los consumers sepan que `SmartController` no enforce auth por sí mismo y deben agregar middleware (`auth`, `MkAuthenticate`, `MkAbility`) en sus rutas o en el constructor del Controller hijo.

**Razón**: cambiar la firma de `SmartController` para enforce auth es BC break. El fix correcto es documentación + lint advisory, no cambio silencioso.

## T1.3 — BaseController debug gate por role/scope

**Estado real** (validado leyendo `src/Controllers/BaseController.php:77-108`):

```php
protected function getDebugData()
{
    if (request()->input('_debug') != 1) return [];  // ⭐ gate actual: SOLO si _debug=1

    $queries = DB::getQueryLog();
    // ... itera y agrega EXPLAIN para SELECTs ...
    return [
        'debug_queries' => $queries,  // ⚠ incluye bindings crudos
        'debug_summary' => [...]
    ];
}
```

**Lo que el código actual SÍ tiene**:
- Gate 1: `getDebugData` solo se llama desde `sendResponse` cuando `config('mk_director.debug') === true` (línea 33).
- Gate 2: solo retorna data si query string tiene `_debug=1`.
- **Lo que NO tiene**: role check. Cualquier request autenticado con `?debug=true&_debug=1` ve EXPLAIN+bindings (líneas 91-98).

**Fix**:

```php
protected function getDebugData()
{
    if (request()->input('_debug') != 1) return [];

    // ⭐ R2-010: role gate antes de exponer EXPLAIN + bindings
    $user = request()->user();
    if (!$user || !method_exists($user, 'hasRole')) {
        return [];
    }
    if (!$user->hasRole('super-admin') && !$user->hasRole('dev')) {
        return [];
    }

    // ... resto del método sin cambios ...
}
```

**Backward compat**:
- Si app consumer no implementa `hasRole` en su User model → retorna `[]` (no leak, no crash). Esto es fail-safe: si no hay role, NO hay debug data.
- Si `hasRole` está y user es `super-admin` o `dev` → retorna data igual que antes.
- Si user tiene cualquier otro role → retorna `[]` (no leak).
- Si `mk_director.debug=false` en config → nunca se llama a `getDebugData` (gate existente en `sendResponse`).

**Test source-parsing** (3 escenarios) — patrón del sprint anterior:
- (a) User sin `hasRole()` method → retorno `[]`.
- (b) User con `hasRole()` que retorna `false` para `super-admin` → retorno `[]`.
- (c) User con `hasRole()` que retorna `true` para `super-admin` → invoca lógica de debug completa.

## T2.1 — role_user FK a auth_users.id

**Problema** (`database/migrations/*_create_role_user_table.php`): el `user_id` no tiene FK declarada. Si un `auth_user` se borra, las filas huérfanas en `role_user` quedan (cascada manual rota).

**Fix**:

```php
Schema::create('role_user', function (Blueprint $table) {
    $table->uuid('user_id');
    $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
    $table->timestamps();

    $table->foreign('user_id')
        ->references('id')
        ->on('auth_users')
        ->cascadeOnDelete();

    $table->primary(['user_id', 'role_id']);
});

// down() con try/catch porque la tabla puede existir pre-1.2 sin FK
public function down(): void
{
    try {
        Schema::table('role_user', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });
    } catch (\Throwable $e) {
        // FK no existía (installs pre-1.2), ignorar
    }

    Schema::dropIfExists('role_user');
}
```

**Migración separada**: este cambio NO se mete en la migration original (eso es BC break para installs existentes). Se crea una migration nueva `2026_06_18_000001_add_fk_role_user_to_auth_users.php` que solo agrega la FK en installs donde la tabla ya existe.

## T4.1 — ~~phpstan + baseline~~ (CANCELADO 2026-06-18)

**Status**: cancelado. Ver tasks.md §"Track 4" para detalle del bug Larastan 3.10.0.

R1-003 queda diferido a 1.3.0.

## T4.2 — Pint + scripts

**Setup**:

```bash
composer require --dev laravel/pint:^1.17
```

**`pint.json`** (preset `laravel` con override mínimo):

```json
{
    "preset": "laravel",
    "rules": {
        "declare_strict_types": true,
        "ordered_imports": {
            "sort_algorithm": "alpha"
        }
    }
}
```

**Script composer.json**:

```json
"scripts": {
    "lint": "pint --test",
    "lint:fix": "pint"
}
```

**Disciplina**: el primer `lint:fix` produce N diffs mecánicos. Esos van en **un commit aparte** (`chore(laravel): apply pint formatting (mechanical drift)`), NO mezclados con `sec:` / `fix(security):` commits. Esto preserva R-NEW-001 traceability (un `git blame` de un fix de seguridad no debe mostrar Pint como author).

## T5.1 — mk:security-lint command

**Signature**: `php artisan mk:security-lint` (sin options; exit code 0/1).

**Comportamiento** (lee `src/Models/**/*.php`):

1. **`$guarded = []` detection**: si un modelo tiene `protected $guarded = []` o `protected $guarded = ['*'];` con un `*` que matchea todo, WARN con path:line.
2. **FK declaration check**: para cada modelo, leer sus relaciones `belongsTo` y verificar que las columnas FK tengan FK declarada en la migration correspondiente (heurística: nombre de columna = `<table>_id`, tabla referenciada = `<table>`). Si no, WARN.
3. **`$tenantColumn` whitelist**: leer `MkMultiTenantPlugin::$tenantColumn` y comparar contra whitelist hardcoded `['tenant_id', 'org_id', 'company_id']`. Si está fuera, ERROR (no WARN).

**Output format**:

```
Security Lint Report
═══════════════════
✓ 12 models checked
⚠ 2 warnings
✗ 0 errors

WARN  src/Models/Property.php:8
       $guarded = [] enables mass-assignment — set $fillable explicitly
WARN  src/Models/Address.php:23
       belongsTo(User::class) on user_id without FK in migration
```

**Tests**:

- `tests/Unit/SecurityLintCommandTest.php` con 3 escenarios: clean model, $guarded = [], $tenantColumn inválido.
- Source-parsing tests (no Mockery chainable, patrón del sprint anterior).

## T6.1 — Docs

- `docs/HARDENING_1.2.2.md` — rationale de cada cambio, qué cubre, qué no, cómo revertir.
- `CHANGELOG.md` — entry bajo `## [1.2.2-rc1]` con cada cambio linkeando al finding ID.
- `openspec/specs/security-lint.md` (NUEVO spec) — requisitos + scenarios del `mk:security-lint`.

## Risk register

| Riesgo | Probabilidad | Impacto | Mitigación |
|--------|--------------|---------|------------|
| T1.1 query filter omite mutación no-obvia | Media | Cache stale | Tests Pest con `REPLACE` + `TRUNCATE` |
| T1.3 rompe apps sin `hasRole` | Baja | Sin debug data | Gate retorna `[]` (fail-safe) |
| T2.1 migration falla en installs pre-1.2 | Baja | Rollback manual | Try/catch en down() + smoke test en sandbox |
| T4.2 Pint diffs enmascaran fixes reales | Media | Confusión en review | Commit `chore:` separado, README en PR explica |
| T5.1 false positives en FK detection | Media | Trust erosion | Config `mk_director.security_lint.strict = true` para opt-in strict mode |
