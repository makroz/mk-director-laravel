# Spec — Dogfooding Model (R-G-033)

**Versión**: 1.0
**Fecha**: 2026-06-24
**Rule ID**: R-G-033 (en `~/.makromania/agency/global/rules_orchestration.md`)
**SDD**: `openspec/changes/2026-06-24-dogfooding-model/`

---

## Requirement: DOGFOOD-001 — Modelo dogfooding-first es la estrategia oficial

**SHALL**: El framework `mk-director` y su primer consumer real `mariogfos/reto` se desarrollan en paralelo. RETO es el dogfood project obligatorio.

**Rationale**: Detectado en 2026-06-24 que RETO escribió manualmente features (DiscoverAbilitiesCommand, AuthController con RBAC, profile fields, login field `ci`) que el paquete debería proveer. Sin dogfooding, el framework diverge de su único consumer.

**Métrica de éxito**:
- 100% de las features del paquete que se taguean en v1.5.0+ tienen un caso de uso documentado en RETO.
- 0 features RETO-only entran al paquete (R-G-033-B).
- BC se maneja caso por caso (R-G-033-C), no como restricción universal.

---

## Requirement: DOGFOOD-002 — Criterio genérico (R-G-033-A)

**SHALL**: Una feature entra al paquete SOLO si cumple los tres criterios:

1. **≥ 2 dominios bounded** la usarían (no solo RETO).
2. **Testeable sin dependencias de RETO** — los tests del paquete pueden correr contra fixtures locales.
3. **Documentable en la skill** sin asumir el dominio de RETO (no menciona "Admin", "ci", paths específicos de Bolivia).

**Rationale**: Sin este criterio, el paquete se convierte en "RETO monolítico" — útil solo para RETO, inútil para otros proyectos.

**Test** (checklist en PR review):
- [ ] El PR tiene ≥ 2 escenarios de uso en `specs/` que no son RETO.
- [ ] Los tests Pest del paquete corren sin clonar RETO.
- [ ] La skill `mk-director-*` actualizada NO menciona "RETO" en la descripción de la feature.

---

## Requirement: DOGFOOD-003 — Criterio RETO-only (R-G-033-B)

**SHALL**: Si una feature solo sirve para RETO, NO va al paquete — se queda en `mariogfos/reto` como código de aplicación.

**Default**: si el agente duda entre "va al paquete" y "se queda en RETO", default = **RETO**.

**Rationale**: Mantener el paquete genérico. RETO puede tener features custom; el paquete no.

**Test**:
- [ ] El PR al paquete NO incluye paths de RETO (`mariogfos/reto/`, `app/Modules/Admin/` si es específico).
- [ ] El PR al paquete NO hardcodea nombres de scope de RETO (`ci`, `cédula`, `Admin`, `Member` como casos únicos).

---

## Requirement: DOGFOOD-004 — BC opt-in (R-G-033-C)

**SHALL**: Romper un contrato (BC) es válido mientras `mariogfos/reto` migre en el mismo sprint. CHANGELOG debe tener migración clara.

**SHALL NOT**: Romper BC sin notificar a `mariogfos/reto` con ≥ 1 sprint de anticipación cuando sea posible (ej: cambios en endpoints públicos).

**Rationale**: Solo RETO consume hoy. Restricción universal de BC es especulación sin feedback loop.

**Métrica de éxito**:
- 100% de los PRs con BC tienen entrada en CHANGELOG con bloque `## Migración` (before/after).
- 100% de los PRs con BC notifican al agente RETO antes de mergear (vía PR thread o continuation prompt).

---

## Requirement: DOGFOOD-005 — Workflow iterativo (R-G-033-D)

**SHALL**: El workflow del paquete sigue este ciclo:

```
1. RETO descubre feature X faltante
       ↓
2. Agente RETO abre SDD change: 2026-YY-MM-DD-<X>-from-reto
       ↓
3. Agente mk-director evalúa triage:
       - ¿genérico (R-G-033-A)? → va al paquete
       - ¿RETO-only (R-G-033-B)? → se queda en RETO
       ↓
4. Si genérico: feature X propuesta al paquete en sub-change
       ↓
5. Implementación + tests Pest + sync R-G-032 (16+ locations)
       ↓
6. Tag rc1 + validación sandbox + validación RETO
       ↓
7. Tag v{N+1}.{MINOR}+ + publish Packagist (web/npm si aplica)
       ↓
8. RETO adopta: composer require makroz/director-laravel:^<version>
       ↓
9. Cierre del SDD change original (en RETO)
```

