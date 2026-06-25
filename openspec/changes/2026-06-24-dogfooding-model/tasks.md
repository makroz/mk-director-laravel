# Dogfooding-Model — Implementation Tasks

**Total**: 5 meta-tasks + 5 sub-changes (cada uno con sus propias tasks).
**Convención de commits**:
- `docs(laravel):` → cambios a docs/reglas/SDD (este sprint).
- `chore(laravel):` → drift mecánico (Pint, baseline regenerate).
- NO hay `feat:` ni `fix:` en este sprint — es meta.

---

## Track 0 — Meta-sprint setup (este sprint)

### T0.1 — R-G-033 en rules_orchestration.md

**Depends on**: —
**Commit prefix**: `docs(laravel):`
**Files**: `~/.makromania/agency/global/rules_orchestration.md`

**Steps**:
1. Insertar nueva sección `### R-G-033 — Modelo Dogfooding-First (mk-director + RETO) ★` justo después de R-G-032 (línea ~825).
2. Incluir: declaración del modelo + componentes A, B, C, D, E + anti-patterns + checklist + cross-references.
3. Commit en el monorepo (NO en el sub-repo, porque rules_orchestration.md vive en el workspace `.makromania/`).
4. Mensaje de commit: `docs(agency): add R-G-033 dogfooding model ★`.

**Done when**: `grep "R-G-033" ~/.makromania/agency/global/rules_orchestration.md` devuelve la sección. Commit pusheado a monorepo.

### T0.2 — SDD principal del modelo

**Depends on**: T0.1
**Commit prefix**: `docs(laravel):`
**Files**: `projects/mk-director/packagist/mk-director-laravel/openspec/changes/2026-06-24-dogfooding-model/{state.yaml,proposal.md,tasks.md,specs/dogfooding-model.md}`

**Steps**:
1. Crear `state.yaml` con metadata del sprint + catálogo de sub-changes + risk register.
2. Crear `proposal.md` con Why / What changes / Scope / Success criteria / Cómo retomar / Anti-patterns.
3. Crear `tasks.md` (este archivo).
4. Crear `specs/dogfooding-model.md` con la spec formal del modelo.
5. Commit en el sub-repo `makroz/mk-director-laravel`.
6. Mensaje: `docs(laravel): open sprint dogfooding-model + R-G-033 baseline`.

**Done when**: archivos commiteados, sprint visible en `git log` del sub-repo.

### T0.3 — Cinco sub-changes abiertos

**Depends on**: T0.2
**Commit prefix**: `docs(laravel):` (5 commits, uno por sub-change)
**Files**: `openspec/changes/2026-06-24-*/{state.yaml,proposal.md,tasks.md,specs/*.md}` × 5

**Steps** (para cada uno):
1. `2026-06-24-discover-abilities-to-core/` — R-PKG-007 (triage: ¿DiscoverAbilitiesCommand es genérico?)
2. `2026-06-24-admin-with-rbac/` — R-PKG-008 (diseño de flag `--with-rbac`)
3. `2026-06-24-auth-user-login-field/` — R-PKG-009 (diseño de campo configurable email/ci/phone/username)
4. `2026-06-24-auth-controller-rbac-stub/` — R-PKG-010 (mejora del stub con RBAC integration)
5. `2026-06-24-retrofit-reto-admin-module/` — R-RET-001 (rehacer módulo Admin de RETO con skills nuevas, depende de los 4 anteriores)

**Done when**: 5 directorios creados, cada uno con 4 archivos mínimos + commit independiente.

### T0.4 — Continuation prompt

**Depends on**: T0.1, T0.3
**Commit prefix**: `docs(laravel):`
**Files**: `~/.makromania/agency/plantillas_prompt/mk-director-continuation.md`

**Steps**:
1. Crear prompt corto (~30-50 líneas) copy-paste friendly.
2. Incluir: contexto del modelo, catálogo de issues activos (los 5 sub-changes), comandos obligatorios al inicio, reglas R-G-033-A..E, links a SDD + skills + memoria.
3. Mensaje: `docs(agency): add mk-director continuation prompt for dogfooding model`.

**Done when**: archivo existe, prompt probado (cargar en sesión nueva, verificar que el agente sabe en qué está).

### T0.5 — R-G-032 sync (3 ubicaciones críticas)

