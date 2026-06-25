# R-PKG-007 — DiscoverAbilitiesCommand → mk-director core

**Sprint ID**: `2026-06-24-discover-abilities-to-core`
**Parent**: `2026-06-24-dogfooding-model` (R-G-033)
**Branch**: `makromania/260624-2140--discover-abilities-to-core` (a crear)
**Base**: `origin/dev` @ v1.4.0
**Status**: triage (abierta, NO en implementación)

---

## Why

RETO implementó manualmente `DiscoverAbilitiesCommand` (268 LOC, `projects/reto/reto-api/app/Modules/Admin/Console/Commands/DiscoverAbilitiesCommand.php`) que escanea controllers y auto-registra abilities en la base de datos. Esta feature resuelve un problema genérico: cualquier app con RBAC necesita descubrir abilities declaradas en controllers para no hardcodearlas en seeders o configuración.

**Hipótesis**: la feature cumple R-G-033-A y debe vivir en el paquete.

**Validar antes de implementar**:
- [ ] ¿≥ 2 dominios bounded la usarían? (SÍ: cualquier app con RBAC)
- [ ] ¿Testeable sin RETO? (SÍ: escanea archivos PHP, fixtures locales)
- [ ] ¿Documentable sin mencionar RETO? (SÍ: "auto-descubrir abilities desde controllers")

---

## What changes

### Si pasa el triage

1. **Nuevo comando en el paquete**: `Mk\Director\Console\Commands\DiscoverAbilitiesCommand`
   - Namespace: `Mk\Director\Console\Commands\DiscoverAbilitiesCommand`
   - Firma: `php artisan mk:discover-abilities {--module=*} {--dry-run} {--json}`
   - Escanea `app/Modules/*/Http/Controllers/*.php` (path configurable via `mk_director.paths.modules`).
   - Lee atributos PHP (`#[\Mk\Director\Auth\Attributes\Ability(...)]`) o comentarios docblock (`@mk-ability name|description`).
   - Output: `INSERT INTO abilities (name, description, scope) VALUES (...)` o `--dry-run` muestra la lista.
   - Sin dependencies de RETO.

2. **Atributo PHP opcional** (PHP 8.4+): `Mk\Director\Auth\Attributes\Ability`
   ```php
   #[\Mk\Director\Auth\Attributes\Ability('admin.users.list', 'Listar usuarios admin')]
   public function index(Request $request) { ... }
   ```

3. **Auto-registro en ServiceProvider**: opcional via `mk_director.features.auto_discover_abilities = true` en `config/mk_director.php`.

4. **Tests Pest** (paquete):
   - `tests/Feature/DiscoverAbilitiesCommandTest.php` — escanea fixture con 3 controllers (uno con atributo, uno con docblock, uno sin nada) → verifica output.
   - `tests/Feature/AbilityAttributeTest.php` — verifica parsing del atributo PHP.

5. **R-G-032 sync (16 locations)**:
   - `CHANGELOG.md` del paquete.
   - `DEVELOPER_GUIDE.md` — sección "RBAC: ability discovery".
   - `README.md` — update feature list.
   - Skill `.makromania/agency/skills/mk-director-laravel/SKILL.md` — agregar comando a la tabla de capacidades + reference `08-discover-abilities.md`.
   - `references/08-discover-abilities.md` — guía detallada.
   - `mk-director/docs/guides/AUTH.md` — sección sobre RBAC.
   - Otros 10 locations según checklist R-G-032.

### Si NO pasa el triage

La feature se queda en RETO. No es parte del paquete. Documentar la decisión en `state.yaml` y cerrar.

---

## Scope

### In-scope
- Triage de la feature (R-G-033-A).
- Si pasa: implementación + tests + R-G-032 sync.

### Out-of-scope
- Reescribir el comando de RETO para que use el del paquete — eso es R-RET-001.
- Cambiar el sistema de abilities base (ya funciona, solo se agrega discovery).
- Cualquier integración con UI (el comando es CLI-only).

---

## Success criteria

1. Triage cerrado con decisión documentada.
2. Si pasa: `php artisan mk:discover-abilities --dry-run` corre contra sandbox sin errores.
3. Si pasa: tests Pest verdes, R-G-032 sync completo.
4. Tag rc1 + validación RETO (RETO reemplaza su `DiscoverAbilitiesCommand` por el del paquete).

---

## Anti-patterns

- ❌ Copiar 268 LOC de RETO tal cual → filtrar assumptions primero.
- ❌ Asumir path `app/Modules/{Name}/Http/Controllers/` hardcoded → configurable.
- ❌ Asumir namespace `App\Modules\Admin\Models\Admin` → agnóstico.

---

## Cross-references

- Parent: `openspec/changes/2026-06-24-dogfooding-model/`
- Source feature: `projects/reto/reto-api/app/Modules/Admin/Console/Commands/DiscoverAbilitiesCommand.php`
- RETO branch huérfano: `makromania/260624-0511--admin-module`
- Regla: `~/.makromania/agency/global/rules_orchestration.md` § R-G-033
