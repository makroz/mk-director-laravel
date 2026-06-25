# Dogfooding-Model — Proposal (mk-director + RETO)

**Sprint ID**: `2026-06-24-dogfooding-model`
**Branch**: `makromania/260624-2130--dogfooding-model` (monorepo + sub-repo `makroz/mk-director-laravel`)
**Base**: `origin/dev` (post v1.4.0)
**Mode**: meta-sprint — no tag de paquete. Cierre se mide por cierre de los 5 sub-changes.
**Owner**: Mario (CTO Makromania) — único humano en el loop
**Agents**: Mavis (sesión root `mvs_d9934675f0d74b46acb1817c387173e5`) + futuros agentes RETO

---

## Why

### El problema detectado

El sprint de auth flow v1.4.0 (cerrado 2026-06-24, PR #13 mergeado, tag `f647339b1c90139d32e2a6150c76c05afc835c8f`) consolidó el flujo de auth en `makroz/director-laravel`. Sin embargo, al revisar el branch `makromania/260624-0511--admin-module` de RETO (no mergeado a dev, +5019 LOC), quedó claro que **RETO implementó manualmente features que el paquete debería proveer**:

| Feature escrita manualmente por RETO | LOC | Estado en el paquete |
|---|---|---|
| `DiscoverAbilitiesCommand` (auto-descubrir abilities desde controllers) | 268 | NO existe — RETO reinventó |
| AuthController con RBAC integration | 322 | Existe stub (187 LOC, v1.4.0) pero sin RBAC ni RBAC-aware methods |
| Profile fields custom en Model + Migration | 62 + 200 | NO hay flag `--profile-fields` en `mk:make:auth-user` |
| 3 CRUDs interrelacionados (Admin + Role + Ability) | 360 | NO hay flag `--with-rbac` en `mk:module` |
| Login con campo `ci` (cédula de identidad, Bolivia) | custom | Stub asume `email` hardcoded |

**Resultado**: el paquete está 5 sprints detrás de su único consumer real. Si esto sigue, RETO se convierte en "otro fork" y el modelo "framework con consumers" colapsa.

### Por qué dogfooding-first

Tres señales convergen:

1. **RETO es el único consumer real** (`mariogfos/reto`). No hay otros proyectos activos. Cualquier esfuerzo de mantener BC "hacia consumidores públicos hipotéticos" es especulación sin feedback loop.
2. **Las features que RETO necesita son genéricas** (RBAC, ability discovery, login configurable). No son específicas de RETO — son patrones que CUALQUIER app multi-tenant con RBAC necesita.
3. **El modelo actual diverge**: el paquete evoluciona en aislamiento, RETO diverge con su propia implementación. El framework deja de ser útil exactamente cuando se prueba contra una app real.

El modelo dogfooding-first corrige esto: **RETO es el driver del roadmap del paquete**. Cada feature del paquete nace de una necesidad concreta de RETO, se valida contra RETO antes de taggearse, y BC se maneja caso por caso (no como restricción universal).

### Lo que NO es dogfooding-first

Para que quede claro:

- **NO** es "RETO es el único usuario para siempre". Es el único consumer HOY. Cuando haya un segundo proyecto real, el criterio de "genérico" (R-G-033-A) se mantiene — solo cambia que "≥ 2 dominios bounded" se vuelve trivialmente cierto.
- **NO** es "todo lo de RETO va al paquete". Una feature RETO-only se queda en RETO (R-G-033-B). El paquete no se convierte en un clon de RETO.
- **NO** es "BC no importa". BC importa cuando hay consumers activos. Hoy: solo RETO → BC es opt-in, manejable sprint a sprint. Mañana: cuando haya 2+ consumers → BC vuelve a importar y se reintroduce el versionado estricto.

---

## What changes

### 1. Nueva regla R-G-033 (Dogfooding Model ★)

Agregada a `~/.makromania/agency/global/rules_orchestration.md`. Es regla de oro, no-negociable mientras solo RETO consuma.

**Componentes de R-G-033** (resumen, ver rules_orchestration.md para detalle):

- **R-G-033-A**: Criterio de "genérico" para entrar al paquete (≥ 2 dominios bounded, testeable sin RETO).
- **R-G-033-B**: Criterio de "RETO-only" — si solo RETO lo usa, se queda en RETO.
- **R-G-033-C**: BC no es sagrado — romper es válido mientras RETO migre en el mismo sprint.
- **R-G-033-D**: Workflow iterativo (RETO descubre → SDD → triage → impl → rc1 → RETO valida → tag → publish).
- **R-G-033-E**: Continuation prompt obligatorio al inicio de cada sesión.

### 2. Cinco sub-changes independientes

Cada uno tiene su propio `state.yaml` + `proposal.md` + `tasks.md` + `specs/`:

| Sub-change | R-ID | Tipo | Status inicial |
|---|---|---|---|
| `2026-06-24-discover-abilities-to-core` | R-PKG-007 | paquete | triage (¿genérico?) |
| `2026-06-24-admin-with-rbac` | R-PKG-008 | paquete | diseño de flag `--with-rbac` |
| `2026-06-24-auth-user-login-field` | R-PKG-009 | paquete | diseño de campo configurable |
| `2026-06-24-auth-controller-rbac-stub` | R-PKG-010 | paquete | mejora del stub actual |
| `2026-06-24-retrofit-reto-admin-module` | R-RET-001 | RETO | releer + rehacer con skills nuevas |

### 3. Continuation prompt

`~/.makromania/agency/plantillas_prompt/mk-director-continuation.md`. Prompt corto (~30 líneas, copy-paste friendly) que se pasa al inicio de cualquier sesión que trabaje en el modelo dogfooding. Incluye: contexto del modelo, issues activos, comandos obligatorios al inicio, reglas R-G-033-A..E.

### 4. R-G-032 sync (en este sprint)

- Skill `.makromania/agency/skills/mk-director-laravel/SKILL.md` — agregar sección "Modelo de release" referenciando R-G-033.
- `projects/mk-director/AGENTS.md` (raíz del monorepo) — agregar referencia al modelo dogfooding y a la nueva regla.
- Memoria del agente `~/.mavis/agents/main/memory/MEMORY.md` — entry nueva sobre R-G-033.

---

## Scope

### In-scope (este sprint)

- R-G-033 definida y agregada a rules_orchestration.md.
- 5 sub-changes abiertos con state.yaml + proposal.md + tasks.md + specs/ (mínimo viable).
- Continuation prompt en `plantillas_prompt/mk-director-continuation.md`.
- R-G-032 sync: skill + AGENTS.md + MEMORY (los 3 críticos).
- Catálogo de issues activos actualizado.

### Out-of-scope (diferido a sprints posteriores)

- Implementación de los 5 sub-changes (cada uno tiene su propio sprint cuando se priorice).
- Reescribir el módulo Admin de RETO (R-RET-001) — depende de R-PKG-007/008/009/010 ya mergeados.
- Migrar RETO de 1.3.1 a 1.4.0 o 1.5.0 — depende del cierre de R-PKG-007/008/009/010.
- Versión v1.5.0 del paquete — se taggea cuando R-PKG-007/008/009/010 cierren, no antes.
- Cualquier trabajo en `@makroz/web` o `@makroz/mobile` — el sprint es solo del paquete Laravel.
- Cambio de naming de branches o convención de commits — sigue R-G-006.

---

## Success criteria

1. **R-G-033 firmada** y visible en `rules_orchestration.md` con todos sus componentes (A, B, C, D, E).
2. **5 sub-changes abiertos** con archivos completos (state + proposal + tasks + specs mínimo).
3. **Continuation prompt** listo para copy-paste, con catálogo inicial de issues activos.
4. **R-G-032 sync**: 3 ubicaciones críticas actualizadas (skill, AGENTS.md, MEMORY).
5. **Próxima sesión puede retomar sin pedir contexto**: cualquier agente que cargue el continuation prompt sabe en qué sprint está, qué issues hay, y qué reglas aplicar.

---

## Cómo retomar este sprint

Si llegás a este sprint en una sesión nueva:

1. Cargar `.makromania/agency/plantillas_prompt/mk-director-continuation.md` — contiene catálogo actualizado.
2. Verificar ramas activas:
   ```bash
   git -C projects/mk-director branch -a | grep makromania/260624
   git -C projects/mk-director/packagist/mk-director-laravel branch -a | grep makromania/260624
   ```
3. Si no hay rama para el sub-change que vas a tocar, crear:
   ```bash
   git checkout -b makromania/$(date +%y%m%d-%H%M)--<sub-change-slug> origin/dev
   ```
4. Actualizar `state.yaml` del sub-change correspondiente (status, owner, findings cerrados).
5. Implementar siguiendo R-G-032 (sync 16 locations) + R-G-033 (criterio genérico) + R-G-001..032.

---

## Anti-patterns (rechazados en PR review)

- ❌ "Absorbemos el módulo Admin de RETO tal cual al paquete" — el código de RETO es RETO-only (R-G-033-B). Solo las features genéricas van al paquete.
- ❌ "RETO bumpea cuando pueda, sin avisar" — RETO siempre sabe antes que va a haber BC (R-G-033-C).
- ❌ "Saltamos el continuation prompt, ya sabemos qué hay" — el prompt existe para que CUALQUIER sesión/agente retome (R-G-033-E).
- ❌ "Esperamos a tener 3 consumers para validar la feature" — RETO es el primer consumer (R-G-033-D).
- ❌ "Releases cada 2 semanas" — releases por demanda, no por calendario.

---

## Cross-references

- Regla R-G-033: `~/.makromania/agency/global/rules_orchestration.md` § R-G-033
- Sub-change 1 (DiscoverAbilities): `openspec/changes/2026-06-24-discover-abilities-to-core/`
- Sub-change 2 (Admin --with-rbac): `openspec/changes/2026-06-24-admin-with-rbac/`
- Sub-change 3 (login field): `openspec/changes/2026-06-24-auth-user-login-field/`
- Sub-change 4 (AuthController RBAC): `openspec/changes/2026-06-24-auth-controller-rbac-stub/`
- Sub-change 5 (RETO retrofit): `openspec/changes/2026-06-24-retrofit-reto-admin-module/`
- Continuation prompt: `~/.makromania/agency/plantillas_prompt/mk-director-continuation.md`
- RETO branch huérfano (a deprecar): `makromania/260624-0511--admin-module` (+5019 LOC, pre-1.4.0, sin merge)