**Rationale**: El feedback loop RETO → paquete → RETO es el motor de evolución. Sin este ciclo, el modelo colapsa.

---

## Requirement: DOGFOOD-006 — Continuation prompt obligatorio (R-G-033-E)

**SHALL**: Cualquier sesión o agente que trabaje en el modelo dogfooding DEBE cargar `.makromania/agency/plantillas_prompt/mk-director-continuation.md` al inicio.

**Contenido mínimo del continuation prompt**:
- Contexto del modelo (dogfooding-first, RETO es el driver).
- Catálogo de issues activos (los sub-changes en vuelo).
- Comandos obligatorios al inicio (git branch check, leer SKILL.md, etc.).
- Reglas R-G-033-A..E (resumen, link a rules_orchestration.md).
- Links a SDD + skills + memoria.

**Rationale**: Múltiples sesiones/agentes van a tocar este trabajo. Sin continuation prompt, cada sesión empieza de cero. Anti-Kaizen.

---

## Escenarios (Gherkin-style)

### Scenario 1: Feature genérica entra al paquete

```
Given RETO implementó DiscoverAbilitiesCommand (268 LOC) en su rama
When el agente mk-director evalúa la feature
Then la feature cumple R-G-033-A (≥ 2 dominios: cualquier app con RBAC lo necesita)
And se abre sub-change `2026-YY-MM-DD-discover-abilities-to-core`
And el código de RETO se FILTRA antes de mergear (paths de RETO → relativos)
And la feature mergeada al paquete pasa los tests Pest sin RETO
```

### Scenario 2: Feature RETO-only NO entra al paquete

```
Given RETO necesita integrar con un sistema externo específico de Bolivia (SIN, SIRE)
When el agente mk-director evalúa la feature
Then la feature NO cumple R-G-033-A (solo RETO usa Bolivia-specific)
And se queda en RETO como `app/Modules/Admin/Integrations/Bolivia/SinService.php`
And el paquete NO la absorbe
```

### Scenario 3: BC breaking se maneja en el mismo sprint

```
Given el paquete cambia `mk:make:auth-user` para usar `sendResponse/sendError` (1.4.0)
And RETO tiene su AuthController custom con `JsonResponse` ad-hoc
When se publica v1.4.0
Then el CHANGELOG tiene bloque "Migración" con before/after del AuthController
And el agente RETO es notificado vía PR thread antes del merge
And el sprint de RETO para migrar se abre en paralelo (`2026-YY-MM-DD-reto-auth-controller-migration`)
```

### Scenario 4: Nueva sesión retoma sin pedir contexto

```
Given una sesión nueva arranca sin contexto
When el operador pasa el continuation prompt al inicio
Then el agente carga SKILL.md + rules_orchestration.md (R-G-033) + catálogo de sub-changes activos
And el agente sabe:
  - El modelo es dogfooding-first
  - RETO es el driver del roadmap
  - Los 5 sub-changes activos y su status
  - Las reglas R-G-033-A..E
  - Dónde están los SDD, skills, memoria
And el agente NO pregunta "¿en qué estamos?"
```

---

## Anti-patterns (rechazados en PR review)

- ❌ "RETO no es nuestro problema" — dogfooding-first ES la estrategia.
- ❌ "Absorbo DiscoverAbilitiesCommand al paquete tal cual" — siempre filtrar.
- ❌ "BC es sagrado" — mientras solo RETO consuma, BC es opt-in.
- ❌ "Espero 3 consumers para validar" — RETO ES el primer consumer.
- ❌ "Skip el continuation prompt" — el prompt es obligatorio (R-G-033-E).
- ❌ "Releases cada 2 semanas" — releases por demanda.

---

## Cross-references

- Regla canónica: `~/.makromania/agency/global/rules_orchestration.md` § R-G-033
- SDD principal: `openspec/changes/2026-06-24-dogfooding-model/`
- Sub-changes: ver `state.yaml` del SDD principal (catálogo dinámico).
- Continuation prompt: `~/.makromania/agency/plantillas_prompt/mk-director-continuation.md`
- Skills: `.makromania/agency/skills/mk-director-{laravel,core,web,mobile}/SKILL.md`
- RETO: `projects/reto/reto-api/` (branch actual: `makromania/260624-1605--bump-director-1.3.1`)
