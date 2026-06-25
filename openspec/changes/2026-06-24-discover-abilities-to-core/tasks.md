# R-PKG-007 — Tasks (TBD cuando se implemente)

**Status**: ABIERTO — pendiente triage (R-G-033-A). Las tasks se llenan cuando se apruebe la implementación.

---

## Track 0 — Triage (primer sprint)

### T0.1 — Leer código fuente de RETO
**File**: `projects/reto/reto-api/app/Modules/Admin/Console/Commands/DiscoverAbilitiesCommand.php`
**Output**: lista de assumptions RETO-específicas (paths, namespaces, nombres de tabla).

### T0.2 — Evaluar R-G-033-A
- ¿≥ 2 dominios bounded? → escribir respuesta documentada.
- ¿Testeable sin RETO? → diseñar test fixture.
- ¿Doc sin RETO? → borrador del doc que iría en la skill.
**Output**: decisión (va al paquete / se queda en RETO).

### T0.3 — Si pasa, diseñar API genérica
- Firma del comando.
- Path configurable.
- Atributo PHP o docblock parsing.
- Output format (table/json/dry-run).
**Output**: ADRs (Architecture Decision Records) en `design.md`.

---

## Track 1 — Implementación (post-triage, sprint futuro)

### T1.1 — Comando base
- Crear `src/Console/Commands/DiscoverAbilitiesCommand.php`.
- Implementar escaneo de controllers.
- Implementar output (table + --dry-run + --json).
**Commit**: `feat(laravel): add mk:discover-abilities command`

### T1.2 — Atributo PHP
- Crear `src/Auth/Attributes/Ability.php`.
- Implementar parser de atributos.
**Commit**: `feat(laravel): add #[Ability] attribute for auto-discovery`

### T1.3 — Auto-registro
- Hook en `MkServiceProvider::boot()` si `auto_discover_abilities=true`.
**Commit**: `feat(laravel): auto-discover abilities on boot`

### T1.4 — Tests Pest
- `tests/Feature/DiscoverAbilitiesCommandTest.php`
- `tests/Feature/AbilityAttributeTest.php`
- Cobertura: 90%+.
**Commit**: `test(laravel): cover discover-abilities command`

### T1.5 — R-G-032 sync
- 16 locations según checklist.
**Commit**: `docs(laravel): sync R-G-032 for discover-abilities`

---

## Track 2 — Validación RETO (sprint posterior)

### T2.1 — RETO adopta
- RETO branch `makromania/260624-0511--admin-module` reemplaza `DiscoverAbilitiesCommand` por `Mk\Director\Console\Commands\DiscoverAbilitiesCommand`.
- Elimina su implementación custom.
- Tag v1.5.0 del paquete + RETO bumpea.
**Commit en RETO**: `refactor(reto): use mk-director discover-abilities instead of custom impl`

---

## Definition of Done

- [ ] Triage cerrado con decisión documentada.
- [ ] Si pasa: comando + atributo + tests + R-G-032 sync completo.
- [ ] Tag v1.5.0-rc1 + validación sandbox + validación RETO.
- [ ] RETO cerró su impl custom y adoptó la del paquete.
