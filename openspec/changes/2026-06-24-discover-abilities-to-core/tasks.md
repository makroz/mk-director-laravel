# R-PKG-007 — Tasks (CIERRE 2026-06-25, v1.5.0-rc2)

**Status**: ✅ CERRADO — listo para tag + Packagist publish + PR coordination.
**Branch**: `makromania/260624-2140--discover-abilities-to-core` (packagist, FROZEN como historical ref per R-G-006 post-tag).
**Tag**: v1.5.0-rc2 (pending — pendiente sign-off de Mario).
**Packagist publish**: pendiente post-merge de PRs (3 coordinados).

---

## Track 0 — Triage ✅

### T0.1 — Cerrar triage R-G-033-A ✅
- ✅ R-G-033-A-1 ≥ 2 dominios: SÍ (mk-director nativo + RETO admin scope + futuros `--with-rbac` consumers)
- ✅ R-G-033-A-2 testeable sin RETO: SÍ (fixtures locales + SQLite in-memory + MkLaravelTestCase)
- ✅ R-G-033-A-3 doc sin RETO: SÍ (skill "auto-descubrir abilities" sin mencionar RETO)
- **Decision**: GO (cumple R-G-033-A, va al paquete)

### T0.2 — Design (419 líneas, ADRs D1-D7 + Q1-Q3 closed) ✅
- ✅ API del comando: 4 flags (--module, --dry-run, --force, --json)
- ✅ Source-of-truth: HYBRID (Q1 sign-off) — provider primario, attribute+docblock fallback único
- ✅ Atributo PHP 8.4 primary (Q2 sign-off), docblock fallback
- ✅ Interactive prompt con --force/--dry-run/--no-interaction (Q3 sign-off)
- ✅ ADRs D1-D7 documentados en design.md

---

## Track 1 — Implementación ✅

### T1.1 — `src/Console/Commands/DiscoverAbilitiesCommand.php` (470 líneas) ✅
Commit `053b412`. Signature con 4 flags. Hybrid D1 source logic. UPSERT idempotente.
Reflection-based controller scanning (attribute + docblock fallback).

### T1.2 — `src/Auth/Attributes/Ability.php` (73 líneas) ✅
Commit `2cd6452`. PHP 8.4 attribute `#[Attribute(TARGET_METHOD | IS_REPEATABLE)]`,
constructor property promotion `__construct(public string $name, public ?string $description = null)`.

### T1.3 — Auto-register on boot + config updates ✅
Commit `0d56ead`. `MkServiceProvider::registerAutoDiscoverAbilities()` gated by
`config('mk_director.features.auto_discover_abilities', false)`. Agrega `paths.modules`
y `features.auto_discover_abilities` a `config/mk_director.php`.

### T1.4 — Tests Pest (17 tests, 75 assertions, +17 deltas) ✅
Commit `a31c8e6`. `tests/Feature/DiscoverAbilitiesCommandTest.php` cubre:
- 7 source-parsing tests (signature, D1 hybrid, D3 prompt, UPSERT, modulesPath, Str::plural, PCRE2 regex)
- 2 atributo PHP tests (TARGET_METHOD + IS_REPEATABLE, name + description properties)
- 5 end-to-end (15 abilities via provider, fallback path, provider overrides, idempotencia UPSERT, scope detection)
- 3 MkServiceProvider + config tests

### T1.5 — R-G-032 sync (8 synced + 5 verified N/A + 4 deferred) ✅
- ✅ packagist: CHANGELOG + DEVELOPER_GUIDE + README (commit `be48038`)
- ✅ monorepo docs: GETTING_STARTED + AUTH + API_REFERENCE_LARAVEL (commit `408e492`)
- ✅ humandirector skills: SKILL.md index + reference 10-discover-abilities (commit `a008890`)
- ✅ verified: core/web/mobile skills NO requieren cambios (Laravel-only)
- ⏸️ deferred: 4 locations (5=monorepo CHANGELOG, 9-10=API_REFERENCE_{MOBILE,WEB}, 16=RETO BC) — sin cambio de contrato cross-stack o agrupados en releases futuros

---

## Track 2 — Validación RETO ⏸️

### T2.1 — RETO adopta ⏸️ BLOCKED
R-RET-001 sprint separado. RETO bumpea composer a `makroz/director-laravel:^1.5.0`
cuando v1.5.0 GA esté publicado. Borra rama huérfana `makromania/260624-0511--admin-module`,
re-scaffoldea módulo Admin con `mk:module Admin --with-rbac`, corre
`mk:discover-abilities --module=admin --force`.

---

## Definition of Done

- [x] Triage cerrado con decisión documentada (state.yaml v2 — commit `93ed0ea`).
- [x] Design con ADRs + Q1-Q3 sign-off (design.md 419 líneas — commit `635e05a`, state.yaml v3 — commit `38af54c`).
- [x] Comando + atributo + auto-register + config (`053b412`, `2cd6452`, `0d56ead`).
- [x] Tests Pest verdes (17 tests, 75 assertions, 302/302 suite) — commit `a31c8e6`.
- [x] R-G-032 sync (8 synced + 5 verified + 4 deferred) — commits `be48038`, `408e492`, `a008890`.
- [x] state.yaml v4 closed (este commit).
- [ ] **Pendiente**: tag v1.5.0-rc2 + 3 PRs coordinados (packagist + docs-sync + skills-sync) + Packagist publish.
- [ ] **Pendiente**: RETO cierra rama huérfana + adopta el paquete (R-RET-001).