**Depends on**: T0.1..T0.4
**Commit prefix**: `docs(laravel):` (3 commits)
**Files**:
- `.makromania/agency/skills/mk-director-laravel/SKILL.md` — sección "Modelo de release (R-G-033)"
- `projects/mk-director/AGENTS.md` — sección "Dogfooding model (R-G-033)"
- `~/.mavis/agents/main/memory/MEMORY.md` — entry sobre R-G-033

**Steps**:
1. Actualizar skill: agregar 1 párrafo en la sección "Capacidades" + nota al pie "Modelo: ver R-G-033".
2. Actualizar AGENTS.md raíz: agregar referencia a R-G-033 + al continuation prompt.
3. Actualizar MEMORY del agente: entry con type=rule, scope=project:mk-director.
4. 3 commits independientes.

**Done when**: 3 archivos actualizados + commits pusheados.

---

## Track 1 — Sub-changes del paquete (sprints futuros)

Estos NO se implementan en este sprint. Cada uno tiene su propio SDD proposal + tasks.md. Este sprint solo los ABRE.

### T1.1 — R-PKG-007 DiscoverAbilitiesCommand → mk-director core
**SDD**: `openspec/changes/2026-06-24-discover-abilities-to-core/`
**Owner**: futuro sprint (post cierre de T0.5)

### T1.2 — R-PKG-008 mk:module Admin --with-rbac
**SDD**: `openspec/changes/2026-06-24-admin-with-rbac/`
**Owner**: futuro sprint

### T1.3 — R-PKG-009 mk:make:auth-user --login-field=<campo>
**SDD**: `openspec/changes/2026-06-24-auth-user-login-field/`
**Owner**: futuro sprint

### T1.4 — R-PKG-010 AuthController stub con RBAC integration
**SDD**: `openspec/changes/2026-06-24-auth-controller-rbac-stub/`
**Owner**: futuro sprint

---

## Track 2 — RETO retrofit (sprint posterior, depende de Track 1)

### T2.1 — R-RET-001 RETO módulo Admin rehacer
**SDD**: `openspec/changes/2026-06-24-retrofit-reto-admin-module/`
**Depends on**: T1.1, T1.2, T1.3, T1.4 mergeados al paquete y publicados como v1.5.0+
**Owner**: sprint futuro después de v1.5.0
**Pasos resumidos**:
1. Releer skills nuevas (`mk-director-laravel/SKILL.md` + 8 references).
2. Re-scaffoldear con `mk:module Admin --with-rbac` + `mk:make:auth-user Admin --login-field=ci`.
3. Regenerar AuthController con nuevo stub (RBAC-aware).
4. Aplicar 1.4.0 envelope estándar (`sendResponse`/`sendError`).
5. Implementar lógica de negocio RETO-only sobre los stubs.
6. Cerrar branch `makromania/260624-0511--admin-module` (NO mergeado, reemplazado).
7. PR → dev → bumpear a v1.5.0+.

---

## Definition of Done (este sprint)

- [ ] T0.1: R-G-033 firmada en rules_orchestration.md (público, commiteada).
- [ ] T0.2: SDD principal con 4 archivos + state.yaml actualizado.
- [ ] T0.3: 5 sub-changes abiertos con state + proposal + tasks + specs mínimo.
- [ ] T0.4: Continuation prompt creado y probado.
- [ ] T0.5: R-G-032 sync de 3 ubicaciones críticas (skill, AGENTS.md, MEMORY).
- [ ] Tag del sprint en monorepo (NO en sub-repo): `dogfooding-model-2026-06-24`.
- [ ] Mario aprueba el sprint en sesión.
- [ ] Continuation prompt actualizado con el catálogo cerrado (los 5 sub-changes con sus R-IDs).
- [ ] Próxima sesión puede retomar sin pedir contexto.

---

## Riesgo del sprint (NO bloqueante, flag-eado)

Si en algún punto se descubre que R-G-033 entra en conflicto con reglas existentes (R-MK-001, R-MK-002, R-G-001..032), la precedencia es:
1. **R-MK-001 (MME)** — sobre R-G-033 (la regla arquitectónica gana).
2. **R-G-033** — sobre R-G-032 (el modelo dogfooding gana sobre doc-sync, porque el sprint del modelo afecta qué se sincroniza).
3. **R-G-033** — sobre R-G-006 (BC no sagrado → si romper un contrato acelera el modelo, está bien, siempre que RETO migre).

Documentar cualquier excepción en `state.yaml` del sub-change correspondiente.

---

*Este sprint es meta. No genera tag del paquete. Su cierre se mide por cierre de los 5 sub-changes (Track 1) + el retrofit de RETO (Track 2).*
