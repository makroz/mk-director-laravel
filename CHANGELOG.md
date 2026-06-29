# Changelog

All notable changes to `makroz/director-laravel` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [v1.8.0] - 2026-06-29 — MAJOR — R-PKG-032 Pagination envelope grouping (`__extraData.pagination`)

> **Source**: Mario decision 2026-06-29 00:39 — "todos los datos de paginación siempre vayan dentro de `__extraData.pagination`".
> **Sprint**: `makromania/260629-0030--r-pkg-032-pagination-envelope` (canonical, in `.makromania/projects/mk-director/openspec/changes/2026-06-29-r-pkg-032-pagination-envelope/`).
> **Scope**: Cross-stack (Laravel + `@makroz/core` + `@makroz/web` + `@makroz/mobile`). RETO consumer **fuera de scope** (Mario regenera Admin module desde 0 en sesión separada, decisión 2026-06-29 00:39).
> **Spec**: R-PKG-032 (binding rule, en `~/.makromania/agency/global/rules_orchestration.md` + `projects/mk-director/AGENTS.md`).
> **Skill sync (R-G-032)**: 4 skills actualizadas (`mk-director-{laravel,core,web,mobile}/SKILL.md` con sección R-PKG-032). AGENTS.md del monorepo actualizado con R-PKG-032 rule binding. Documento `docs/UPGRADE_1.7_1.8.md` (NEW) con migration guide completo.
> **Tests pineados (HALLAZGO-NEW-03)**: 15 tests verde en paquete Laravel (source-parsing INTENCIÓN + e2e EFECTIVIDAD con Mockery ResponseFactory). Cross-stack: 11 type-level tests en `@makroz/core`, 7 tests web, 5 tests mobile — todos verde.

### ⚠️ BREAKING CHANGES (BC cross-stack — R-G-033-C BC opt-in válido)

- **R-PKG-032 (BLOCKER + MAJOR BC) — Pagination metadata GRUPED bajo `__extraData.pagination`**. Antes (v1.7.0 → v1.7.1-rc1): `__extraData` mezclaba las 5 (LengthAwarePaginator) o 3 (CursorPaginator) keys snake_case planas al top-level junto con custom keys del consumer (`audit_checked`, `request_id`). Ahora: las keys snake_case viven exclusivamente bajo `__extraData.pagination`, y las custom keys del consumer siguen planas (sin nesting). Post-v1.8.0 el shape canónico es:
  ```json
  {
    "success": true,
    "data": [...items...],
    "__extraData": {
      "pagination": {
        "current_page": 1,
        "last_page": 10,
        "per_page": 20,
        "total": 200,
        "has_more_pages": true
      },
      "audit_checked": true,
      "request_id": "req-abc"
    }
  }
  ```
  **NO** flag opt-in, **NO** compat dual (siguiendo política R-PKG-024 "no BC bridge, no opt-in"). Consumers en v1.7.x que lean `response.__extraData.last_page` plano deben migrar a `response.__extraData.pagination.last_page`. La migration es trivial (1 línea). Ver `docs/UPGRADE_1.7_1.8.md` para snippets completos.

### Added

- **`R-PKG-032` — `__extraData.pagination` GROUPED envelope (BC break + logical conclusion of R-PKG-024)**. `BaseController::sendResponse()` envuelve `extractPaginationMetadata()` bajo `['pagination' => ...]` en el `array_merge` final:
  ```php
  // v1.8.0:
  $extra = array_merge(['pagination' => $this->extractPaginationMetadata($result)], $extra);
  ```
  `ListManager::getExtraData()` sincroniza al mismo grouped shape (nuevo arg `$extras = []` opcional para merge de custom keys planas). El helper `extractPaginationMetadata()` **NO cambia internamente** (sigue retornando las 5 LA / 3 Cursor keys planas) — el wrap bajo `'pagination'` lo hacen los callers. Esto mantiene el helper **componible** para usos no-envelope.

### Changed

- **`LengthAwarePaginator` response shape**: las 5 keys snake_case (`current_page`, `last_page`, `per_page`, `total`, `has_more_pages`) ahora viven bajo `__extraData.pagination.*` en vez de `__extraData.*` (flat). Ver ejemplo JSON arriba.
- **`CursorPaginator` response shape**: las 3 keys (`per_page`, `next_cursor`, `prev_cursor`) agrupadas bajo `__extraData.pagination.*`. NO emite `current_page`/`last_page`/`total`/`has_more_pages` (no existen en `CursorPaginator`).
- **`ListManager::getExtraData()` signature**: nuevo parámetro opcional `array $extras = []` para merge flat de custom keys del consumer. Caller `['pagination' => [...]]` override entero del sub-object (array_merge semantics, last wins).
- **Caller-supplied `$extra` precedence**: `array_merge(['pagination' => $defaults], $extra)` — caller gana en conflicto. Caller puede override el sub-object entero pasando `'pagination' => [...]`.

### NOT emitted (intencionalmente, YAGNI)

- ❌ `from`, `to`, `path` (Laravel-specific UI metadata, no consumidos en frontend).
- ❌ `first_page_url`, `next_page_url`, `prev_page_url`, `last_page_url` (URLs Laravel-specific).
- ❌ `links` (HTML pagination links array).

### Migration guide (v1.7.x → v1.8.0)

**BC break**: consumers que lean `response.__extraData.*` directamente para acceder a pagination metadata deben envolver en `pagination`:

```php
// ❌ v1.7.x (LEGACY, OBSOLETE):
$pagination = $response['__extraData'];
$lastPage = $pagination['last_page'] ?? null;

// ✅ v1.8.0+:
$pagination = $response['__extraData']['pagination'] ?? [];
$lastPage = $pagination['last_page'] ?? null;
```

**TypeScript frontend** (`@makroz/web` + `@makroz/mobile` v1.3.0+):

```typescript
// ❌ v1.7.x (LEGACY):
const lastPage = response.__extraData?.last_page;

// ✅ v1.8.0+:
const lastPage = response.__extraData?.pagination?.last_page;
```

**NO migration needed** si tu consumer solo usa `BaseController::sendResponse()` + `useMkList` / `useMkInfiniteList` (los hooks ya están actualizados para leer del grouped object — public return shape unchanged).

**Custom keys** (audit_checked, request_id): NO cambian. Siguen planas en `__extraData` (no afectadas por R-PKG-032).

Ver `docs/UPGRADE_1.7_1.8.md` para el migration guide completo con snippets.

### Cross-stack bumped en este sprint

- `@makroz/core` 1.2.0 → **1.3.0** (type regrouping, type-level guarantee reforzado).
- `@makroz/web` 1.2.6 → **1.3.0** (`useMkList` + `useMkInfiniteList` leen de grouped pagination).
- `@makroz/mobile` 1.2.4 → **1.3.0** (idéntico a web, `MkTable.tsx` recibe la `pagination` prop agrupada).

### Consumer action

Bumpear `composer.json` a `^1.8.0` cuando Mario taguee el GA + force-update Packagist. **RETO**: NO bumpear en este sprint — Mario regenera Admin module desde 0 sobre v1.8.0+ directamente (decisión 2026-06-29 00:39).

---

## [v1.7.1-rc1] - 2026-06-28 — RC1 — Post-fase 12 RETO feedback fixes (PKG-NEW-17, PKG-NEW-18, BUG-NEW-auto-discover-serve)

> **Source**: Feedback RETO fase 12 2026-06-28 (`FEEDBACK-TO-MK-DIRECTOR-fase12.md`) — clean rebuild sobre v1.7.0 pineó 3 issues NUEVOS al paquete. RC1, pendiente RETO fase 13 rebuild para validar EFECTIVIDAD antes de GA.
> **Sprint**: `makromania/260628-2030--pkg-new-17-18-and-bug-auto-discover-serve` (origen `dev@23e1040`).
> **Spec**: 3 fixes, todos BC-safe (no breaking changes vs v1.7.0).
> **Tests pineados**: source-parsing INTENCIÓN (3 archivos, 22 tests verde) + e2e EFECTIVIDAD para PKG-NEW-18 (paginator real, 5 tests). E2e completo de PKG-NEW-17 + BUG-NEW-auto-discover-serve deferido al consumer per HALLAZGO-NEW-03 (package no puede bootear `php artisan serve` sin app Laravel completa).
> **Skill sync**: `mk-director-laravel` actualizado (3 gotchas nuevas + § "Cambios en v1.7.1-rc1"). Cross-stack type `@makroz/core` `MkListResponse<T>.__extraData` YA pineaba `has_more_pages` (verificado) — no requiere update.

### Fixed

- **PKG-NEW-17 (HIGH) — Scaffolder `MakeAuthUserCommand` ya NO emite los placeholders `{{moduleNameLower}}` / `{{moduleNamePluralLower}}` literales en strings PHP dinámicos**. El bug afectaba: (a) la `registerRoute` con `--with-crud` (`mk.auth:{{moduleNameLower}}` literal en el `->middleware(...)`), (b) las verify-email routes en el heredoc del array de replacements (`{{moduleNameLower}}.auth.verify` y `mk.auth:{{moduleNameLower}}` literales en el `<<<'PHP'`). El bug se debía a que `generateStub()` solo aplica `str_replace` a los stubs cargados, NO a los replacement values. Runtime symptom: HTTP 500 `Auth guard [{{moduleNameLower}}] is not defined.` en `POST /api/{scope}/auth/register`. Fix: PHP interpolation `{$scopeLower}` / `{$scopePlural}` en todos los strings dinámicos + cambio de `<<<'PHP'` (NOWDOC) a `<<<"PHP"` (heredoc) en el array de verify replacements para permitir interpolación. El parámetro `buildVerifyEmailReplacements(bool $enabled, string $scopeLower = '')` ahora acepta `$scopeLower` para que el heredoc lo pueda usar. **Solution of root** (per perfil Mario "soluciones de raíz, no parches"): se pineó el bug class completo en este sprint, no solo el caso reportado. Consumer ya NO necesita el workaround `sed` (R-AD-020) que RETO pineó en fase 12.
- **PKG-NEW-18 (MEDIUM) — `BaseController::extractPaginationMetadata()` ahora incluye `has_more_pages` (boolean) para `LengthAwarePaginator`**. Antes solo emitía 4 keys (`current_page, last_page, per_page, total`). `ListManager::getExtraData()` YA emitía las 5 keys — drift fixed. Frontend `@makroz/web` `useMkInfiniteList` lee `last_page` (no se rompe), pero consumers que leen `has_more_pages` veían `undefined` runtime. `@makroz/core` `MkListResponse<T>.__extraData` type ya pineaba `has_more_pages?: boolean` (línea 53) — no requiere cross-stack update. CursorPaginator NO emite `has_more_pages` (no tiene el método, solo `next_cursor` / `prev_cursor`).
- **BUG-NEW-auto-discover-serve (CRITICAL) — `MK_AUTO_DISCOVER_ABILITIES=true` ya NO brickea `php artisan serve`**. 2 bugs pineados: (1) `runningInConsole()` retorna `true` para `artisan serve` (Laravel CLI server cuenta como "console context"), así que el check dejaba pasar y auto-discover corría en el boot del HTTP server. (2) `$this->app->call(DiscoverAbilitiesCommand::class, ['--force' => true])` trataba la FQCN como callable, fallando con `Call to undefined function DiscoverAbilitiesCommand()`. Fix: skip cuando `$_SERVER['argv']` incluye cualquier long-running CLI context (`serve`, `octane:start`, `octane:reload`, `horizon`, `horizon:supervisor`, `queue:work`, `queue:listen`, `schedule:work`, `schedule:run`) + uso de `\Illuminate\Support\Facades\Artisan::call('mk:discover-abilities', ['--force' => true, '--json' => true])` en lugar del malformed `$this->app->call(Class, params)`. El guard pre-existente para `mk:discover-abilities` (avoid infinite recursion) se preserva. Consumer ya NO necesita comentar el flag en `.env` (R-AD-021 workaround absorbed).

### Migration guide (v1.7.0 → v1.7.1-rc1)

**Sin breaking changes**. Los 3 fixes son BC-safe:

- (a) Scaffolders que ya pineaban `--with-crud` en v1.7.0 ahora NO necesitan el workaround `sed` post-`mk:make:auth-user`. El `routes/api.php` generado viene con `mk.auth:{scope}` + `mk.ability:{scope}.{scope_plural}.create` limpios out-of-the-box.
- (b) Consumers que leen `__extraData.has_more_pages` ahora lo reciben populado (antes era `undefined` para endpoints que pasaban paginator al `BaseController::sendResponse`).
- (c) `MK_AUTO_DISCOVER_ABILITIES=true` en `.env` es ahora seguro para `php artisan serve`. El dev puede pinear el flag sin riesgo de brickear el server.

**Consumer action**: bump `composer.json` a `^1.7.1` cuando se libere el GA (post-fase 13 RETO validation). En RC1, RETO puede usar `path repo` o `"dev"` constraint para validar el fix end-to-end antes del GA.

## [v1.7.0] - 2026-06-28 — GA — R-PKG-024 Single-level envelope (PROHIBIDO `data.data`)

> **Source**: Mario flipió la decisión OBS-01 ("by design, NO se unificar", pineada en R-PKG-031 sprint 2026-06-28) → "ningún endpoint o DTO debe tener anidamiento `data.data`". GA trigger inmediato. BC break cross-stack (per R-G-033, válido mientras RETO migre en el mismo sprint).
> **Spec**: R-PKG-024 (binding rule, pineada en `~/.makromania/agency/global/rules_orchestration.md` + `projects/mk-director/AGENTS.md`).
> **Skill sync**: `mk-director-laravel` actualizado + CHANGELOG + DEVELOPER_GUIDE + OpenAPI spec + skill `mk-director-core` (`MkListResponse<T>` pineado) + skill `mk-director-web` + skill `mk-director-mobile` (fallback legacy eliminado).
> **Tests pineados**: source-parsing INTENCIÓN (no `data.data` en el paquete) + e2e EFECTIVIDAD (paginator retorna shape canónico).
> **PR**: pendiente Mario abrir — rama `makromania/260628-1900--r-pkg-024-single-level-envelope` (origen `dev@564a2d0`).

### ⚠️ BREAKING CHANGES (BC cross-stack — R-G-033 BC opt-in válido)

- **PROHIBIDO `data.data` en cualquier endpoint o DTO**. El envelope canónico es `{success, message, data, __extraData, debugMsg}`. Para colecciones, `data` es SIEMPRE un array directo de items (NO paginator Laravel nested). Metadata de paginación (`current_page`, `last_page`, `per_page`, `total`, `has_more_pages` para LengthAwarePaginator; `per_page`, `next_cursor`, `prev_cursor` para CursorPaginator) se emite en `__extraData` top-level (sibling de `data`).
- **Eliminado flag opt-in `mk_director.response.top_level_extra_data`** (rc12) y env var `MK_DIRECTOR_RESPONSE_TOP_LEVEL_EXTRA_DATA`. El shape es siempre canónico. No hay BC bridge.
- **Cambio de keys camelCase → snake_case en `ListManager::getExtraData()`**: `page` → `current_page`, `perPage` → `per_page`, `lastPage` → `last_page`, `hasMorePages` → `has_more_pages`. Frontend (`@makroz/web` + `@makroz/mobile`) YA consumía snake_case (ver `useMkInfiniteList.ts` línea 56-57: `extra.last_page`), por lo que la inconsistencia pre-existente queda corregida.

### Migration guide (RC12 / rc11 → v1.7.0)

**Backend (consumer code)**:
- ❌ `sendResponse([ 'data' => $paginator, '__extraData' => $extra ])` — legacy nested shape.
- ❌ `sendResponse([ 'data' => $data, '__extraData' => $extra ])` con `$data` siendo un paginator Laravel — legacy nested.
- ✅ `sendResponse($paginator, '', 200, $extra)` — BaseController auto-extrae items a `data` y pagination meta a `__extraData` top-level.
- ✅ `sendResponse($items, '', 200, $extra)` — items como Collection (NO paginator).
- ✅ `sendResponse($model, 'OK')` — single resource (no change vs rc11).

**Frontend (consumer code)**:
- ❌ `response.data.data` — removed (no `data.data` ever).
- ❌ `response.data.__extraData` — removed (top-level only).
- ✅ `response.data` — array de items o single resource (single-level).
- ✅ `response.__extraData` — pagination metadata (current_page, last_page, etc.) — siempre top-level.
- ⚠️ `useMkList` / `useMkInfiniteList` en `@makroz/web` + `@makroz/mobile` — el fallback legacy `response.data?.__extraData` se ELIMINA en v1.7.0. Si tu código lee `__extraData` directamente (no vía hooks), busca top-level.

### Fixed

- **R-PKG-024 (BLOCKER) — `BaseController::sendResponse()` ahora siempre emite single-level envelope**. Antes el método envolvía cualquier `$result` en `data` sin detectar paginadores → cuando el caller pasaba un `LengthAwarePaginator` Laravel, `autoTransform()` lo serializaba como ResourceCollection que emite `{data: [items], links, meta}` → resultado `data: { data: [items], links, meta }` (el `data.data` problemático). Fix: `sendResponse()` ahora detecta `AbstractPaginator` / `CursorPaginator` y extrae items a `data` + pagination metadata a `__extraData` top-level vía `extractPaginationMetadata()`. También eliminó el flag opt-in `mk_director.response.top_level_extra_data` (rc12) — el shape es siempre canónico post-GA. **Stub afectado**: `BaseController::sendResponse()` + `BaseController::extractPaginationMetadata()` (nuevo método helper).
- **R-PKG-024 (BREAKING FIX) — `CRUDSmart::index()` ahora pasa el paginator directamente al `sendResponse()`** (vía `sendResponse($paginator, '', 200, $extra)`), eliminando la duplicación manual de `total`, `service.afterList`, y `ListManager::getExtraData($paginator)`. El BaseController maneja la pagination metadata automáticamente; las keys del caller en `$extra` (e.g., custom service hooks) ganan sobre las defaults en conflicto. **Stub afectado**: `Traits/CRUDSmart.php::index()`.
- **R-PKG-024 (BREAKING FIX) — `Controller::index()` (legacy template method, pre-1.3.0) ahora también pasa el paginator directamente**. Mismo refactor que `CRUDSmart`. **Stub afectado**: `Controllers/Controller.php::index()`.
- **R-PKG-024 (BREAKING FIX) — `ListManager::getExtraData()` keys normalizadas a snake_case** (`current_page`, `last_page`, `per_page`, `has_more_pages`). Camel-case pre-existente (`page`, `perPage`, `lastPage`) era inconsistente con frontend `useMkInfiniteList` (que lee `extra.last_page`). Migration: ver § Migration guide. **Stub afectado**: `Managers/ListManager.php::getExtraData()`.
- **R-PKG-024 — `mk:status --response-shape` ahora detecta `data.data` anidamiento** (no solo `__extraData` anidado). Reporta findings como `error` (no `warning` como en rc12). Detecta 2 patrones: (a) `sendResponse(['__extraData' => ...])` legacy, (b) `sendResponse(['data' => $paginate*])` paginator wrapping. Audit non-ignorable post-GA. **Stub afectado**: `Console/Commands/MkCheckCommand.php::auditResponseShape()`.

### Added

- **R-PKG-024 — `BaseController::extractPaginationMetadata()` helper method** (nuevo). Extrae `current_page`, `last_page`, `per_page`, `total`, `has_more_pages` (LengthAwarePaginator) o `per_page`, `next_cursor`, `prev_cursor` (CursorPaginator) para emisión top-level en `__extraData`. Snake_case para consistencia con `@makroz/web` `useMkInfiniteList` + `@makroz/core` `MkResponse<T>.__extraData` contract.

### Documentation

- **R-PKG-024 regla binding cross-project** pineada en `~/.makromania/agency/global/rules_orchestration.md` (nueva sección `### R-PKG-024 — Single-level envelope`). 20 lugares R-G-032 sync (CHANGELOG, DEVELOPER_GUIDE, OpenAPI spec, 4 skills, AGENTS.md monorepo, CHANGELOG monorepo, 4 packages cross-stack, RETO api_contract.md + state.yaml + composer.json).
- **`@makroz/core` `MkListResponse<T>` type** (nuevo, pineado en sprint paralelo) — type-level guarantee: colecciones paginadas son `MkResponse<T[]>`, NO `MkResponse<{data: T[]}>`.
- **`@makroz/web` + `@makroz/mobile` `useMkList` cleanup** (sprint paralelo) — fallback legacy `response.data?.__extraData` eliminado. Single source of truth = top-level.

### Migration del consumer RETO (sprint `2026-06-28-fase-12-retos-bump-v170`)

RETO bumpea `composer.json` a `^1.7.0` post-publish. Validar e2e:
- `php artisan mk:status --response-shape` → 0 violations (todas las endpoints paginadas retornan `{data: [items], __extraData: {pagination}}`).
- `php artisan mk:status` → 0 SmartController findings.
- `curl GET /api/admin/admins | jq` → `data` es array directo (NO objeto paginator).
- 17/17 e2e tests verde + 0 workarounds + 0 grep `data\.data` en runtime responses.

---

## [Unreleased]

### Fixed

- **PKG-NEW-16 (HIGH) — `register()` scaffoldeado ahora valida `name` + `loginField` (no rompe con NOT NULL)**. Antes el método `register()` scaffoldeado solo validaba profile fields + password, dejando `name` (NOT NULL en `AuthUser` base) y el loginField (NOT NULL UNIQUE, `email` por default) sin validar. Resultado runtime: SQLSTATE 500 `NOT NULL constraint failed: {scope}.name` cuando el consumer trataba de usar register, rompiendo el contrato del paquete que promete validación via `$request->validate()` antes del insert. Fix: agregar `'name' => ['required', 'string', 'max:255']` siempre + `'{$loginField}' => ['required', 'email'|'string', 'unique:{scopePlural},{loginField}']` según `--login-field`. La regla de email aplica solo cuando `loginField=email` (default); para `--login-field=ci`/`phone`/`username`, la regla es `['required', 'string', 'unique:...]`. BC preservada: profile fields existentes siguen funcionando, solo se agregaron reglas. **Stub afectado**: `MakeAuthUserCommand::buildRegisterMethod()` (vía `mergeRulesPhp()`). **Source**: feedback RETO fase 11 (`FEEDBACK-TO-MK-DIRECTOR.md`, 2026-06-28).

- **PKG-NEW-09 (BLOQUEANTE) — `register()` scaffoldeado pinea middleware `mk.auth` + `mk.ability` cuando `--with-crud` está activo**. Antes el endpoint `register` quedaba público por default cuando el scope se scaffoldeaba con `--with-crud` sin `--with-auth-rbac` — cualquiera podía crear admins vía `POST /api/admin/auth/register` (escalación de privilegios). El consumer tenía que pinear el middleware manualmente en `routes/api.php` (workaround C-01 RETO). Fix: el scaffolder ahora pinea `->middleware(['mk.auth:{scope}', 'mk.ability:{scope}.{table}.create'])` en el registerRoute cuando `$withCrud` es true (defense-in-depth). BC preservada: sin `--with-crud`, register sigue público (consistente con la doc del paquete). Cuando `--with-auth-rbac` está activo, R-PKG-010 sigue aplicando sus ability checks internos — pinear el middleware es redundante pero harmless. **Stub afectado**: `MakeAuthUserCommand::handle()` → bloque `$registerRoute`. **Source**: feedback RETO fase 11 (`FEEDBACK-TO-MK-DIRECTOR.md`, 2026-06-28).

- **OBS-02 (DEFENSE-IN-DEPTH) — `mk:auth:create-super-admin` ahora pinea `is_active = true` explícito al crear el admin**. Antes el comando creaba el admin con `is_active = null` (semántica per PKG-NEW-04: `null` = permitido, compat con datos preexistentes). Defense-in-depth: un admin NUEVO siempre debe estar activo explícito, no `null` implícito. Fix: después del `create()`, si la columna `is_active` existe en la tabla del scope (`Schema::hasColumn()` check, BC con scopes sin la columna), pinear `$admin->is_active = true; $admin->save()`. Bypass `fillable` (property assignment + save) porque `is_active` NO está en `$fillable` del modelo base `AuthUser` ni del stub scaffoldeado. Follow-up opcional: pinear `is_active` en `$fillable` del stub del modelo (defense-in-depth adicional, no requerido para este fix). **Stub afectado**: `AuthCreateSuperAdminCommand::handle()` (import `Schema` facade agregado). **Source**: feedback RETO fase 11 (OBS-02, 2026-06-28).

### Documented

- **OBS-01 (BY DESIGN) — Doble envelope en responses paginadas es patrón canónico, NO unificar**. El `BaseController::sendResponse()` envuelve TODA response en `{success, message, data, debugMsg}` (R-PKG-023, desde v1.4.0). Para responses paginadas (`GET /api/{scope}` list endpoints), el paginator de Laravel anida `data/links/meta` DENTRO de `data` del envelope → **doble envelope**: `{success, message, data: {data: [...], links: {...}, meta: {...}}, debugMsg: []}`. Frontend debe parsear `data.data` (no `data`) para acceder a los items paginados. Patrón consistente con `@makroz/core` `MkResponse<T>` + consumido por `useMkInfiniteList` en `@makroz/web` + `@makroz/mobile`. **Decisión**: documentar (CHANGELOG + skill `mk-director-laravel` + `api_contract.md` del consumer), NO unificar. Cambiar el shape rompería el contrato cross-stack con `@makroz/web` + `@makroz/mobile` (consumer Provider abstrae el shape, pero pinear un cambio cross-stack merece sprint dedicado). **Documentado en**: `mk-director-laravel` skill § "Versión actual" + este CHANGELOG + RETO consumer `api_contract.md` § Convenciones. **Source**: feedback RETO fase 11 (OBS-01, 2026-06-28).

## [1.6.2] - 2026-06-28 — R-PKG-029 Post-RETO fase 10b feedback (solución óptima para RETO + defense-in-depth)

> Source: `.makromania/projects/reto/modules/admin/FEEDBACK-TO-MK-DIRECTOR.md` (fase 10b, clean rebuild sobre v1.6.1).
> Driver: Mario aprueba Opción C — pinear 3 issues (PKG-NEW-12/14/15) en sprint dedicado para que RETO pueda hacer fase 11 sin workarounds.
> Spec: R-PKG-029. Tests pineados: 11 nuevos en `MakeAuthUserRPkg029FixesTest.php` + 4 actualizados (LEGACY BUG-05/BUG-NEW-01/BUG-NEW-02).
> PR: #35 (SHA `4f1f7ca`). Rama: `makromania/260628-1700--pkg-new-12-14-15-fixes`.

### Fixed

- **PKG-NEW-12 (MEDIUM) — `logout()` scaffoldeado referencia `$user->currentAccessToken()?->id` (no `$token?->id` indefinido)**. El evento `auth.logout` auditaba `'token_id' => $token?->id` pero `$token` no estaba definido en el scope del método `logout()` scaffoldeado (el stub usa `$user->safeLogoutCurrentToken()` de R-PKG-027 PKG-NEW-08 que no expone el token al consumer). En runtime PHP 8.5+ esto emite warning `Undefined variable $token` (deprecation) y el listener de `auth.logout` recibía `null` para `token_id`. Fix: usar `$user->currentAccessToken()?->id` (null-safe, consistente con el helper). **Stub afectado**: `MakeAuthUserCommand.php` → `$rbacAuditLogout`.

- **PKG-NEW-15 (MEDIUM) — `login()` y `me()` ahora retornan el mismo shape canónico (`$user` → `autoTransform()`)**. Antes `login()` construía un `array_merge` ad-hoc con `id`/`name`/`loginField` + profile fields + `roles` (mapeados a `[id, name]`) + `abilities` (top-level combinadas de roles + directAbilities), mientras `me()` retornaba el modelo completo con abilities **anidadas por role**. Esto causaba drift cross-stack: el frontend debía parsear 2 shapes distintos y `api_contract.md` documentaba solo el formato top-level (drift con `me()` real). **Fix (Opción B)**: ambos retornan `$user` (modelo completo) y `BaseController::sendResponse()` aplica `autoTransform()` con el `apiResource` del modelo (`AdminResource`/`MemberResource`/etc.) — patrón canónico del paquete desde 1.4.0. Frontend parsea 1 formato. BC: este cambio SOLO afecta el contenido dentro de `data.{scope}`; los headers `access_token`/`refresh_token`/`token_type`/`expires_in` siguen iguales. Para customizar, override `login()` completo. **Stub afectado**: `src/Stubs/auth-user.auth-controller.stub` + `MakeAuthUserCommand::buildLoginResponseArray()` ahora retorna `'$user'` literal (de 60 líneas a 1).

### Added

- **PKG-NEW-14 (MEDIUM UX) — Scaffolder ahora advierte sobre cache driver que no soporta tags**. SmartController, CRUDSmart, CacheTrait y BaseModelBuilder usan `Cache::tags()` extensivamente. Si `CACHE_STORE` apunta a un driver que no soporta tags (`file`, `database`, `array`, `null`, `apc`), el primer request CRUD revienta con `RuntimeException: cache driver does not support tags` — un gotcha silencioso porque la falla es runtime (no compile/lint-time) y `mk:status` no la detecta. **Fix**: nuevo método `MakeAuthUserCommand::checkCacheDriver()` (llamado post-scaffold después de `checkSanctumInstalled()`) que:
  1. Resuelve el cache store activo (`config('cache.default')` o `CACHE_STORE` desde `.env`).
  2. Si NO soporta tags (lista conservadora: `redis`/`memcached`/`dynamodb` son los únicos que sí), emite warning claro con:
     - El error específico que va a ver (`RuntimeException: cache driver [X] does not support tags`).
     - Fix por ambiente: dev/local → `CACHE_STORE=array` + `MK_CACHE_ALLOW_FULL_CLEAR=true`; staging/prod → `CACHE_STORE=redis` o `memcached`.
  3. Helpers nuevos: `resolveCacheStore()` y `cacheStoreSupportsTags()` (testeables). **Stub afectado**: `MakeAuthUserCommand.php` → sección post-scaffold.

### Migration

- **PKG-NEW-15 — consumers que dependían del shape top-level de `login()`** deben migrar a un `Resource` (es el patrón canónico desde 1.4.0). Si el modelo del scope declara `protected $apiResource = {Resource}::class;` (ej: `AdminResource`), `autoTransform()` aplica el Resource automáticamente. Consumers que overridean `login()` para customizar el shape siguen funcionando sin cambios. Ver `docs/UPGRADE_1.2.md` para ejemplos completos.
- **PKG-NEW-12** — consumers con listener custom de `Mk\Director\Auth\Events\AuthEvent` con key `auth.logout` siguen funcionando: `token_id` ahora recibe el valor correcto (`int|null` del token actual) en vez de `null` silencioso.

## [1.6.1] - 2026-06-28

### Fixed

- **PKG-NEW-10** — Removed `'description'` from the `'searchable'` array of the `RoleController` stub (`role-controller.stub`). The `roles` table does not have a description column.
- **PKG-NEW-11** — Added the auth middleware constructor (`$this->middleware('mk.auth:{{moduleNameLower}}');`) to the generated controllers (`AbilityController`, `RoleController`, and `{{ModuleName}}Controller` / `AdminController`). This ensures that authorization middleware is enforced by default in generated controllers.

## [1.6.0] - 2026-06-28 — R-PKG-027 Scaffolder hardening + auth flow defaults

> Source: Code Review 4R post-merge audit 2026-06-28 sobre `mariogfos/reto` (rama `dev` commit `372c28d`, fase 9 mergeada como `reto-admin-v1.1.0`).
> Pineado por Mavis (consumer) en `.makromania/projects/reto/modules/admin/FEEDBACK-TO-MK-DIRECTOR.md` (9 hallazgos pineables, 8 fixes en este sprint).
> Release: `v1.6.0` GA, tag `c378c56` (post-merge PRs #31 + #32 + #33). Mario dispara tag + Packagist publish (boundary binding).

### Fixed

- **PKG-NEW-01 (HIGH) — Scaffolder `--with-crud` ahora SIEMPRE crea `email_verified_at` (nullable) en la migration del scope**. Antes la columna solo se generaba cuando se pasaba `--verify-email` (placeholder `{{emailVerifiedAtColumn}}`). Esto causaba drift: el modelo base `AuthUser` declara el cast `email_verified_at => datetime` + implementa `MustVerifyEmail`, así que cualquier path que setee el atributo (factory con `Schema::hasColumn` workaround, register, verificación futura) revienta con SQLSTATE si la columna no existe. Fix: la columna se crea siempre — barato, nullable, no molesta, consistente con el cast del modelo. Defense-in-depth. **Stub afectado**: `src/Stubs/auth-user.migration.stub`.

- **PKG-NEW-02 (MEDIUM) — `{{ModuleName}}Service::beforeCreate()` renombrado a `mutateData()` + eliminado el `Hash::make` manual**. El nombre `beforeCreate` inducía a error (se ejecutaba también en `update()`). Además el `Hash::make` manual con check `str_starts_with($password, '$2y$')` duplicaba el hasheo del cast `'password' => 'hashed'` del modelo base `AuthUser`, produciendo `bcrypt(bcrypt(plain))` en algunos paths. Fix: delegar 100% al cast del modelo (zero-overhead, BC-safe porque `AuthUser` ya tiene el cast). **Stub afectado**: `src/Stubs/auth-user/admin-service.stub`.

- **PKG-NEW-04 (BLOCKER) — Login flow scaffoldeado ahora consulta `is_active` por default** (cuando la columna existe en la tabla del scope). Antes el flow solo validaba `Hash::check()` + `getAuthScope()`, dejando el flag `is_active` como decoración. Un admin desactivado podía loguearse y obtener tokens válidos. Fix: el `AuthController` scaffoldeado agrega `$isActiveCheck = Schema::hasColumn(...) && $user->is_active === false` y lo incluye en el guard del login. Semántica: `null` o `true` = permitido (compat con datos preexistentes); `false` = bloqueado. **Stub afectado**: `src/Stubs/auth-user.auth-controller.stub`.

- **PKG-NEW-05 (MEDIUM) — `forgot()` y `reset()` también consultan `is_active`** (consistencia con el fix del login). Un admin desactivado no recibe email de reset (anti-enumeración + anti-UX-inconsistente) y no puede resetear su password (admin sigue bloqueado después del reset). **Stub afectado**: `src/Stubs/auth-user.auth-controller.stub`.

- **PKG-NEW-06 (MEDIUM) — `{{ModuleName}}Data` DTO ahora mapea TODOS los profile fields** en `fromRequest`, `fromArray`, y `toArray`. Antes el DTO solo mapeaba `name`/`email`/`password`, perdiendo los profile fields (`full_name`, `ci`, `phone`, `address`, `photo_path`, `is_active`, etc.). Si alguien adoptaba el DTO tal cual, rompía la feature silenciosamente. Fix: 3 nuevos helpers en `MakeAuthUserCommand` (`buildProfileFieldsFromRequest`, `buildProfileFieldsFromArray`, `buildProfileFieldsToArray`) + 3 nuevos placeholders en `admin-data-dto.stub`. **Stubs afectados**: `src/Stubs/auth-user/admin-data-dto.stub`, `src/Console/Commands/MakeAuthUserCommand.php`.

- **PKG-NEW-07 (MEDIUM) — Scaffolder `--with-crud` ahora genera `SyncRoleAbilitiesRequest`** (FormRequest dedicado) para `PUT /roles/{id}/abilities`. Antes el `RoleController::syncAbilities` validaba inline con `$request->validate([...])` — inconsistente con `AssignRolesRequest` / `AssignDirectAbilitiesRequest` que SÍ usan FormRequest. Esta inconsistancia violaba R-AD-012 del propio paquete ("validation via FormRequest, NO inline"). Fix: nuevo stub `sync-role-abilities-request.stub` + `role-controller.stub` actualizado para typehint el FormRequest + el comando scaffoldeado genera el archivo. **Stubs afectados**: `src/Stubs/auth-user/sync-role-abilities-request.stub` (nuevo), `src/Stubs/auth-user/role-controller.stub`, `src/Console/Commands/MakeAuthUserCommand.php`.

- **PKG-NEW-08 (HIGH) — Helper `AuthUser::safeLogoutCurrentToken()` + AuthController stub actualizado**. El patrón naive `$token = $user->currentAccessToken(); $token->delete();` scaffoldeado en `logout()` revienta con `Call to a member function delete() on null` cuando `currentAccessToken()` retorna null (autenticación via Sanctum stateful SPA con cookies, o token ya revocado). Fix: nuevo método en el modelo base `AuthUser` que encapsula la null-safety. Retorna `bool` (`true` si revocó un token, `false` si no había). El stub scaffoldeado ahora usa `$user->safeLogoutCurrentToken()` en vez del patrón naive. **Archivos afectados**: `src/Auth/Models/AuthUser.php` (+nuevo método), `src/Stubs/auth-user.auth-controller.stub` (logout usa el helper). BC-safe: additive (nuevo método), no rompe consumers existentes.

### Notas de migración

Todos los fixes son **BC-safe** para consumers existentes:

- **PKG-NEW-01**: el cambio de migration stub SOLO afecta a consumers que regeneren un scope con `mk:make:auth-user {Scope}`. Consumers existentes que ya tienen la tabla creada sin `email_verified_at` deben correr una migration adicional (similar a `mk:fix:sanctum-uuids`).
- **PKG-NEW-02**: rename + cambio de hash. Consumers que override `beforeCreate()` deben renombrar a `mutateData()`. El cambio de hash (manual → cast) puede romper consumers que NO tienen el cast `'hashed'` en su modelo concreto (improbable — `AuthUser` base ya lo provee).
- **PKG-NEW-04/05**: el check de `is_active` solo aplica si la columna existe (`Schema::hasColumn`). Consumers sin la columna no ven cambio de comportamiento.
- **PKG-NEW-06**: solo afecta al DTO scaffoldeado. Si el consumer ya cableó el DTO con un override custom, debe actualizar `fromRequest`/`fromArray`/`toArray` para incluir los profile fields (o regenerar).
- **PKG-NEW-07**: agregar FormRequest. Consumers que override `RoleController::syncAbilities` con validación inline deben migrar al nuevo FormRequest.

### Patrón pineado

El code review 4R detectó un **patrón sistémico**: el scaffolder `--with-crud` genera stubs que omiten 4 reglas críticas (is_active check, login_field respeto en validaciones específicas, FormRequests consistentes, DTOs cableados). Cualquier consumer que use `--with-crud` sin auditar el código scaffoldeado va a tener estos bugs en producción. **R-PKG-027 cierra la clase de bugs**, no solo los 7 específicos — futuros consumers se benefician automáticamente.

### Pendiente (no incluido en R-PKG-027)

- **PKG-NEW-03** (`login()` hardcodea email) — verificado post-audit: el stub YA usa `{{loginField}}` placeholder correctamente desde v1.5.0-rc3. El reporte 4R menciona drift en RETO pero el scaffolder está OK. Sin cambios necesarios en el paquete.
- **PKG-NEW-08** (`MkAuth::safeLogout()` helper) — feature opcional, sale casi gratis pero scope creep. Diferido a sprint futuro.
- **PKG-NEW-09** (SKILL.md gotchas `Ability.module` + `register` público) — defense-in-depth docs. Diferido a R-G-032 sync del sprint (5 líneas de docs, no de código).

### Cross-refs

- **Reporte 4R preservado**: `.makromania/projects/reto/modules/admin/audits/4r-code-review-fase9-postmerge-2026-06-28.md` (consumer side).
- **Feedback activo**: `.makromania/projects/reto/modules/admin/FEEDBACK-TO-MK-DIRECTOR.md` (consumer side, 9 hallazgos pineables).
- **Sprint state**: `.makromania/projects/reto/openspec/changes/2026-06-26-reto-admin-module-pilot/state.yaml` (consumer SDD).
- **Paquete rama**: `origin/makromania/260628-1110--r-pkg-027-fixes` (lista para squash-merge a `dev`).

## [1.6.0-rc13] - 2026-06-27

### Fixed

- **R3.1 (HIGH) — SQL injection vector in `BaseController::getDebugData()`**: the previous code interpolated the query directly into a database call that prepended the string `EXPLAIN` to the SQL. An authenticated `super-admin` or `dev` could pass `?debug=true&_debug=1` to reach this path; any field that leaked into the query was vulnerable. The new behavior logs slow-query candidates via `Log::debug()` for offline analysis (safe default), and gates the actual `EXPLAIN` execution behind the new config flag `mk_director.debug.explain_enabled` (default `false`). When the flag is `true`, the SQL is logged as a `warning` so a developer can run the analysis manually in a safe environment — the query is NEVER interpolated into a database call. The role gate from R2-010 is preserved (defense-in-depth). Pineado con `tests/Unit/Controllers/BaseControllerGetDebugDataTest.php` (5 tests).

- **R3.2 (HIGH) — `MkMultiTenantPlugin::resolveTenantId()` now uses `getTenantId()` centralizado**: when the user model implements `getTenantId()` (typically via the `HasTenantMembership` trait), the plugin uses the method as the preferred resolver — matching the pattern already used by `TenantResolver` (TenantResolver.php:89-90). The legacy fallback to direct property access (`$user->{$this->tenantColumn}`) is preserved for consumers that predated the trait. This lets consumers with custom tenant-resolution logic (e.g. derived from org memberships) override the accessor without touching the plugin. Pineado con `tests/Unit/Plugins/MkMultiTenantPluginResolveTenantIdTest.php` (3 tests).

- **R3.3 (HIGH) — `CacheManager::flush()` no longer silently nukes the entire cache**: the previous fallback (`$cache->clear()`) when the cache driver does not support tags wipes EVERY key in the app's cache store — not just the keys for the requested tags. That's destructive in production where multiple modules share the same cache store. The new behavior gates the fallback path with `mk_director.cache.allow_full_clear` (default `false`). When the flag is `false` and no tags support: throw a `RuntimeException` with an actionable message. When the flag is `true`: preserve the legacy `$cache->clear()` behavior (dev environments that use file/database cache). Production MUST use a cache store that supports tags (Redis, Memcached) — see `cache.store` config. Pineado con `tests/Unit/Managers/CacheManagerFlushTest.php` (4 tests).

- **R3.4 (HIGH) — `MkServiceProvider::registerGlobalCacheListener()` regex broadened**: the previous regex `(update|delete|insert\s+into)` missed `REPLACE`, `TRUNCATE`, and `upsert()` (Eloquent's `upsert()` generates `INSERT ... ON DUPLICATE KEY UPDATE` on MySQL/MariaDB). The auto-cache invalidation listener would NOT fire for these mutations, leaving stale cache after a `TRUNCATE TABLE` or `Eloquent::upsert()`. The new pattern is `(update|delete|insert(\s+into)?|replace(\s+into)?|upsert|truncate)` with the same `\s+` requirement after the verb (so `updateHook` and `deletedAt` are not matched). Pineado con `tests/Unit/Console/RegisterGlobalCacheListenerRegexTest.php` (3 tests).

- **R3.5 (HIGH) — `mk:make:auth-user {Scope}` generated `register()` now wraps `create + setAuthScope` in `DB::transaction()`**: the previous code did both as separate statements, NOT wrapped in a transaction. If `setAuthScope` failed after `create` succeeded, the user row was orphaned (no scope bound, no error reported). The new stub uses `\DB::transaction(function () { ... })` with `return $user` so the rest of the method has access to it. `sendEmailVerificationNotification` stays OUTSIDE the transaction (queueable side-effect — must not be rolled back if the queue worker is down, and does not need to be in the same TX as user creation). Pineado con `tests/Feature/MakeAuthUserCommandBuildRegisterTransactionTest.php` (2 tests, source-parsing the stub heredoc).

### Added

- **3 new config flags** (env-driven, defaults safe):
  - `mk_director.response.top_level_extra_data` (R-PKG-023, lifted forward from rc12 work) — `MK_DIRECTOR_RESPONSE_TOP_LEVEL_EXTRA_DATA` (default `false`).
  - `mk_director.cache.allow_full_clear` (R3.3) — `MK_CACHE_ALLOW_FULL_CLEAR` (default `false`).
  - `mk_director.debug.explain_enabled` (R3.1) — `MK_DIRECTOR_DEBUG_EXPLAIN_ENABLED` (default `false`).
  The original flat `mk_director.debug` boolean is preserved as the inner `debug.enabled` for BC. See `config/mk_director.php` and `DEVELOPER_GUIDE.md` for full descriptions.

- **10 new unit test files** (29 tests, 79 assertions) pineando R3.1–R3.5:
  - `tests/Unit/Controllers/BaseControllerGetDebugDataTest.php` (5)
  - `tests/Unit/Plugins/MkMultiTenantPluginResolveTenantIdTest.php` (3)
  - `tests/Unit/Managers/CacheManagerFlushTest.php` (4)
  - `tests/Unit/Console/RegisterGlobalCacheListenerRegexTest.php` (3)
  - `tests/Feature/MakeAuthUserCommandBuildRegisterTransactionTest.php` (2)
  - Plus 3 extensions to `tests/Unit/MkDirectorConfigDefaultsTest.php` (3) verifying the new flags are env-driven and default `false`.
  - Plus 2 existing test files (`BaseControllerDebugGateTest.php`, `ListManagerSecurityTest.php`) continue to pass — the R3.x fixes are additive (no regressions on R2-010 hardening).

### Migration desde rc12

None of the R3.x fixes are BC-breaking for runtime consumers:

- **R3.1 (SQL injection fix)**: code path gated by `mk_director.debug.explain_enabled` (default `false`). Consumers that never set the flag see the same behavior, just safer (the dangerous `EXPLAIN` interpolation is gone). To re-enable manual EXPLAIN analysis, set `MK_DIRECTOR_DEBUG_EXPLAIN_ENABLED=true` and inspect the warning log.
- **R3.2 (resolveTenantId)**: prefers the new `getTenantId()` method, falls back to direct property access. Consumers that don't implement `HasTenantMembership` see identical behavior.
- **R3.3 (CacheManager::flush gate)**: the gate defaults to `false` (safe). Dev environments that use file/database cache MUST set `MK_CACHE_ALLOW_FULL_CLEAR=true` to opt into the legacy nuke. Production environments that use Redis/Memcached see no behavior change.
- **R3.4 (regex broadening)**: additive. Existing `update|delete|insert into` writes are still matched; new verbs (REPLACE/TRUNCATE/upsert) now also match.
- **R3.5 (buildRegisterMethod DB::transaction)**: only affects the SCAFFOLDER output. Consumers that regenerate a scope with `mk:make:auth-user {Scope}` get the new transactional version. Existing AuthController code in consumer projects is unaffected unless they regenerate.

**BC-safe for the entire rc12 → rc13 transition.**

## [1.6.0-rc12] - 2026-06-27

### Added

- **`BaseController::sendResponse()` signature extended** with optional 4th parameter `array $extra = []`. When the caller passes a non-empty `$extra` AND the config flag `mk_director.response.top_level_extra_data` is true, the response envelope emits `__extraData` as a **TOP-LEVEL sibling of `data`** — matching the `@makroz/core` `MkResponse<T>` contract and the `useMkInfiniteList` consumption shape in web + mobile. BC strategy: opt-in via flag (default `false` in rc12, `true` in GA). See "Migration desde rc11" below.

- **`mk:status --response-shape` audit option** on the existing `MkCheckCommand`. Walks every controller in `app/Http/Controllers` and `app/Modules/*` and warns about legacy `sendResponse(['data' => ..., '__extraData' => ...])` calls (the rc11 and earlier pattern). Consumers that flip the flag MUST also migrate any custom controllers — otherwise those endpoints emit the legacy nested shape and the envelope becomes inconsistent. Output: per-file/per-line warning with migration hint. Run with `php artisan mk:status --response-shape`.

- **`config('mk_director.response.top_level_extra_data')`** flag, env-driven via `MK_DIRECTOR_RESPONSE_TOP_LEVEL_EXTRA_DATA` (default `false`). The flag flips to `true` at GA. The shape is opt-in per environment during the transition window.

- **`config('mk_director.cache.allow_full_clear')`** flag, env-driven via `MK_CACHE_ALLOW_FULL_CLEAR` (default `false`). Used by `CacheManager::flush()` to gate the fallback path when the cache driver does not support tags — prevents accidental nuke of the entire app cache. (Lifted forward to rc13 implementation.)

- **`config('mk_director.debug.explain_enabled')`** flag, env-driven via `MK_DIRECTOR_DEBUG_EXPLAIN_ENABLED` (default `false`). Used by `BaseController::getDebugData()` to gate the optional `EXPLAIN` query analysis. (Lifted forward to rc13 implementation.)

- **Two new unit test files** (15 tests, 51 assertions):
  - `tests/Unit/Controllers/BaseControllerSendResponseExtraTest.php` (5 tests): pinean the new `sendResponse` signature, the top-level `__extraData` emission when both `$extra` and the flag are non-empty, the guard with the non-empty + flag check, the original payload regression guard, and the debug-merge behavior preservation.
  - `tests/Unit/Controllers/ControllerIndexTopLevelTest.php` (5 tests): pinean the legacy `Controller::index()` (template-method controller) branches on the flag, keeps the legacy nested path when off, and emits the new top-level path via the 4-arg `sendResponse` form when on.
  - `tests/Unit/CRUDSmartIndexTopLevelTest.php` (6 tests): pinean the recommended `CRUDSmart::index()` (used by 95% of generated controllers) branches on the flag, preserves the legacy nested path, and emits the new top-level path. Also pines regression guards for `fireAfterResponse` plugin hook and `afterList + getExtraData` extra-data assembly.
  - `tests/Unit/Console/MkCheckCommandResponseShapeTest.php` (4 tests): pinean the `--response-shape` option in the signature, the dispatch to `auditResponseShape`, the audit method's structure (scans both `app/Http/Controllers` and `app/Modules`), and the legacy-nested detection logic.
  - `tests/Unit/Console/LintBoundariesDtoLayoutTest.php` (5 tests): pinean the new `ALLOWED_PATTERNS` (with `DTOs\` as canonical), the deprecated `Api\Dto\` pattern, the `isDeprecatedExternal` warning emit method, and the split between `Api\` (no Dto) and `DTOs\` (sibling of Api).
  - Plus 3 extensions to `tests/Unit/MkDirectorConfigDefaultsTest.php` for the new flags: `response.top_level_extra_data` (rc12), `cache.allow_full_clear` (rc13 lift-forward), `debug.explain_enabled` (rc13 lift-forward).

### Changed

- **`src/Controllers/BaseController.php::sendResponse()`** — added optional `array $extra = []` 4th parameter. The flag check `if ($extra !== [] && config('mk_director.response.top_level_extra_data', false))` is the only condition that emits the top-level `__extraData`. Default behavior (empty `$extra`, flag off) is byte-identical to rc11.

- **`src/Controllers/Controller.php::index()`** — added the same flag branch. When on, calls `sendResponse($data, '', 200, $extra)` directly. When off, keeps the legacy `sendResponse(['data' => $data, '__extraData' => $extra])` shape (BC default).

- **`src/Traits/CRUDSmart.php::index()`** — extracted `$items = $paginator->items()` once. When the flag is on, the new path auto-transforms `$items` (via `autoTransform`) before passing to `sendResponse(..., $extra)` — this is the only place the items go through the resource collection when the new shape is active. The plugin hook `fireAfterResponse` receives `$items` (transformed) in the new path, the wrapped array in the legacy path. Plugin contract change is documented in `Migration desde rc11`.

- **`src/Console/Commands/MkCheckCommand.php`** — added `--response-shape` option and `auditResponseShape()` method. The audit is a one-pass Symfony Finder scan of every controller PHP file; pattern is `sendResponse([` followed within 500 chars by `'__extraData'`. Reports per-file/per-line warnings (not errors) — mixed-mode operation is allowed during the transition window.

- **`src/Console/Commands/LintBoundariesCommand.php`** — split the `Api\Dto` pattern from the main `ALLOWED_PATTERNS` into a separate `DEPRECATED_PATTERNS` constant. Added `App\Modules\X\DTOs\` as the canonical (silent) pattern. The new `isDeprecatedExternal()` method and `reportDeprecations()` reporter emit per-import warnings for `Api\Dto` use. The `Api\Dto` allowance is a 1-release BC window — it will be removed in 1.7.0.

- **`config/mk_director.php`** — added 3 new top-level blocks: `response` (top-level extra_data flag), expanded `cache` (allow_full_clear flag), expanded `debug` (nested block with `explain_enabled` flag). The original flat `mk_director.debug` boolean is preserved as the inner `debug.enabled` for BC.

- **`packages/mk-web/src/hooks/useMkList.ts`** — auto-detects the response shape. Reads `__extraData` from the top-level `response.__extraData` first (canonical, matches `@makroz/core` `MkResponse<T>`), falls back to legacy nested `response.data?.__extraData`. Captures the value in local state so `pagination` is stable across re-renders. New `useMkList.test.ts` (4 tests) pinean both shapes plus the empty case and the defensive top-level-wins case.

- **`packages/mk-mobile/src/hooks/useMkList.ts`** — same auto-detect logic as web. New `useMkList.test.ts` (4 tests) mirror the web coverage.

- **`DEVELOPER_GUIDE.md`** — JSON example updated to the canonical top-level shape. New "Migration a `__extraData` top-level" note explains the flag and the BC window. § 5.1 (línea 1544) now mentions the top-level shape explicitly.

### Migration desde rc11

#### Frontend consumers (web + mobile)

The `@makroz/web` and `@makroz/mobile` `useMkList` hooks now auto-detect the response shape. **No code change is required on the consumer side** for the hooks themselves — they read top-level when present, fall back to nested otherwise. If you bypass `useMkList` and read the response body directly (e.g. with `api.get()` and manual handling), update your code to read `response.__extraData` (top-level) instead of `response.data?.__extraData` (nested) when the flag is on.

#### Backend consumers (the Laravel app)

If you have custom controllers that override `BaseController::sendResponse()` or that call `sendResponse([...])` directly with a wrapped array, audit them with `php artisan mk:status --response-shape` and migrate the affected ones to the new 4-arg form:

```php
// Before (rc11 and earlier)
return $this->sendResponse([
    'data' => $items,
    '__extraData' => $extra,
]);

// After (rc12+, when flag is on)
return $this->sendResponse($items, '', 200, $extra);
```

When the flag is off (rc12 default), the legacy form is still accepted and emitted as-is. The flag flips to `true` at GA. The audit command lists every controller that still uses the legacy form.

#### Plugin authors (MkMultiTenantPlugin, MkAuditLoggerPlugin, custom)

The `fireAfterResponse` plugin hook receives different shapes depending on the response envelope:
- Legacy path (flag off): `['data' => $items, '__extraData' => $extra]`
- New top-level path (flag on): the items directly (after `autoTransform`)

If your plugin needs access to `$extra` (e.g. for audit metadata), it should read `mk_director.response.top_level_extra_data` config to decide which path is active, or read from a future plugin API extension. CHANGELOG note: this is a contract change in rc12.

#### DTO namespace

The `mk:lint:boundaries` linter now accepts `App\Modules\X\DTOs\` as the canonical DTO location. The legacy `App\Modules\X\Api\Dto\` is still allowed (BC 1-release window) but emits a deprecation warning. To migrate: rename your namespace from `App\Modules\{Module}\Api\Dto` to `App\Modules\{Module}\DTOs` and update the import statements.

## [1.6.0-rc11] - 2026-06-27

### Fixed

- **BUG-NEW-32 (MEDIUM, HALLAZGO-NEW-05 cleanup)**: `MkBelongsToMany::from()` usaba `ReflectionProperty::setAccessible(true)` (3 lugares: líneas 130, 131, 247) que está deprecated desde PHP 8.1 y completamente sin efecto desde PHP 8.5 (emits warnings en cada `roles()` / `directAbilities()` access → contamina logs y tests). Solución: removidas las 3 llamadas (PHP 8.1+ properties son accesibles por default, paquete requiere PHP 8.4+). Defense-in-depth adicional: `MkMultiTenantPlugin::scopeAlreadyApplied()` ya no usa `ReflectionProperty::setAccessible(true)` sobre `protected static $usesTenant` — ahora consume el nuevo accessor público `HasTenantScope::isTenantEnabled()`. Defense-in-depth trade-off: 12 warnings de `setAccessible()` persisten en tests que acceden a métodos private del paquete (`LintBoundariesCommand`, `HasAbilities::invalidateAbilityCache`, `ListManager::applyJoins`) y al static `$globalScopes` de Laravel Model. Esos warnings son filterables con `php -d error_reporting=E_ALL~E_DEPRECATED vendor/bin/pest` y NO afectan runtime de consumers (son testing-only path). Cleanup completo vía `Closure::bind()` deferred a sprint de hardening futuro. Ver `tests/Unit/Database/Eloquent/Relations/MkBelongsToFromNoDeprecatedTest.php` (4 tests verde).

- **BUG-NEW-33 (HIGH, HALLAZGO-NEW-04 scaffolder auto-apply)**: el scaffolder `mk:make:auth-user X --with-crud` generaba un override de `roles()` / `directAbilities()` con `BelongsToMany` stock, bypaseando el fix BUG-NEW-31 (`MkBelongsToMany::from()`). Causa raíz: el scaffolder fue creado ANTES de BUG-NEW-31 (rc9). Drift scaffolder↔trait clásico. Solución: el scaffolder ahora emite directamente el patrón completo con `->using(MkRoleUserPivot::class)` + `MkBelongsToMany::from($relation)` + FK explícita `user_id` (BUG-NEW-06 BC). Esto resuelve BUG-NEW-06 (FK override) + BUG-NEW-31 (user_type auto) simultáneamente. El override scaffoldeado cubre TODAS las mutations nativas (`attach`, `detach`, `sync`, `syncWithoutDetaching`, `toggle`, `updateExistingPivot`) out-of-the-box — sin necesidad de fix manual del consumer. Lección reusable (HALLAZGO-NEW-04): cuando un fix tiene un patrón de aplicación bien definido, el scaffolder debería aplicarlo automáticamente — documentar el patrón en CHANGELOG es necesario pero no suficiente, la UX debería ser "out of the box". Ver `tests/Unit/Console/MakeAuthUserWithCrudMkBelongsToManyTest.php` (8 tests verde) + audit e2e sandbox-laravel confirma runtime `attach()` directo setea `user_type` correctamente.

### Added

- **2 accessors públicos nuevos** en `src/Tenancy/HasTenantScope.php`:
  - `public static function isTenantEnabled(): bool` — lee `$usesTenant` sin reflection. Solución de raíz al `setAccessible()` deprecated en `MkMultiTenantPlugin`.
  - `public static function setTenantEnabled(bool $enabled): void` — toggle del flag per-instance sin redeclarar la property (PHP forbids redeclaring trait static property). Útil para testing setup y runtime opt-in/out dinámicos.
  Ambos son BC-safe (agregar método público a trait existente es no-op para consumers).

- **3 nuevos archivos de test** (17 tests, 30 assertions):
  - `tests/Unit/Database/Eloquent/Relations/MkBelongsToFromNoDeprecatedTest.php` (4 tests): pinean que `MkBelongsToMany::from()` no emite deprecation warnings, no llama `->setAccessible()` en runtime, copia properties via reflection correctamente, y preserva late static binding.
  - `tests/Unit/Console/MakeAuthUserWithCrudMkBelongsToManyTest.php` (8 tests): pinean que el scaffolder emite `->using(MkRoleUserPivot::class)` + `->using(MkAbilityUserPivot::class)` + `MkBelongsToMany::from($relation)` en ambos relation overrides. Mantiene FK explícita `user_id` (BUG-NEW-06 BC). Mantiene `wherePivot('user_type', static::class)` (MME-polimórfico). Regression guard: pinea que NO esté el patrón viejo `return $this->belongsToMany(...)->wherePivot(...)` en ningún heredoc.
  - `tests/Unit/Tenancy/HasTenantScopeAccessorTest.php` (5 tests): pinean que `isTenantEnabled()` retorna default false, `setTenantEnabled(true/false)` togglea el flag, late static binding funciona, y el source contiene las firmas públicas nuevas.

### Changed

- `src/Database/Eloquent/Relations/MkBelongsToMany.php`: 3 `setAccessible(true)` removidos. Comportamiento idéntico, BC-clean para PHP 8.5+.
- `src/Plugins/Enterprise/MkMultiTenantPlugin.php::scopeAlreadyApplied()`: refactor de reflection (`ReflectionProperty` + `setAccessible` + `getValue`) a llamada directa al accessor público `HasTenantScope::isTenantEnabled()`. Legacy fallback preserva comportamiento pre-rc11 si el model predates el accessor.
- `src/Console/Commands/MakeAuthUserCommand.php`: bloques PHP heredoc de `{{rolesRelationOverride}}` y `{{directAbilitiesRelationOverride}}` actualizados para emitir el patrón completo (`->using(MkPivot::class)` + `MkBelongsToMany::from($relation)`).

### Migration desde v1.6.0-rc10

- **BC break válido del scaffolder output** (R-G-033-C): si tu consumer regenera un scope `mk:make:auth-user X --with-crud`, el nuevo `roles()` / `directAbilities()` override ahora usa `MkBelongsToMany::from($relation)` + `->using(MkRoleUserPivot::class)`. Si tu modelo concreto tenía un override manual con el patrón incompleto (pre-rc11), ahora podés borrarlo y regenerar — el scaffolder emite el patrón correcto out-of-the-box.
- **BC-safe runtime** para consumers existentes que NO regeneran: los 3 `setAccessible()` removidos en `MkBelongsToMany.php` son no-ops desde PHP 8.1+ (paquete requiere PHP 8.4+), así que no hay cambio funcional.
- **Sin acción** para el accessor público nuevo en `HasTenantScope` — es API additive. Si tu código custom usaba reflection sobre `$usesTenant`, podés migrar a `ModelClass::isTenantEnabled()` pero NO es obligatorio (la reflection legacy sigue funcionando, solo emite warning PHP 8.5).
- **Sin acción** para el cleanup de tests — los 12 deprecation warnings restantes son testing-only path, filterables.

## [1.6.0-rc10] - 2026-06-27

### Fixed

- **BUG-NEW-31 (CRITICAL, RC9 regression of HALLAZGO-NEW-01)**: el listener `creating` de `MkPivot::boot()` que debía setear `user_type` automáticamente en mutaciones nativas de Eloquent NO funcionaba runtime. Causa raíz: Laravel's `BelongsToMany::attachUsingCustomClass()` (vendor) instancia la pivot via `newPivot()` y llama `save()` SIN setear `pivotParent` antes. Resultado: el listener del `MkPivot::boot()` chequeaba `$pivot->pivotParent !== null` y era siempre false en runtime → `user_type` quedaba NULL → `NOT NULL constraint failed: role_user.user_type`. El feedback de RETO fase 7 lo confirmó: `$admin->roles()->attach([$roleId])` directo seguía fallando idéntico a rc8 (workaround: usar `syncRoles()`). **Fix de raíz**: nueva clase `\Mk\Director\Database\Eloquent\Relations\MkBelongsToMany` que extiende `BelongsToMany` y override `newPivot()` para inyectar `user_type = $this->parent->getMorphClass()` ANTES de instanciar la pivot + setea `pivotParent` después. Las traits `HasRoles::roles()` y `HasAbilities::directAbilities()` ahora retornan `MkBelongsToMany::from($relation)` (reflection-based state copy desde la `BelongsToMany` stock de Laravel — preserva query builder, related model, keys, sin re-implementar setup interno). Esto cubre TODAS las mutations nativas (`attach`, `detach`, `sync`, `syncWithoutDetaching`, `toggle`, `updateExistingPivot`) porque todas terminan llamando `newPivot()` o `attach()`. Defense-in-depth: `MkPivot::boot()` listener queda activo como segunda capa (cubre edge cases donde pivotParent SÍ está seteado). BC-safe: si la pivot NO tiene columna `user_type` (consumer legacy), la inyección es no-op via `Schema::hasColumn()` cacheado en memoria. Opt-out via override de `roles()` / `directAbilities()` sin retornar `MkBelongsToMany`. Ver `tests/Unit/Auth/BugNew31MkBelongsToManyTest.php` para pineo source-parsing (6 tests verde).

- **BUG-NEW-29 (HIGH)**: `mk:discover-abilities --force` fallaba con `relation "{scope}_abilities" does not exist` para consumers que usan `mk:make:auth-user X --with-crud`. Causa raíz: el scaffolder tiene dos rutas que generan schema distinto — `mk:module X --with-rbac` crea tabla `{scope}_abilities` per-scope, pero `mk:make:auth-user X --with-crud` usa tabla `abilities` global del paquete. El comando `DiscoverAbilitiesCommand::upsertAbilities()` SIEMPRE escribía en `{scope}_abilities`, fallando cuando esa tabla no existía. **Fix**: `upsertAbilities()` ahora es schema-aware — detecta cuál tabla existe (`{scope}_abilities` per-scope vs `abilities` global) y escribe ahí. Si NINGUNA existe, lanza `RuntimeException` con mensaje accionable ("Corriste `php artisan migrate` después de scaffoldear?"). Ver `tests/Unit/Console/BugNew29DiscoverAbilitiesSchemaAwareTest.php` para pineo (5 tests verde).

- **BUG-NEW-30 (MEDIUM)**: `mk:auth:create-super-admin` no invocaba el `AdminRolesSeeder` scaffoldeado, dejando `ability_role` con 0 rows. Causa raíz: el comando NO llamaba al seeder; el feedback de RETO fase 7 reportó que solo los grants directos (path `ability_user`) funcionaban, pero `ability_role` (abilities asignadas a ROLES) quedaba vacío. Workaround del consumer: correr manualmente `php artisan db:seed "App\\Modules\\Admin\\Database\\Seeders\\AdminRolesSeeder"`. **Fix**: el comando ahora invoca `App\Modules\Admin\Database\Seeders\AdminRolesSeeder` via `class_exists()` check + `app($seederClass)->run()`. Si el seeder no existe (consumer no scaffoldeó con `--with-crud`), warning explícito indicando que las abilities del role no fueron sembradas. Defense-in-depth: el seeder es idempotente (`firstOrCreate` + `sync` sin detach), múltiples invocaciones no duplican filas. Ver `tests/Unit/Console/BugNew30CreateSuperAdminSeederTest.php` para pineo (6 tests verde).

### Added

- **17 nuevos audit tests source-parsing** distribuidos en 3 archivos:
  - `tests/Unit/Auth/BugNew31MkBelongsToManyTest.php` (6 tests): pinean que `MkBelongsToMany::from()` existe y preserva state, que override `newPivot()` y `attach()` aplica merge de `user_type`, y que `HasRoles::roles()` + `HasAbilities::directAbilities()` retornan `MkBelongsToMany::from($relation)`.
  - `tests/Unit/Console/BugNew29DiscoverAbilitiesSchemaAwareTest.php` (5 tests): pinean que `resolveAbilitiesTable()` prefiere per-scope si existe, fallback a global, y lanza `RuntimeException` con mensaje accionable si ninguna existe. `tableExists()` usa `DB::connection()->getSchemaBuilder()` directamente (más robusto que `Schema` facade que requiere `db.schema` bindeado).
  - `tests/Unit/Console/BugNew30CreateSuperAdminSeederTest.php` (6 tests): pinean que `seedAdminRolesIfAvailable()` existe, usa namespace DDD `App\\Modules\\Admin\\Database\\Seeders\\AdminRolesSeeder`, emite warning explícito si no existe, invoca via container `app($seederClass)->run()`, y es llamado desde `handle()` después de la asignación de roles+abilities.

### Migration desde v1.6.0-rc9

- **Sin acción** para los 3 fixes (todos son BC-safe additive o BC-safe refactors internos).
- Consumers que usan `mk:discover-abilities --force` con `--with-crud`: el comando ahora detecta automáticamente la tabla correcta. Si antes dependían del workaround de correr `db:seed AdminRolesSeeder` manual para popular abilities, ya no es necesario.
- Consumers que usan `mk:auth:create-super-admin --roles=super-admin,admin,editor,viewer`: ahora el comando también invoca el `AdminRolesSeeder` DDD, así que `ability_role` queda con las 11 filas esperadas sin necesidad del comando manual `db:seed`.
- Consumers con pivots MME-polimórficas (`role_user` / `ability_user` con columna `user_type`): las mutations nativas (`attach`, `sync`, `syncWithoutDetaching`, `toggle`, `updateExistingPivot`) ahora setean `user_type = $user->getMorphClass()` runtime via la nueva relation custom `MkBelongsToMany` (vía reflection-based state copy). Antes, las mutations directas seguían fallando con `NOT NULL constraint failed: role_user.user_type` (rc9 fix era solo source-parsing, runtime fallaba idéntico a rc8). Si tu consumer tiene una override de `roles()` / `directAbilities()` en el modelo concreto (e.g. RETO por BUG-NEW-06 FK override), agregar `->using(MkRoleUserPivot::class)` y retornar `MkBelongsToMany::from($relation)` para que el fix aplique.
- **HALLAZGO-NEW-03 (anti-pattern)**: el feedback de RETO fase 7 identificó que pinear fixes con source-parsing tests es un anti-pattern — pasa pero no prueba el comportamiento real. Aplica a HALLAZGO-NEW-01 en rc9. R-PKG-021 pinea la fix con source-parsing (porque el paquete es minimalista sin DB activa), pero el audit pre-tag valida el comportamiento end-to-end contra sandbox-laravel con DB MySQL/SQLite.

## [1.6.0-rc9] - 2026-06-27

### Fixed

- **OBS-NEW-02 (HIGH)**: `mk:discover-abilities` retornaba `"count": 0` cuando los controllers scaffoldeados NO estaban loaded (caso típico en contexto artisan CLI). Causa: `discoverClassesInDir()` solo iteraba `get_declared_classes()`, que retorna únicamente clases ya cargadas por el autoloader. Como las controllers scaffoldeadas (`AdminController`, `RoleController`, `AbilityController`) NO se cargan hasta que `route:list` o el bootstrap del framework las referencia, el command reportaba `"Classes: 1"` (solo el `AdminServiceProvider`) en vez de 4 (provider + 3 controllers). Resultado: el path `discoverAbilitiesFromMkConfig()` (pinedo en v1.6.0-rc5) funcionaba en unit tests pero NO en runtime de consumers reales — `mk:discover-abilities --force` escribía 0 filas en `{scope}_abilities`, dejando a los consumers dependiendo de seeders manuales. **Fix**: `discoverClassesInDir()` ahora hace `require_once $realPath` ANTES de iterar `get_declared_classes()`, forzando la declaración de la clase sin depender del autoload trigger. Después del require, el matching por suffix contra `get_declared_classes()` funciona correctamente. Side-effects documentados: el `require_once` es seguro en proyectos Laravel siguiendo convención PSR-4 (cada archivo = una clase, sin código top-level). Si un consumer tiene archivos con código top-level (helpers, side-effects), esos side-effects ocurrirán — trade-off explícito vs parsear namespace via regex (que requiere conocer el root namespace).

- **BUG-NEW-28 (MEDIUM, drift)**: el stub `admin-factory.stub` generado por `mk:make:auth-user {Scope} --with-crud` emitía `'email_verified_at' => now()` SIEMPRE. Si el scaffolder se llamaba SIN `--verify-email`, la tabla `{scope}s` NO tiene columna `email_verified_at`, y cualquier test que use `{Scope}::factory()->create()` fallaba con `SQLSTATE[HY000]: General error: 1 table admins has no column named email_verified_at`. Workaround aplicado en RETO fase 6: usar `{Scope}::create([...])` directo en tests (no factory), pero el factory pattern quedaba inutilizable. **Fix**: el stub ahora envuelve `email_verified_at` en `if (Schema::hasColumn((new {Scope}())->getTable(), 'email_verified_at'))`. Si la columna existe (consumer con `--verify-email`), la factory funciona idéntico a antes (BC-safe). Si NO existe (consumer sin `--verify-email`), el array `definition()` no incluye la key y la factory funciona sin error. Implementado con check `Schema::hasColumn` runtime en vez de dos stubs distintos — más robusto y BC-clean.

- **HALLAZGO-NEW-01 (HIGH, solución de raíz)**: `attach()`/`detach()`/`sync()`/`syncWithoutDetaching()` directos en las relations `roles()` y `directAbilities()` NO seteaban `user_type` automáticamente en consumers MME-polimórficos (FK polimórfica con columna `user_type` en la pivot). Antes, solo los métodos helper (`assignRole`, `syncRoles`, `giveAbilityTo`, `syncDirectAbilities`) lo hacían via `pivotExtras()` / `abilityPivotExtras()`. Un consumer que hiciera `$admin->roles()->attach([$id])` directo fallaba con `NOT NULL constraint failed: role_user.user_type`. Workaround aplicado en RETO fase 6: usar `syncRoles()` (que sí funciona) — pero el path `attach()` directo quedaba inutilizable para casos donde se necesita insert sin detach (ej: append). **Fix raíz**: nuevas clases `Mk\Director\Auth\Pivots\MkRoleUserPivot` y `MkAbilityUserPivot` (extienden `MkPivot` base abstracta) aplicadas via `->using(...)` en las relations. `MkPivot::boot()` registra un listener `creating` que setea `user_type = $pivot->pivotParent->getMorphClass()` (FQCN del modelo concreto, ej: `App\Modules\Admin\Models\Admin`) automáticamente cuando la pivot tiene la columna. Defense-in-depth: el listener respeta `user_type` explícito del caller (no pisa intención), es no-op si la pivot NO tiene la columna (chequeo via `Schema::hasColumn()` cacheado en memoria del proceso, evita query repetido por attach/detach), y se puede opt-out via override de `roles()`/`directAbilities()` sin `->using(...)` (estándar Laravel).

- **Tests pre-existing backlog RC4 (4 tests)**: `tests/Unit/Process/UpgradeDocumentationTest.php` tenía 4 tests fallando porque `docs/UPGRADE_1.2.md` no existía. **Fix**: archivo `docs/UPGRADE_1.2.md` creado documentando los 4 breaking changes históricos del salto 1.1.x → 1.2.x (UUID primary key, opt-in multi-tenancy, MkAbility refactor, ListManager unknown operator whitelist) con sección de Rollback explícita y aviso de irreversibilidad del UUID migration. Suite completa del paquete: **518 passing, 0 failing** (de 505+4 fail).

### Changed

- **BC-safe: `classesNamespacePrefix()` ahora es un método protected overridable**. Antes el matching de clases discovered usaba `str_starts_with($declared, 'App\\Modules')` hardcoded, lo cual rechazaba clases en otros namespaces (e.g. tests con `TestNs\\...`, packages vendorizados que usan el command con sus propios namespaces). Ahora el prefijo default sigue siendo `App\\Modules` (regla R-MK-001) pero los tests pueden override el método para retornar `null` (skip prefix check) u otro prefijo custom. Ver `tests/Feature/DiscoverAbilitiesCommandTest.php::makeTestCommand()` para el patrón. No afecta consumers reales (mantienen el default `App\\Modules`).

### Added

- **2 nuevos audit tests pineando OBS-NEW-02** en `tests/Feature/DiscoverAbilitiesCommandTest.php`:
  - Source-parsing: `discoverClassesInDir` contiene `require_once $realPath` antes de iterar `get_declared_classes()`.
  - E2E real-disk: archivos PHP creados en disco (no eval) en tempdir + módulos con estructura Controllers/Models → discoverModules() los encuentra correctamente via require_once + matching por suffix. Cubre el caso RETO (controllers scaffoldeadas no loaded).
- **4 nuevos audit tests pineando BUG-NEW-28** en `tests/Feature/AdminUserFactoryStubTest.php` (archivo nuevo):
  - Source-parsing: el stub importa `Schema`, usa `Schema::hasColumn` con argumento `'email_verified_at'`, y el check envuelve el `email_verified_at => now()`.
  - Anti-regresión: el stub NO contiene `'email_verified_at' => now()` hardcoded fuera del if.
  - Render + syntax: el stub renderizado con `{{ModuleName}}` → `Admin` compila como PHP válido (`php -l` passes).
  - Render content: el stub renderizado con `{{ModuleName}}` → `Member` tiene `Schema::hasColumn((new Member())->getTable()` (placeholder correctamente reemplazado).
- **9 nuevos audit tests pineando HALLAZGO-NEW-01** en `tests/Unit/Auth/HallazgoNew01PivotTest.php` (archivo nuevo):
  - Source-parsing: `MkRoleUserPivot` y `MkAbilityUserPivot` existen y extienden `MkPivot` base abstracta.
  - Source-parsing: ambas pivot classes declaran `protected $table = 'role_user'` / `'ability_user'`.
  - Source-parsing: `HasRoles::roles()` y `HasAbilities::directAbilities()` usan `->using(MkRoleUserPivot::class)` / `MkAbilityUserPivot::class`.
  - Source-parsing: `MkPivot::boot()` registra listener `creating` que setea `user_type` desde `pivotParent->getMorphClass()` cuando la pivot lo requiere.
  - Anti-regresión: el listener respeta `user_type` explícito del consumer (no pisa).
  - Anti-regresión: el listener es no-op si la pivot NO tiene columna `user_type` (chequeo via `Schema::hasColumn()` cacheado en memoria del proceso para evitar query repetido por attach/detach).
- **1 método overridable nuevo**: `DiscoverAbilitiesCommand::classesNamespacePrefix(): ?string` retorna `App\\Modules` por default. Tests pueden override. Documentado en docblock del método.
- **3 nuevas clases concretas**: `Mk\Director\Auth\Pivots\MkPivot` (base abstracta), `MkRoleUserPivot` (tabla `role_user`), `MkAbilityUserPivot` (tabla `ability_user`). Patrón: cualquier consumer puede declarar su propio Pivot class con `extends MkPivot` y `->using()` para opt-in a la auto-inyección de `user_type` en pivots custom (no solo `role_user` / `ability_user`).
- **1 doc nuevo**: `docs/UPGRADE_1.2.md` documenta los 4 breaking changes históricos del salto 1.1.x → 1.2.x con sección de Rollback explícita y aviso de irreversibilidad del UUID migration. Companion script `bin/migrate-1.1-to-1.2.php` (ya existía) lo referencia en la sección "Migration script".

### Migration desde v1.6.0-rc8

- **Sin acción** para todos los fixes (todos son BC-safe additive o BC-safe refactors internos).
- Consumers que usan `mk:discover-abilities` con `mk:make:auth-user --with-crud`: ahora el command retorna abilities de los controllers scaffoldeados (no solo del ServiceProvider). Si antes dependían de seeders manuales para popular `{scope}_abilities`, ahora `mk:discover-abilities --force` puebla la tabla automáticamente con las 5 abilities CRUD estándar por scope (`{scope}.{model}.viewAny`, `view`, `create`, `update`, `delete`).
- Consumers con `--with-crud` que usan `{Scope}::factory()->create()` en tests: el factory stub ahora funciona tanto si la tabla tiene `email_verified_at` (con `--verify-email`) como si NO (sin `--verify-email`). Ya no necesitan usar `Admin::create([...])` como workaround.
- Consumers con pivots MME-polimórficas (`role_user` / `ability_user` con columna `user_type`): `$user->roles()->attach([$id])`, `detach()`, `sync()`, `syncWithoutDetaching()`, `toggle()`, `updateExistingPivot()` ahora setean `user_type = $user->getMorphClass()` automáticamente vía las nuevas clases `MkRoleUserPivot` / `MkAbilityUserPivot`. Antes, esos métodos directos NO seteaban `user_type` (solo los helpers `assignRole` / `syncRoles` / `giveAbilityTo` / `syncDirectAbilities` lo hacían via `pivotExtras()`). Workaround aplicado en RETO fase 6 (usar `syncRoles()` en vez de `attach()` directo) ya NO es necesario. Opt-out disponible (defense-in-depth): override la relation `roles()` / `directAbilities()` en el modelo del consumer sin `->using(MkRoleUserPivot::class)` / `MkAbilityUserPivot::class`.
- Upgrade histórico desde 1.1.x → 1.2.x (si todavía no se hizo): ver `docs/UPGRADE_1.2.md` con los 4 breaking changes + rollback procedure.

## [1.6.0-rc8] - 2026-06-27

### Fixed

- **BUG-NEW-26 (CRITICAL, causa raíz de BUG-NEW-23)**: `TokenIssuer::rotateRefreshToken()` usaba `Hash::check($plaintext, $tokenModel->token)` para comparar el plaintext del refresh token contra el hash guardado en `personal_access_tokens.token`. **PERO Sanctum v4.3.2 hashea con SHA256 (no bcrypt)** — verificado en `vendor/laravel/sanctum/src/HasApiTokens.php:66` y `PersonalAccessToken.php:61,67`. El hash guardado tiene 64 chars (SHA256), no 60 (bcrypt). Resultado: `Hash::check()` SIEMPRE lanzaba `RuntimeException: This password does not use the Bcrypt algorithm`. El catch de BUG-NEW-23 mitigaba el 500 → 401, pero el refresh NUNCA funcionaba (incluso con token recién emitido y válido vía `/login`). **Fix raíz**: cambiar a `hash_equals($tokenModel->token, hash('sha256', $plaintext))` — timing-safe y consistente con la implementación interna de Sanctum v4. El try/catch de BUG-NEW-23 se mantiene como **defense-in-depth** por si Sanctum rota de algoritmo en el futuro (bcrypt→argon2→sha512). Documentación del método actualizada para reflejar SHA256 (antes incorrectamente decía "bcrypt").

- **BUG-NEW-27 (MEDIUM, UX)**: el `AuthController::refresh()` scaffoldeado por `mk:make:auth-user` solo capturaba `\Illuminate\Auth\Access\AuthorizationException`. Como `InvalidRefreshTokenException` extiende `AuthorizationException`, el catch SÍ lo capturaba — pero con un mensaje genérico ("Refresh token inválido.") en vez del mensaje detallado (e.g. "Refresh token expired.", "Refresh token hash mismatch.", "Refresh token scope mismatch: expected `admin`, got `member`.", "Refresh token not found."). **Fix**: el stub `auth-user.auth-controller.stub` ahora tiene un catch específico para `InvalidRefreshTokenException` ANTES del catch genérico. El catch específico expone el mensaje detallado vía `sendError($e->getMessage(), [], 401)` para mejor DX (front-end puede mostrar mensaje preciso) y testabilidad (tests e2e pueden pinear el path específico del error). BC-safe: el catch genérico queda como defense-in-depth.

### Added

- **9 nuevos audit tests de regresión** distribuidos en 3 archivos:
  - `tests/Unit/TokenIssuerTest.php`: 3 source-parsing tests pineando el fix de BUG-NEW-26 (uso de `hash_equals` + SHA256, NO `Hash::check`; docblock correcto).
  - `tests/Unit/Console/MakeAuthUserCommandTest.php`: 3 source-parsing tests pineando el fix de BUG-NEW-27 (import de `InvalidRefreshTokenException`, orden de catches, status 401).
  - `tests/Feature/DiscoverAbilitiesCommandTest.php`: 4 tests pineando OBS-NEW-01 (path `discoverAbilitiesFromMkConfig` funciona correctamente, genera 5 abilities CRUD desde un SmartController stub con `$mkConfig['model']`, ignora controllers que NO extienden `SmartController`).
- **2 tests actualizados** en `tests/Feature/Fase4FeedbackAuditTest.php`: los tests pineados de BUG-NEW-23 ahora reflejan la nueva realidad tras descubrir que BUG-NEW-26 es la causa raíz. Pinan `hash_equals` + SHA256 + try/catch defense-in-depth.
- **Documentación del patrón "Sanctum v4 + UUIDs + SHA256"** en `DEVELOPER_GUIDE.md` § 3.13.7 (MEJORA-NEW-02). Para que otros consumers no caigan en el mismo bug. Cubre: cómo funciona Sanctum v4 internamente, anti-patterns a evitar (`Hash::check`, `hash(sha256, $fullToken)`, `md5`), patrón correcto, verificación post-fix.

### Notes

- **OBS-NEW-01**: `mk:discover-abilities` ya tenía implementado el path de `discoverAbilitiesFromMkConfig()` desde R-PKG-015 (v1.6.0-rc5), pero no había tests pineados específicos para este path. RETO fase 5 reportó "No se descubrieron abilities" pero la causa real fue que el `ModuleServiceProvider` del módulo admin implementaba `discoverAbilities()` con un subset distinto de abilities (regla Q1 hybrid: provider es source-of-truth primario). Si RETO quiere que `mk:discover-abilities` use el path `$mkConfig` INCLUSO cuando el provider retorna abilities, es un cambio de regla Q1 (requeriría decisión de Mario).
- **MEJORA-NEW-01** (`--with-rate-limit` flag): no implementado en este sprint para mantener scope acotado. Sigue el patrón de `--with-auth-rbac` (R-PKG-010).

### Migration desde v1.6.0-rc7

- **Sin acción** para todos los fixes (todos son BC-safe additive o BC-safe refactors internos).
- Consumers con Sanctum v4.3.x: el refresh token ahora funciona correctamente sin workarounds. Si tu consumer tenía un try/catch manual alrededor de `Hash::check`, puede removerlo (el `TokenIssuer::rotateRefreshToken` del paquete ya hace la comparación SHA256 internamente).
- Consumers con AuthController scaffoldeado: regenerar el módulo para obtener el nuevo catch específico de `InvalidRefreshTokenException`. Si tu consumer custom AuthController tiene su propia jerarquía de catches, no requiere acción.

## [1.6.0-rc7] - 2026-06-26

### Fixed

- **BUG-NEW-21 (CRITICAL, REGRESIÓN de BUG-NEW-13 fix)**: `mk:make:auth-user --with-crud` rompía el bootstrap cuando el `routes/api.php` original tenía `declare(strict_types=1);` (que es lo que `auth-user.routes.stub` SIEMPRE emite). El fix de BUG-NEW-13 inyectaba los `use` statements del CRUD stub DESPUÉS del `<?php` opener y ANTES del `declare`, dejando: `<?php\nuse ...\ndeclare(strict_types=1);` — PHP rechaza esto con `Fatal error: strict_types declaration must be the very first statement in the script`. Resultado: `php artisan route:list` crasheaba con `ReflectionException` / FatalError al cargar el módulo. **Fix**: `extendRoutesWithCrud()` ahora detecta vía regex si el archivo tiene un bloque `declare(...)` inmediatamente después del `<?php` opener (con whitespace flexible entre medio). Si lo hay, inserta los `use` statements NUEVOS DESPUÉS del bloque `declare`, no antes. Si NO hay `declare`, mantiene el comportamiento previo (insertar después de `<?php\n`). BC-safe: solo cambia el orden cuando el `declare` está presente, que es lo que el scaffolder actual SIEMPRE emite.

- **BUG-NEW-22 (CRITICAL, derivado de BUG-NEW-16 fix)**: el Repository scaffoldeado por `--with-crud` (`AdminRepository::syncRoles()` / `syncDirectAbilities()`) NO seteaba `user_type` en la pivot MME-polimórfica. Resultado: `POST /api/admins/{uuid}/roles` fallaba con `SQLSTATE[23502]: null value in column "user_type" of relation "role_user" violates not-null constraint`. La fix de BUG-NEW-16 solo había cubierto el command `mk:auth:create-super-admin --roles=`, no el endpoint CRUD. **Fix**: 3 cambios: (a) `HasRoles::pivotExtras()` y `HasAbilities::abilityPivotExtras()` ahora son `public` (BC-safe: solo agrega visibilidad) — el Repository scaffoldeado puede consumirlos directamente; (b) `admin-repository.stub` ahora invoca `$admin->pivotExtras()` / `$admin->abilityPivotExtras()` en el payload del `sync()`, eliminando el hardcodeo manual del FQCN; (c) si la pivot NO tiene `user_type` (consumer legacy), `pivotExtras()` retorna `[]` y el comportamiento es idéntico al previo.

- **BUG-NEW-23 (CRITICAL)**: `TokenIssuer::rotateRefreshToken()` podía retornar HTTP 500 en vez de HTTP 401 cuando el refresh token recibido no era bcrypt válido. Causa: `Hash::check($plaintext, $tokenModel->token)` lanza `RuntimeException: This password does not use the Bcrypt algorithm` cuando `$tokenModel->token` no es un hash bcrypt (caso edge: tokens legacy con sha256, datos corruptos, scope mismatching). El `AuthController::refresh()` solo captura `InvalidRefreshTokenException`, NO `RuntimeException`, así que se filtraba como 500. **Fix**: envolver el `Hash::check()` en try/catch; mapear `RuntimeException` a `InvalidRefreshTokenException::hashMismatch()` (que SÍ retorna HTTP 401). El path normal (hash bcrypt válido, hash no matchea) sigue funcionando idéntico.

- **BUG-NEW-24 (medium, drift)**: `mk:make:auth-user X --with-crud` generaba el Model concreto (ej: `Admin`) con `protected static function newFactory(): XFactory` PERO sin el `use App\Modules\X\Database\Factories\XFactory;` necesario para resolver el FQCN desde el namespace `App\Modules\X\Models`. Resultado: cualquier test que use `Admin::factory()->create()` fallaba con `Class "App\Modules\Admin\Database\Factories\AdminFactory" not found`. **Fix**: el placeholder `{{factoryHasFactoryUse}}` ahora emite 2 imports cuando `$withCrud` está activo: (a) `use Illuminate\Database\Eloquent\Factories\HasFactory;` (existente) + (b) `use App\Modules\{$scope}\Database\Factories\{$scope}Factory;` (NUEVO). Tests del factory funcionan out-of-the-box sin workarounds.

- **BUG-NEW-25 (cosmetic, 4to ciclo de BUG-NEW-14)**: `buildProfileFieldsReplacements()` emitía docblock de profile fields con drift de indentación cuando el consumer tenía 5+ profile fields. Causa raíz: la fix anterior terminaba el docblock con `\n\n` (doble newline) para separar del próximo bloque vía `*/`. PERO el control del blank line entre docblocks vivía en el GENERADOR (no en el stub), y con muchos fields el doble newline acumulaba drift visual. **Fix robusta**: el docblock generado cierra con `\n` simple (`     */\n`); el control del blank line entre docblocks vive en el STUB (`{{profileFieldsDocblock}}\n\n    /**`). Esto elimina el drift y mantiene el control de espaciado en UN lugar (single source of truth).

### Changed

- **BC-safe: `HasRoles::pivotExtras()` y `HasAbilities::abilityPivotExtras()` ahora son `public`** (antes `protected`). Esto permite que el Repository scaffoldeado (y cualquier consumer que quiera inspeccionar el payload de una pivot polimórfica) consuma el helper directamente sin reflection ni hardcodeo del FQCN. No rompe ningún caller existente (solo se agrega visibilidad). Ver BUG-NEW-22 fix arriba para el contexto completo.

### Added

- **5 nuevos audit tests de regresión** (`tests/Feature/Fase4FeedbackAuditTest.php`) pineando los 5 bugs de fase 4 (BUG-NEW-21..25). Patrón: source-parsing con helpers `extractMethodBody()` + `extractArrayBody()` (recuento de llaves balanceadas, NO regex frágil). Total acumulado: 22 audit tests verde (33 nuevos assertions en Fase4 + 117 del AuthUserFeedbackAuditTest previo). Total paquete: 489 tests passing, 4 pre-existing failures (UPGRADE_1.2.md backlog RC4, sin regresión).
- **1 pineo actualizado**: `BUG-NEW-14` test en `AuthUserFeedbackAuditTest.php` se actualizó para reflejar la nueva realidad de BUG-NEW-25 (newline simple en el docblock, blank line en el stub). El pineo previo pineaba el patrón viejo con doble newline; ahora pinea el patrón correcto.

### Migration desde v1.6.0-rc6

- **Sin acción** para todos los fixes (todos son BC-clean additive o BC-clean refactors internos).
- Consumers con `--with-crud` que regeneren el módulo Admin: el `routes/api.php` final respetará la regla PHP de `declare(strict_types=1)` como primera instrucción. Workaround previo (mover `declare` manualmente) ya NO es necesario.
- Consumers con pivot MME-polimórfica (`role_user` / `ability_user` con columna `user_type`) que usan `--with-crud`: el Repository scaffoldeado ahora setea `user_type = static::class` automáticamente via `pivotExtras()`. Workaround previo (hardcodear `['user_type' => Admin::class]` en `sync()`) ya NO es necesario y debería removerse para mantener consistencia.
- Consumers que llamaban `HasRoles::pivotExtras()` o `HasAbilities::abilityPivotExtras()` via reflection: ahora pueden llamarlos directamente (visibilidad pública). No requiere acción — solo habilita un patrón más limpio.

## [1.6.0-rc6] - 2026-06-26

### Fixed

- **BUG-NEW-13 (CRITICAL)**: `mk:make:auth-user --with-crud` generaba `routes/api.php` con DOS bloques PHP (uno al inicio del archivo, otro insertado por `extendRoutesWithCrud()` antes del cierre del grupo). El segundo bloque rompía `loadRoutesFrom` con `ReflectionException: Class "AdminController" does not exist`. **Fix**: `extendRoutesWithCrud()` ahora extrae los `use` statements del `with-crud.stub` via regex (`preg_match_all('/^use\s+[^;]+;\s*$/m')`) y los inyecta al inicio del `routes/api.php` (después del primer `<?php` opener, con dedup via `str_contains`), e inserta solo el cuerpo de las rutas (sin `<?php`, sin `use`) antes del cierre del último grupo. Resultado: UN solo bloque PHP con imports consolidados al inicio (PSR-12).
- **BUG-NEW-14 (cosmetic, 3er ciclo)**: `buildProfileFieldsReplacements()` emitía docblock `*/` PEGADO al `/**` del siguiente bloque en el modelo (porque `auth-user.model.stub` tiene `{{profileFieldsDocblock}}/**` sin newline). Resultado: PHPStan/IDEs confundidos. **Fix**: el docblock ahora termina con `\n\n` (doble newline) en vez de `\n` para garantizar una línea en blanco entre el docblock de profile fields y el siguiente docblock del modelo.
- **BUG-NEW-15 (UX)**: `mk:auth:create-super-admin` lanzaba `NOT NULL violation on column "name"` cuando se ejecutaba en modo `--no-interaction` (CI / seed scripts) sin `--name=`. El `$this->ask('Nombre')` retorna `null` en modo no-interactive, y `create(['name' => null, ...])` rompía. **Fix**: fallback chain ahora es (1) `--name=` flag, (2) prompt interactivo, (3) autogenerar del email local-part con `ucfirst(strtolower(explode('@', $email)[0]))`, (4) default `'Admin'`.
- **BUG-NEW-16 (CRITICAL)**: `HasRoles::assignRole()` y `HasAbilities::giveAbilityTo()` usaban `syncWithoutDetaching([$id])` sin setear `user_type` en el pivot. En consumers MME con FK polimórfica (`role_user` con columna `user_type`, `ability_user` con columna `user_type`), el INSERT quedaba con `user_type = NULL` → `NOT NULL violation`. **Fix**: helper `pivotExtras()` / `abilityPivotExtras()` detecta via `Schema::hasColumn('role_user', 'user_type')` (cacheado en memoria del proceso); si la pivot tiene la columna, agrega `['user_type' => static::class]` al payload del sync. BC-safe: si la pivot NO tiene `user_type`, el comportamiento es idéntico al previo (sin extras).
- **BUG-NEW-17 (CRITICAL)**: `HasAbilities::abilities()` retornaba `[]` para usuarios con abilities vía roles pero SIN direct abilities. Causa: el método hacía `belongsToMany(static::class, 'ability_user', ...)` (JOIN directo a la pivot `ability_user`) + filtro `whereExists` que no aplicaba a filas inexistentes. Para users sin direct abilities, el JOIN retornaba 0 rows y `whereExists` quedaba sin rows donde aplicar. **Fix**: refactor a `whereIn('abilities.id', $unionSubquery)` con subqueries `UNION ALL` (path 1: `ability_user.user_id = ?`, path 2: `ability_role JOIN role_user`). Portable cross-engine, lazy (no resuelve los IDs eagerly). El cambio de `whereExists` a `whereIn` también resuelve el BUG-NEW-08 (Postgres SQLSTATE 42P01) porque ya no se referencia `abilities` sin join explícito.
- **BUG-NEW-18**: `AbilityController` (generado por `--with-crud`) declaraba `'with' => ['roles']` y `'allowedIncludes' => ['roles']` en `$mkConfig`, pero el modelo `Mk\Director\Auth\Models\Ability` NO tiene relation `roles()` (solo Role tiene `abilities()`). Resultado: `GET /api/abilities` crasheaba con `Call to undefined relationship [roles] on model [Mk\Director\Auth\Models\Ability]`. **Fix**: `ability-controller.stub` ahora usa `'with' => []` y `'allowedIncludes' => []`. Si un consumer necesita eager loading custom, override `$mkConfig` en la subclase.
- **BUG-NEW-19 (HIGH)**: Las rutas con parámetro dinámico del scope se emitían con espacios alrededor del placeholder: `Route::get('/{ admin }', ...)` (después del str_replace `{{moduleNameLower}}` → `admin`). Laravel interpretaba el parámetro como ` admin` (con espacio) y no matcheaba URLs sin espacio. Resultado: `GET /api/admins/{uuid}` retornaba "route could not be found" para CUALQUIER ID. Curiosamente `{role}` y `{ability}` NO tenían espacio (ya correctos). **Fix**: `auth-user.routes.with-crud.stub` ahora emite rutas con `'{ {{moduleNameLower}}}'` SIN espacios alrededor del placeholder (`'{admin}'` post-resolve). Aplica a las 6 rutas del scope: show, update×2 (PUT/PATCH), destroy, assignRoles, assignDirectAbilities.
- **BUG-NEW-20 (CRITICAL for UUID consumers)**: `CRUDSmart::show/update/destroy()` declaraban `int $id` en la signature. Consumers que usan `HasUuids` (RETO) generan IDs string tipo `01HXYZ...`. Resultado: `TypeError: SmartController::show(): Argument #2 ($id) must be of type int, string given` al primer `GET /api/{scope}/{uuid}`. **Fix**: signatures cambiadas a `string|int $id` (PHP 8.0+ union type). El casteo se hace internamente vía `findOrFail()` que acepta ambos tipos. BC: cualquier código existente que pase `int` sigue funcionando.
- **BUG-NEW-10 drift fix**: `mk:make:auth-user` seguía reportando "BUG-NEW-10: laravel/sanctum no está instalado" DESPUÉS de que el consumer corriera `composer require laravel/sanctum`. Causa: `class_exists()` falla si el autoloader de Composer todavía no regeneró el classmap (común cuando el scaffolder corre en la misma sesión que `composer require` sin `composer dump-autoload` intermedio). **Fix**: extraer la lógica a un helper `isSanctumInstalled()` con fallback `file_exists(base_path('vendor/laravel/sanctum/composer.json'))`. Si la carpeta existe, Sanctum SÍ está instalado aunque el autoloader no haya picked up aún.

### Added

- **8 audit tests de regresión** (R-PKG-016). `tests/Feature/AuthUserFeedbackAuditTest.php` ahora pinea los 8 bugs nuevos de fase 3 (BUG-NEW-13..20) + BUG-NEW-10 drift fix. Cada bug tiene un test que falla si el bug vuelve. Patrón: source-parsing + reflection-based isolation (mk-director-implementation.md § "Audit-driven pre-tag discovery"). El test de BUG-NEW-08 de fase 2 se actualizó para pinear el nuevo approach (`whereIn` con subqueries UNION en vez de `whereExists` con join explícito).

### Migration desde v1.6.0-rc5

- **Sin acción** para todos los fixes (todos son BC-clean additive o BC-clean refactors internos).
- Consumers con `--with-crud` deben regenerar el módulo Admin (o correr `migrate:fresh`) para que el `routes/api.php` consolidado quede con un solo bloque PHP.
- Consumers con `HasUuids` que usan `--with-crud`: el `routes/api.php` final tendrá los `use` statements consolidados al inicio del archivo, sin DOS bloques PHP.
- Consumers con pivot `role_user` / `ability_user` que tiene columna `user_type`: las mutations de roles/abilities ahora setean `user_type = static::class` automáticamente.

## [1.6.0-rc5] - 2026-06-26

### Added

- **`php artisan mk:fix:sanctum-uuids` command** (R-PKG-015 BUG-NEW-09). Helper que parchea automáticamente la migration `create_personal_access_tokens_table` cambiando `$table->morphs('tokenable')` por `$table->uuidMorphs('tokenable')`. Necesario cuando el consumer usa `HasUuids` en sus modelos `AuthUser`. Idempotente (no-op si ya está parcheada). Soporta `--dry-run`. Detecta la migration buscando `*_create_personal_access_tokens_table.php` en `database/migrations/`.
- **`mk:discover-abilities` lee `$mkConfig` de `SmartController`** (R-PKG-015 OBS-NEW-01). Ahora, además de atributos `#[Ability]` y docblocks `@mk-ability`, el command inspecciona el `protected array $mkConfig = [...]` de los controllers que extienden `SmartController` y genera abilities CRUD estándar del estilo `{scope}.{model}.{verb}` (e.g. `admin.admins.viewAny`). Sin esto, los controllers generados por `mk:make:auth-user --with-crud` no tenían abilities detectables automáticamente.
- **Override de `roles()` y `directAbilities()` con FKs explícitas** (R-PKG-015 BUG-NEW-06). Cuando el scaffolder se ejecuta con `--with-crud`, el modelo generado incluye overrides de `roles()` y `directAbilities()` con FK explícita `user_id` (no inferida del nombre del modelo) y `wherePivot('user_type', static::class)` para mantener el polimorfismo MME (R-MK-001). Sin esto, `syncRoles()`, `assignRoles()`, `syncDirectAbilities()` explotaban con `no such column: role_user.admin_id` en cualquier consumer MME (tablas por scope).
- **Sanctum installation check en output de scaffolder** (R-PKG-015 BUG-NEW-10). `mk:make:auth-user` ahora detecta si `laravel/sanctum` está instalado y avisa al consumer con `composer require laravel/sanctum:^4.3` + `php artisan install:api` + (si usa UUID) `php artisan mk:fix:sanctum-uuids`. Sin esto, el módulo crasheaba con `Trait "Laravel\Sanctum\HasApiTokens" not found` al primer request.
- **13 audit tests de regresión** (R-PKG-015 R-G-032). Nuevo `tests/Feature/AuthUserFeedbackAuditTest.php` con source-parsing + reflection tests para pinear los 11 bugs + 2 obs de fase 2 (feedback RETO sobre v1.6.0-rc4). Cada bug tiene un test que falla si el bug vuelve.

### Fixed

- **BUG-NEW-01**: `AuthController::login()` response mal armada. La coma `,` después de `$base` quedaba AFUERA del `array_merge`, generando que PHP interpretara el sub-array como sibling del array padre (key `0` en el JSON response). Front esperaba `admin.roles`/`admin.abilities` y recibía `admin` con `{id, name}` + key `0` separada. **Fix**: sub-array `['roles' => ..., 'abilities' => ...]` ahora es siempre el ÚLTIMO argumento DENTRO del `array_merge(...)` (o concatenado con `+` cuando no hay profile fields).
- **BUG-NEW-02**: Placeholder `{{loginField}}` no se reemplazaba en el array_merge del login response. El command pasaba el placeholder literal al stub porque `buildLoginResponseArray()` se ejecutaba ANTES del `str_replace('{{loginField}}', $loginField, ...)` en `generateStub()`. **Fix**: la función ahora recibe `$loginField` resuelto como parámetro y emite el valor directo.
- **BUG-NEW-03**: `AdminRolesSeeder` setea `'module' => '{scope}'` en `abilities`, pero la tabla `abilities` del paquete solo tiene `id, name, description, timestamps`. **Fix**: stub del seeder ya no setea `module`. Si el consumer quiere `module`, agrega migration custom.
- **BUG-NEW-04**: `AdminRolesSeeder` setea `'description' => '...'` en `roles`, pero la tabla `roles` del paquete solo tiene `id, name, guard, timestamps`. **Fix**: stub del seeder ya no setea `description` en roles (mantiene solo `guard`).
- **BUG-NEW-05 (CRITICAL)**: `mk:make:auth-user --with-crud` no importaba `AdminController`, `RoleController`, `AbilityController` en `routes/api.php`. Sin los `use` statements, las 14 rutas CRUD no cargaban (`ReflectionException: Class "AdminController" does not exist`). **Fix**: `auth-user.routes.with-crud.stub` ahora incluye los 3 imports al inicio del bloque.
- **BUG-NEW-06**: Ver `Added` arriba (overrides FK explícitas). Sin esto, los endpoints `assignRoles`, `assignDirectAbilities`, `syncRoles`, `syncRoleAbilities` explotaban en consumers MME.
- **BUG-NEW-07 (BREAKING for MME consumers)**: Migration `2026_06_18_000001_add_fk_role_user_to_auth_users` agrega FK hardcoded `role_user.user_id → auth_users.id`. Asumía que TODOS los users viven en `auth_users`, lo cual NO es válido para consumers MME (R-MK-001) que usan tablas por scope (`admins`, `members`, etc.). **Fix**: la migration se ELIMINA del paquete. Consumers que ya la aplicaron con v1.6.0-rc4 deben `ALTER TABLE role_user DROP CONSTRAINT role_user_user_id_foreign;` antes de upgrade (RETO clean rebuild desde 0 esquiva esto). Si necesitan la FK a su tabla custom, agregan migration propia.
- **BUG-NEW-08 (CRITICAL, cross-engine)**: `HasAbilities::abilities()` generaba SQL inválido en PostgreSQL: `whereColumn('ability_role.ability_id', 'abilities.id')` referencia la tabla `abilities` sin joinearla explícitamente. MySQL/MariaDB lo tolera (optimizador), PostgreSQL rompe con `SQLSTATE 42P01: missing FROM-clause entry for table "abilities"`. Efecto: `login()` y `me()` retornaban `"abilities": []` en Postgres, y `GET /api/admins` explotaba al eager-load `abilities`. **Fix**: `->join('abilities', 'abilities.id', '=', 'ability_role.ability_id')` explícito dentro del `whereExists`. SQL portable cross-engine.
- **BUG-NEW-09**: Ver `Added` arriba (`mk:fix:sanctum-uuids`).
- **BUG-NEW-10**: Ver `Added` arriba (Sanctum installation check).
- **BUG-NEW-11 (BUG-02 regression)**: `buildProfileFieldsReplacements()` generaba docblock con `* @property` mal indentado (1 espacio en vez de 5) y sin header descriptivo. Resultado: cuando había `--profile-fields`, el modelo generado tenía docblock suelto (`/**\n * @property...\n */\n/**\n * Columnas asignables...\n */`) que confundía IDEs y PHPStan. **Fix**: 5 espacios de indentación para alinear con `     *` + header "Profile fields per-scope (R-PKG-011)." + cierre con `\n     */\n`.

### Changed

- **Migration `2026_06_18_000001_add_fk_role_user_to_auth_users.php` removida** (R-PKG-015 BUG-NEW-07). Consumers que ya la aplicaron deben hacer `down()` manual o `ALTER TABLE` antes de upgrade. Ver `Migration desde v1.6.0-rc4` abajo.
- **`buildLoginResponseArray()` signature cambiada** (R-PKG-015 BUG-NEW-01+02). Antes: `buildLoginResponseArray(array $profileFieldsRaw)`. Ahora: `buildLoginResponseArray(array $profileFieldsRaw, string $loginField)`. Es método `protected` interno del command, no afecta API pública.

### Migration desde v1.6.0-rc4

- **BREAKING para consumers MME que aplicaron la FK en rc4**: ejecutar ANTES de `composer update`:
  ```sql
  ALTER TABLE role_user DROP CONSTRAINT role_user_user_id_foreign;
  ALTER TABLE ability_user DROP CONSTRAINT ability_user_user_id_foreign;
  -- Mantener role_user.role_id → roles.id y ability_user.ability_id → abilities.id
  ```
  Consumers con clean rebuild desde 0 (RETO fase 3+) NO necesitan esto.
- **Para consumers con UUIDs**: después de `php artisan install:api`, correr `php artisan mk:fix:sanctum-uuids` ANTES de `php artisan migrate`.
- **Sin acción** para el resto (todos los demás fixes son BC-clean additive).

## [1.6.0-rc4] - 2026-06-26

### Added

- **`php artisan mk:make:auth-user {Scope} --with-crud` flag** (R-PKG-014 MEJORA-02 / BUG-08). Genera CRUD completo del scope + RBAC triada: `AdminController` + `RoleController` + `AbilityController` (todos extendiendo `SmartController`), 4 `FormRequest`s, 3 `JsonResource`s, 2 DTOs readonly (`AdminData`, `AdminFilterData`), `Repository` + interface, `Service`, `Factory` DDD, `Seeder` con 4 roles predefinidos (super-admin, admin, editor, viewer). Total: 17 archivos nuevos. El service provider se extiende automáticamente con el binding del repository. Ortogonal con `--with-auth-rbac`, `--login-field`, `--profile-fields`.
- **`mk:auth:create-super-admin --roles=<csv>`** (R-PKG-014 MEJORA-04). Siembra múltiples roles predefinidos (super-admin/admin/editor/viewer) en una sola corrida, cada uno con su set de abilities pre-asignadas. Override el método `roleAbilitiesMap()` para customizar la jerarquía.
- **`--profile-fields-required=<csv>` flag** (R-PKG-014 BUG-03 fix). Override del validation default `nullable` a `required` para profile fields específicos. Default BC: todos nullable.
- **Prefijo `!` en `--profile-fields=<csv>`** (R-PKG-014 BUG-09 fix). Marca el field como `unique` en la migration. Ej: `--profile-fields=full_name,!ci,phone` → `ci` queda `->unique()->nullable()`.
- **`Mk\Director\Auth\Services\RefreshTokenParser`** (R-PKG-014 BUG-07 fix). Helper para parsear tokens Sanctum v4 `<id>|<plaintext>`. Lanza `InvalidRefreshTokenException` si el formato es inválido.
- **`TokenIssuer::rotateRefreshToken($refreshToken, $expectedScope)`** (R-PKG-014 BUG-07 fix). Valida el refresh_token, hashea solo el plaintext, busca en `personal_access_tokens`, emite un nuevo access_token con el mismo `auth_scope`, opcionalmente rota el refresh_token (config `mk_director.auth.refresh.rotate_on_refresh`). Defense-in-depth contra escalación de scope vía refresh.
- **`AuthController::refresh()`, `reset()`, `forgot()` implementación completa** (R-PKG-014 BUG-07 fix). Reemplazan los TODOs con código funcional usando `TokenIssuer::rotateRefreshToken()`, `RefreshTokenParser`, `Hash::check()`, `personal_access_tokens`, `{scope}_password_reset_tokens`. Emite `AuthEvent` para audit log.
- **`AuthController::login()` response extendida** (R-PKG-014 BUG-05 fix). Ahora incluye profile fields + roles + abilities (no solo `id, name, {loginField}`). Built dinámicamente via `{{loginResponseArray}}` placeholder.
- **`AuthController::me()` eager-load** (R-PKG-014 BUG-06 fix). Hace `$user->loadMissing(['roles', 'directAbilities'])` antes del `sendResponse` para que `autoTransform()` serialice correctamente.
- **`AuthController::logout()` lookup order fix** (R-PKG-014 BUG-01 fix). `$user = $request->user()` ahora ocurre ANTES del `authorizeAbility('logout', $user)`. BC: el bug solo afectaba con `--with-auth-rbac`.
- **Storage link check** (R-PKG-014 BUG-10 fix). Al generar un scope con `--profile-fields=photo_path,...`, si `mk_director.storage.disk === 'public'` y `public/storage` no existe, warn al dev para que corra `php artisan storage:link`.
- **Auto `mk:discover-abilities`** (R-PKG-014 MEJORA-03). Si el scope se genera con `--with-auth-rbac`, el scaffolder corre `mk:discover-abilities` automáticamente al final para tener las abilities en DB antes del primer uso.
- **Factory DDD helpers** (R-PKG-014 MEJORA-07). Cuando se genera con `--with-crud`, el modelo incluye `use HasFactory` + override `newFactory()` apuntando a la factory DDD en `App\Modules\{Scope}\Database\Factories\{Scope}Factory`.
- **Ability naming convention docs** (R-PKG-014 MEJORA-05). Nueva sección en `DEVELOPER_GUIDE.md` documentando la convención `'auth.{scope}.{endpoint}'` para abilities de `--with-auth-rbac`.

### Changed

- **Profile fields default validation `nullable`** (R-PKG-014 BUG-03 fix). Antes era `required`, lo cual contradecía la migration (`->nullable()`). Para forzar `required` por field, pasar `--profile-fields-required=<csv>`.
- **`resolveProfileFields()` retorna metadata `{type, unique}`** (R-PKG-014 BUG-09 fix). Antes retornaba solo `[$key => $type]`. La firma ahora es `array<string, array{type: string, unique: bool}>|null`. BC preservada en el output (los stubs siguen igual).
- **`mk:auth:create-super-admin` ahora itera roles** (R-PKG-014 MEJORA-04). Antes solo creaba un super-admin. Ahora itera sobre `$rolesToSeed` y asigna cada role + abilities del map.

### Fixed

- **BUG-01**: `logout()` usaba `$user` antes de definirlo (regression de R-PKG-010).
- **BUG-02**: Model docblock `@property` suelto (sin `/** ... */` de apertura/cierre).
- **BUG-03**: `register()`/`updateProfile()` validation `required` vs migration `nullable()`.
- **BUG-04**: `register()` no aceptaba `password`.
- **BUG-05**: `login()` retornaba solo `id, name, {loginField}`.
- **BUG-06**: `me()` no hacía eager-load de `roles` + `directAbilities`.
- **BUG-07**: `refresh()` y `reset()` venían como TODO sin implementación.
- **BUG-08**: scaffolder no generaba CRUD del scope (CRUD ahora opt-in via `--with-crud`).
- **BUG-09**: `--profile-fields` no permitía `unique` constraint (ahora con prefijo `!`).
- **BUG-10**: scaffolder no verificaba `storage:link`.

### Migration desde v1.6.0-rc3

- **Sin acción requerida** para consumers que NO usen `--profile-fields` (default BC: validation no aplica). Para consumers que SÍ usen `--profile-fields`:
  - **Si tenían validación `required` antes**: agregar `--profile-fields-required=<campos>` para mantener el comportamiento.
  - **Si querían `unique` en algún field**: antes lo agregaban a mano en la migration. Ahora usar prefijo `!` (`!ci`) y regenerar.
- **`AuthController::register()` requiere `password` por default** (BUG-04 fix). Si tu consumer override `register()` con custom validation, no aplica. Si lo dejó default del scaffolder, ahora `password` es required (antes era imposible registrarse sin password).
- **`AuthController::logout()` con `--with-auth-rbac` reordenado** (BUG-01 fix). Si tu consumer override `logout()` con ability check custom, no aplica. El fix solo cambia el orden del lookup.
- **`AuthController::login()`/`me()` ahora retorna profile fields + roles + abilities** (BUG-05/BUG-06 fix). Si tu front esperaba solo `id, name, email` en `/api/{scope}/auth/login`, ahora hay más keys. Tu front puede ignorarlas o usarlas.

### Spec

- Sprint: `.makromania/projects/mk-director/openspec/changes/2026-06-26-auth-user-feedback-fixes/`
- Feedback origen: [`.makromania/projects/reto/modules/admin/FEEDBACK-TO-MK-DIRECTOR.md`](../../reto/modules/admin/FEEDBACK-TO-MK-DIRECTOR.md) (RETO v1.1 consumer).
- 17 archivos nuevos (stubs), 5 modificados (command + stubs + service helpers), 11 tests nuevos.

---

## [1.6.0-rc3] - 2026-06-26

### Changed

- **`php artisan mk:update` ahora lista TODAS las versiones superiores a la instalada, incluyendo pre-releases** (R-PKG-013). Bug fix: el filtro previo `/^v?\d+\.\d+\.\d+$/` ocultaba cualquier versión con sufijo (`-rc1`, `-rc2`, `-beta`, `-alpha`). Si estabas en `v1.3.1`, el command decía "última disponible: v1.4.0" cuando `v1.6.0-rc2` ya estaba en Packagist. Ahora:
  - Lista todas las versiones publicadas en Packagist que son mayores a la instalada.
  - Las presenta en un menú navegable con flechas del teclado (Symfony `choice()`), sin necesidad de flags (`--include-rc`, `--channel=`, etc.).
  - El usuario elige con ↑↓ + Enter. Por default, la primera opción es la versión más alta disponible (sea RC o estable).
  - Markers visuales: `⭐ (última estable)` y `🧪 (pre-release)` para distinguir.
  - Una vez elegida, ejecuta `composer require makroz/director-laravel:vX.Y.Z` (en lugar del `composer update` genérico de antes) para garantizar la versión exacta.

### Migration desde v1.6.0-rc1 / rc2

- **Sin acción requerida.** El comportamiento cambia solo para devs que corren `php artisan mk:update`. Los que ya están en la última versión verán el nuevo output ("X versiones disponibles para actualizar") pero ningún cambio real.
- Si tenés scripts CI que pinean `composer update makroz/director-laravel`, ahora deberías pinear `composer require makroz/director-laravel:vX.Y.Z` para reproducibilidad. (Esto era implícitamente cierto antes también — `composer update` sin constraint puede moverse a versiones inesperadas.)

### Spec

- Sprint: `openspec/changes/2026-06-26-mk-update-interactive/`
- Bug original detectado por Mario en RETO (corriendo `mk:update` desde `v1.3.1`, vio "última v1.4.0" ignorando `v1.6.0-rc2`).

---

## [1.6.0-rc1] - 2026-06-25

Release candidate. **Extensión backward-compatible** de `--profile-fields` en
`php artisan mk:make:auth-user {Scope}`: sintaxis `key[:type]` para declarar
tipos custom (más allá de `string` que era el único tipo en v1.5.0-rc5).

### Added

- **Sintaxis `key:type` en `--profile-fields=<csv>`** (R-PKG-012): cada item del
  CSV puede ser `key` (sin tipo, default `string`) o `key:type` (tipo explícito).
  Sin `:` = `string` (BC con R-PKG-011). Tipos case-sensitive (lowercase only).
- **8 tipos soportados** en v1.6.0-rc1 (lista cerrada):

  | Tipo | Migration column | Model cast | Validation rule |
  |---|---|---|---|
  | `string` (default, BC) | `string` | (sin cast) | `['required', 'string', 'max:255']` |
  | `text` | `text` | (sin cast) | `['required', 'string']` |
  | `int` | `integer` | `'integer'` | `['required', 'integer']` |
  | `decimal` | `decimal(8,2)` | `'decimal:2'` | `['required', 'numeric']` |
  | `bool` | `boolean` | `'boolean'` | `['required', 'boolean']` |
  | `date` | `date` | `'date'` | `['required', 'date']` |
  | `datetime` | `dateTime` | `'datetime'` | `['required', 'date']` |
  | `json` | `json` | `'array'` | `['required', 'array']` |

- **Constante `PROFILE_FIELD_TYPES`** en `MakeAuthUserCommand`: tabla cerrada
  con 8 tipos y sus configs (column_method, column_args, cast, validation).
  Reutilizable desde otros commands si en el futuro se necesita.

### Ortogonalidad

R-PKG-012 es extensión de R-PKG-011. Combinable con todos los flags existentes:

```bash
# v1.5.0-rc5 (BC): todos string
php artisan mk:make:auth-user Admin --profile-fields=name,dni,phone

# v1.6.0-rc1 (NEW): tipos custom
php artisan mk:make:auth-user Admin \
  --profile-fields=name:string,birthdate:date,age:int,biography:text,active:bool

# Mixed (con y sin tipo): default string cuando no se especifica tipo
php artisan mk:make:auth-user Admin --profile-fields=name,age:int,active:bool

# RETO Bolivia Member scope (futuro, post-GA)
php artisan mk:make:auth-user Member \
  --login-field=email \
  --with-auth-rbac \
  --profile-fields=name:string,phone:string,birthdate:date,active:bool,registered_at:datetime,metadata:json
```

Los 5 flags del command siguen siendo combinables:
`--login-field`, `--with-auth-rbac`, `--profile-fields` (con o sin tipos),
`--verify-email`. Sin interacción inesperada entre ellos.

### Compatibility

- **BC con R-PKG-011 preservada**: `--profile-fields=name,dni,phone` (sin
  tipos) se interpreta como `name:string,dni:string,phone:string`. Output
  **idéntico** a v1.5.0-rc5. 0 regresiones verificadas con 411 tests Pest
  verdes (372 baseline + 39 nuevos).
- **Default mode preservado**: sin `--profile-fields`, comportamiento idéntico
  a v1.5.0-rc5 (sin profile fields, sin register method).
- **Tipos desconocidos → fail-fast**: `--profile-fields=name:foo` se rechaza
  con error explícito listando los 8 tipos válidos. No fail-silent.
- **Tipos case-sensitive**: `String`, `STRING`, `sTrInG` se rechazan (solo
  lowercase). No normalización implícita.
- **Out of scope v1.6.0-rc1**: `file`/`avatar` (storage uploads, R-PKG-013+),
  `enum` (Laravel 11+ `Rule::enum()`, R-PKG-014+), `decimal` con precisión
  custom (`decimal:10,4`, post-RC si RETO necesita), `uuid`/`ulid`,
  `int[]`/`string[]`.

### Anti-patterns

- ❌ **No usar `--profile-fields=name:foo`**: tipo no en lista cerrada se
  rechaza con error. Tipos válidos: `string, text, int, decimal, bool, date,
  datetime, json`.
- ❌ **No usar `--profile-fields=name:String`**: case incorrecto se rechaza.
  Solo lowercase.
- ❌ **No usar `--profile-fields=email:date --login-field=email`**: colisión
  con login field sigue siendo rechazada por R-PKG-011 (regla preservada).
- ❌ **No usar tipos para compartir datos entre scopes**: cada scope tiene su
  propia tabla y modelo. Para datos compartidos, exponer `Api\*` interface
  del scope que los posee (MME/R-MK-001).
- ❌ **No esperar validación strict format para `date`/`datetime`**: el rule
  es `['required', 'date']` loose (acepta múltiples formatos). Para strict
  format (`Y-m-d` o ISO 8601), override `register()`/`updateProfile()` en el
  AuthController generado.

### Spec

- Proposal: `openspec/changes/2026-06-25-profile-fields-types/proposal.md`
- Spec formal: `openspec/changes/2026-06-25-profile-fields-types/specs/profile-fields-types.md`
- Design: `openspec/changes/2026-06-25-profile-fields-types/design.md`
- Tasks: `openspec/changes/2026-06-25-profile-fields-types/tasks.md`

## [1.5.0-rc1] - 2026-06-24

Release candidate. **New feature** in the `mk:module` scaffolder: the
`--with-rbac` flag generates a complete bounded-context RBAC triad
(User + Role + Ability + 2 pivots + 3 Policies + RbacService +
ServiceProvider with Gate bindings) in a single command. The triad
is fully isolated from the package's central `Auth/Models/{Role,Ability}`
and uses its own migration namespace prefixed by `{moduleNameLower}_`.

### Added

- **`php artisan mk:module {Name} --with-rbac`**: scaffolds a complete
  RBAC triad per module (D1: per-module isolation, NOT reuse of
  central `Auth\Models\Role`/`Ability`). Generates 20 files:
  - 3 Models: `{Name}` (extends `Illuminate\Foundation\Auth\User`, NOT `AuthUser` — D2), `Role`, `Ability`.
  - 3 Controllers: `{Name}Controller` (CRUD + `assignRole`/`revokeRole`),
    `RoleController` (CRUD + `syncAbilities`), `AbilityController` (read-only).
  - 3 Policies: `{Name}Policy`, `RolePolicy`, `AbilityPolicy` — `before()` super-admin
    bypass + per-method `hasAbility()` checks (default-deny, RBAC-004).
  - 1 Service: `RbacService` (singleton, bound in ServiceProvider).
  - 5 Migrations: 3 entity tables (`{scope}_users`, `{scope}_roles`,
    `{scope}_abilities`) + 2 pivots (`{scope}_role_user`,
    `{scope}_ability_role`) with **FK constraints + `cascadeOnDelete()`
    on both columns** (R-RISK-001, hardening R3-014).
  - 1 ServiceProvider: auto-`Gate::policy()` for 3 models +
    auto-`Gate::define()` for 15 abilities (D5: explicit abilities
    make `mk:discover-abilities` — R-PKG-007 — trivial).
  - 1 Routes file (`Routes/api.php`): CRUD for the 3 controllers +
    `assignRole` / `revokeRole` / `syncAbilities` actions.
  - 3 standard reusados: DTO, Repository, RepositoryInterface.
  - Timestamps secuenciales (`addSeconds($i)`) para evitar colisión
    de filenames en migrations generadas en el mismo run.

- **Per-module RBAC isolation (D1)**: each `--with-rbac` module gets its
  own tables. Avoids cross-module state contamination. Aligns with
  R-MK-001 (MME: bounded contexts must not share DB state).

- **Auto-discover abilities source-of-truth (D5)**: the ServiceProvider
  exposes `discoverAbilities()` returning 15 explicit ability strings
  (`{scope}.{resource}.{action}`). `mk:discover-abilities` (R-PKG-007)
  can use this as the canonical list when seeding the `{scope}_abilities` table.

### Tests (15 new)

- `tests/Feature/MkModuleWithRbacTest.php` — 15 Pest tests, 157 assertions.
  Coverage: scaffolding generates 20 files, FK constraints in pivots,
  `Gate::policy` auto-bind for 3 models, 15 abilities via `discoverAbilities()`,
  default-deny via `hasAbility()` in all 7 CRUD methods, `before()` super-admin
  bypass, User extends `Authenticatable` NOT `AuthUser`, end-to-end tempdir
  test that runs the actual command.

### Breaking change

**None for existing consumers.** This release is purely additive: existing
modules generated without `--with-rbac` see no change. Only RETO's orphan
branch (`makromania/260624-0511--admin-module`, pre-1.4.0) has a custom RBAC
module that will be replaced when it bumps to v1.5.0 (planned sprint
R-RET-001, separate).

### Related sprints

- **R-PKG-007** (`mk:discover-abilities`): consumes the explicit abilities
  list from `--with-rbac` provider.
- **R-PKG-009** (`mk:make:auth-user --login-field`): orthogonal flag.
  Run separately to add login flow to a module that already has RBAC.
- **R-PKG-010** (`AuthController` RBAC stub): depends on `--with-rbac`
  for the `Role`/`Ability` models.

### Spec

- Spec: `openspec/changes/2026-06-24-admin-with-rbac/specs/admin-with-rbac.md`
- Design: `openspec/changes/2026-06-24-admin-with-rbac/design.md`
- Tasks: `openspec/changes/2026-06-24-admin-with-rbac/tasks.md`

## [1.5.0-rc2] - 2026-06-25

Release candidate. **New Artisan command**: `php artisan mk:discover-abilities`
auto-publishes abilities into `{scope}_abilities` by reading them from the
module's `ServiceProvider::discoverAbilities()` (consumed when present),
falling back to PHP 8.4 attributes `#[\Mk\Director\Auth\Attributes\Ability]`
and docblock `@mk-ability` annotations when the provider doesn't expose the
method.

### Added

- **`php artisan mk:discover-abilities {--module=*} {--dry-run} {--force} {--json}`**:
  - **Source-of-truth (D1 hybrid)**: provider primary; attribute + docblock
    as fallback ONLY when provider absent. Never mix sources within a module.
  - **PHP 8.4 attribute `#[\Mk\Director\Auth\Attributes\Ability(name, description)`**:
    repeatable, target METHOD, ideal for typed declaration on controllers.
  - **Docblock fallback `@mk-ability name|description`**: regex-escape-free
    (PHP 8.5 PCRE2), supports pre-8.4 apps.
  - **Write intent (D3)**: interactive `$this->confirm(..., false)` with
    `--force` skip + `--dry-run` skip + Laravel `--no-interaction` safe
    no-op (Q3 sign-off).
  - **Idempotent UPSERT** into `{scope}_abilities` (`{scope}` = snake_case
    plural of module name, e.g. `admin_abilities`).
  - **Opt-in auto-register**: `mk_director.features.auto_discover_abilities = true`
    runs on every boot (sandbox/dev only — off by default).
  - **Configurable module path**: `mk_director.paths.modules` (default
    `app_path('Modules')`, env override `MK_MODULES_PATH`).
- **`src/Auth/Attributes/Ability.php`**: PHP 8.4 attribute,
  `#[\Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]`.
- **Config additions**: `paths.modules`, `features.auto_discover_abilities`.

### Compatibility

- No BC break for existing consumers (command is opt-in).
- The `--with-rbac` provider from 1.5.0-rc1 is consumed as-is: `mk:module Admin --with-rbac`
  followed by `mk:discover-abilities --module=admin --force` is the canonical
  workflow for scaffolding + ability sync.

### Spec

- Spec: `openspec/changes/2026-06-24-discover-abilities-to-core/proposal.md`
- Design: `openspec/changes/2026-06-24-discover-abilities-to-core/design.md`
- Tasks: `openspec/changes/2026-06-24-discover-abilities-to-core/tasks.md`

## [1.5.0-rc3] - 2026-06-25

Release candidate. **Configurable login field** for `mk:make:auth-user`:
the new `--login-field=<campo>` flag lets consumers authenticate with
non-email identifiers (CI for Bolivia, phone, username, documento, etc.)
without hardcoding the column.

### Added

- **`php artisan mk:make:auth-user {Scope} --login-field=<campo>`**:
  - **Default `email`** (BC con v1.4.0 — comportamiento idéntico sin flag).
  - **String-only fields** (D1): valida `[a-zA-Z_][a-zA-Z0-9_]*`. Empty
    o ausente → fallback a `email`.
  - **Columna DB = nombre del campo** (D3): `--login-field=ci` genera
    `$table->string('ci')->unique()`, NO `login_field` o `email`.
  - **Validación mínima** (D4): `required|string` cuando loginField != email
    (consumer customiza vía LoginRequest override).
  - **`MustVerifyEmail` interface** (D5): solo se implementa cuando loginField
    es `email` (subclases con `ci`/`phone` lo heredan del AuthUser base pero
    el cast de `email_verified_at` se omite).
- **`AuthUser` base agnóstico**:
  - Nueva property `$loginField` (default `email`).
  - Nuevo método `getLoginField(): string`.
  - Nuevo local scope `scopeWhereLoginField(Builder $query, string $value): Builder`
    (D6 — permite queries dinámicas agnósticas al campo).
  - Docblock ya NO hardcodea `@property string $email` (regresión cubierta
    por `AuthUserDocblockTest`).
- **Config**: `mk_director.auth.login_field` (env `MK_LOGIN_FIELD`, default `email`).
- **Stubs actualizados**: `auth-user.model.stub`, `auth-user.migration.stub`,
  `auth-user.auth-controller.stub` ahora son parametrizados con `{{loginField}}`
  + placeholders condicionales para BC con v1.4.0.

### Compatibility

- **BC preservada**: sin flag, `mk:make:auth-user Admin` genera idéntico a v1.4.0.
- 316/316 tests verde, 0 regresiones.

### Examples

```bash
# Default (BC): email
php artisan mk:make:auth-user Admin

# Bolivia: cédula de identidad
php artisan mk:make:auth-user Admin --login-field=ci

# Genérico: phone
php artisan mk:make:auth-user Member --login-field=phone

# Genérico: username
php artisan mk:make:auth-user Customer --login-field=username
```

### Spec

- Spec: `openspec/changes/2026-06-24-auth-user-login-field/proposal.md`
- Design: `openspec/changes/2026-06-24-auth-user-login-field/design.md`
- Tasks: `openspec/changes/2026-06-24-auth-user-login-field/tasks.md`

---

## [1.5.0-rc4] - 2026-06-25

Release candidate. **RBAC integration** for the `AuthController` generated
by `mk:make:auth-user`: the new `--with-auth-rbac` flag adds ability checks
on `/me` and `/logout`, rate-limit middleware on `/login` (and `/forgot`,
`/reset`), and audit-log events for the auth flow. Default (sin flag)
preserva comportamiento idéntico a v1.5.0-rc3.

### Added

- **`php artisan mk:make:auth-user {Scope} --with-auth-rbac`**:
  - **Default `false`** (BC con v1.5.0-rc3 — comportamiento idéntico sin flag).
  - **Ability checks** en `/me` y `/logout` vía `authorizeAbility()` helper.
    Configurables via `mk_director.auth.abilities.{me,logout}` (default `null`
    → no check, retrocompatible).
  - **Rate limit middleware** en endpoints públicos:
    - `/login`: `throttle:5,1` (configurable via `mk_director.auth.rate_limits.login`).
    - `/forgot`: `throttle:3,1`.
    - `/reset`: `throttle:3,1`.
  - **Audit log events** vía `Mk\Director\Auth\Events\AuthEvent`:
    - `auth.login.success` (user_id, ip, user_agent, scope).
    - `auth.login.failed` (login_field_value, ip, user_agent — **sin password**).
    - `auth.logout` (user_id, token_id).
    - `auth.password_reset.requested` (login_field_value, ip).
    - `auth.refresh.success` y `auth.password_reset.success` marcados con TODO
      (esperan implementación del consumer).
    Consumido por `MkAuditLoggerPlugin` si está activo.
- **Constructor injection** opcional: `AbilityResolver` se inyecta via
  container o se resuelve vía `app()` en boot. Fallback a `canMk()` del
  trait `HasAbilities` para tests que no bootean Laravel completo.
- **Configuración**:
  - `mk_director.auth.abilities.{me,logout}` (env `MK_AUTH_ABILITY_ME` /
    `MK_AUTH_ABILITY_LOGOUT`, default `null`).
  - `mk_director.auth.rate_limits.{login,forgot,reset}` (env
    `MK_AUTH_RATE_LIMIT_*`, defaults `5,1` / `3,1` / `3,1`).
- **Nueva clase**: `Mk\Director\Auth\Events\AuthEvent` — readonly props
  (`type`, `payload`), `Dispatchable` trait para emitir via
  `AuthEvent::dispatch(...)`.
- **Stub updates**:
  - `auth-user.auth-controller.stub`: 11 placeholders RBAC condicionales
    (`{{rbacImports}}`, `{{rbacConstructor}}`, `{{rbacAbilityCheckMe}}`,
    `{{rbacAbilityCheckLogout}}`, `{{rbacAudit*}}`, `{{rbacAuthorizeAbilityMethod}}`).
  - `auth-user.routes.stub`: 3 placeholders inline `{{rbac{Login,Forgot,Reset}Throttle}}`
    (preservan línea original del stub cuando están vacíos → BC estricta).

### Anti-patterns (rejected)

- **Habilitar RBAC por default**: rompe BC con v1.5.0-rc3 (consumers que no
  esperaban ability checks). RBAC es **opt-in** vía flag.
- **Loggear passwords** (ni hasheados) en audit events. El payload se
  sanitiza antes de dispatch.
- **Rate limit agresivo global**: configurable por endpoint. Default seguro
  pero tunable.

### Compatibility

- **BC preservada**: sin flag, `mk:make:auth-user Admin` genera AuthController
  + routes **idénticos** a v1.5.0-rc3 (modulo la parametrización de
  `--login-field`). 0 regresiones verificadas con 332 tests Pest verdes.
- **RETO migration path**: cuando bumpeen a v1.5.0 (R-RET-001 phase 2+3),
  regenerar `AuthController` con `--with-auth-rbac` y eliminar los
  ~322 LOC de implementación manual que viven en su rama huérfana.

### Spec

- Spec: `openspec/changes/2026-06-24-auth-controller-rbac-stub/proposal.md`
- Spec formal: `openspec/changes/2026-06-24-auth-controller-rbac-stub/specs/auth-controller-rbac-stub.md`
- Design: pendiente (T0 de tasks.md).
- Tasks: `openspec/changes/2026-06-24-auth-controller-rbac-stub/tasks.md`

---

## [1.5.0-rc5] - 2026-06-25

Minor release. Dos flags opt-in para `php artisan mk:make:auth-user {Scope}`:

1. `--profile-fields=<csv>` — columnas adicionales para el scope (per-scope, no compartidas).
2. `--verify-email` — flujo completo de verificación por email.

Ambos flags son **opt-in**. Sin ellos, el comportamiento es **idéntico a v1.5.0-rc4** (BC preservada).

### Added

- **`mk:make:auth-user --profile-fields=<csv>`** (R-PKG-011): declarativo de columnas adicionales para el scope. Cada field se genera como columna `string` nullable en la tabla del scope, se incluye en `$fillable` y `$casts` del modelo, y se expone vía:
  - `GET /api/{scope}/auth/me` (read via `$fillable`)
  - `PATCH /api/{scope}/auth/me` (update con validación `required|string|max:255` por default)
  - `POST /api/{scope}/auth/register` (write al crear)
- **`mk:make:auth-user --verify-email`** (R-PKG-011): habilita el flujo completo de verificación por email:
  - Columna `email_verified_at` en migration (nullable, opt-in)
  - Cast `'email_verified_at' => 'datetime'` en `$casts` del modelo (opt-in)
  - Endpoint `GET /api/{scope}/auth/email/verify/{id}/{hash}` (signed URL)
  - Endpoint `POST /api/{scope}/auth/email/resend` (throttle 6,1, auth:scope required)
  - Dispatch de `Illuminate\Auth\Notifications\VerifyEmail` queueable en `register()`
  - Métodos `verifyEmail()` + `resendVerification()` en el AuthController generado
- **Refactor de `email_verified_at`**: antes (R-PKG-009) se generaba siempre que `--login-field=email`; ahora depende del flag `--verify-email` (opt-in). R-PKG-011 ADR-009 simplifica la matriz de combinaciones.
- **Método `register()`** en AuthController: NUEVO endpoint `POST /api/{scope}/auth/register`. Se genera solo si `--profile-fields` o `--verify-email` está activo. BC: NO existe en v1.5.0-rc4.

### Ortogonalidad

Los 4 flags del command son independientes y combinables:

```bash
# Default (BC con v1.5.0-rc4)
php artisan mk:make:auth-user Customer

# Solo login field no-email (R-PKG-009)
php artisan mk:make:auth-user Admin --login-field=ci

# Solo RBAC (R-PKG-010)
php artisan mk:make:auth-user Admin --with-auth-rbac

# Solo profile fields (R-PKG-011)
php artisan mk:make:auth-user Admin --profile-fields=name,dni,phone

# Solo email verification (R-PKG-011)
php artisan mk:make:auth-user Admin --verify-email

# Full combo (RETO Bolivia Admin)
php artisan mk:make:auth-user Admin --login-field=ci --with-auth-rbac --profile-fields=name,dni,phone --verify-email
```

### Compatibility

- **BC preservada**: sin `--profile-fields` y sin `--verify-email`, `mk:make:auth-user Admin` genera **exactamente lo mismo** que v1.5.0-rc4. 0 regresiones verificadas con 372 tests Pest verdes (336 baseline + 36 nuevos).
- **`--verify-email` solo aplica si `--login-field=email`**: si se pide el flag con login-field != email (e.g. `--verify-email --login-field=ci`), el scaffolder ignora `--verify-email` con warning explícito. ADR-009.
- **Reserved columns**: `--profile-fields` rechaza colisiones con `id`, `password`, `auth_scope`, `client_id`, `remember_token`, `created_at`, `updated_at`, `email_verified_at`, y con el `--login-field` (que ya tiene su propia columna).
- **Duplicados**: `--profile-fields=name,dni,name` se rechaza fail-fast (no se genera nada).
- **Tipos**: en v1.5.0-rc5 todos los profile fields son `string`. Tipos custom (`date`, `int`, `decimal`, `bool`, `datetime`, `json`, `text`) en v1.6.0-rc1 con `--profile-fields=key:type,...` (sintaxis extendida en el mismo flag, default `string` cuando no se especifica tipo).

### Anti-patterns

- ❌ **No usar `--profile-fields` con columnas reservadas**: `--profile-fields=password` o `--profile-fields=email` (si `--login-field=email`) se rechazan. La razón: colisión con columnas críticas que tienen comportamiento distinto.
- ❌ **No combinar `--verify-email` con `--login-field != email`**: el flag se ignora silenciosamente. Para casos atípicos (e.g. SMS verification de CI), override el AuthController directamente.
- ❌ **No usar `--profile-fields` para compartir datos entre scopes**: cada scope tiene su propia tabla y modelo. Para datos compartidos, exponer una `Api\*` interface del scope que los posee (MME/R-MK-001).

### Spec

- Spec: `openspec/changes/2026-06-25-profile-fields-per-scope/proposal.md`
- Spec formal: `openspec/changes/2026-06-25-profile-fields-per-scope/specs/profile-fields.md`
- Design: `openspec/changes/2026-06-25-profile-fields-per-scope/design.md`
- Tasks: `openspec/changes/2026-06-25-profile-fields-per-scope/tasks.md`

---

## [1.4.0] - 2026-06-24

Minor release. **Breaking change** in the response shape of all 6 auth-user
endpoints generated by `php artisan mk:make:auth-user {Scope}`. The
generated `AuthController` is now aligned with the rest of the package
(BaseController envelope, TokenIssuer service, plugin instrumentation).

### Changed (BC)

**Response shape** of every endpoint generated by the auth-user scaffolder.

Before (v1.3.x, ad-hoc shapes via `response()->json(...)`):
```json
POST /api/{scope}/auth/login
{ "access_token": "...", "token_type": "Bearer", "{scope}": {...} }

POST /api/{scope}/auth/logout
{ "message": "Sesión cerrada." }

POST /api/{scope}/auth/me
{ "id": "...", "name": "...", "email": "..." }

POST /api/{scope}/auth/forgot
{ "message": "Si el email existe, ..." }

POST /api/{scope}/auth/refresh
{ "error": "not_implemented", "hint": "..." }   HTTP 501

POST /api/{scope}/auth/reset
{ "error": "not_implemented", "hint": "..." }   HTTP 501
```

After (v1.4.0, package envelope via `sendResponse()` / `sendError()`):
```json
POST /api/{scope}/auth/login
{ "success": true, "message": "Login exitoso",
  "data": { "access_token": "...", "refresh_token": "...",
            "token_type": "Bearer", "expires_in": 900,
            "{scope}": {...} },
  "debugMsg": [] }

POST /api/{scope}/auth/logout
{ "success": true, "message": "Sesión cerrada.", "data": true, "debugMsg": [] }

POST /api/{scope}/auth/me
{ "success": true, "message": "",
  "data": { "id": "...", "name": "...", "email": "..." },
  "debugMsg": [] }

POST /api/{scope}/auth/forgot
{ "success": true, "message": "Si el email existe, ...",
  "data": null, "debugMsg": [] }

POST /api/{scope}/auth/refresh
{ "success": false, "message": "not_implemented",
  "errors": { "hint": "..." }, "debugMsg": [] }   HTTP 501

POST /api/{scope}/auth/reset
{ "success": false, "message": "not_implemented",
  "errors": { "hint": "..." }, "debugMsg": [] }   HTTP 501
```

**Consumers MUST update** their client code to read from `response.data`
instead of `response` directly. Affects:
- Frontend SPA / mobile that reads the auth response.
- Tests that pinned the v1.3.x shape.

The package's `getDebugData()` is now also active on these endpoints
when the requester has `super-admin` or `dev` role and `?debug=true&_debug=1`
is set (gated by R2-010). Previously the auth endpoints had no debug.

### Changed (BC, internal)

**`$user->createToken(...)` → `TokenIssuer::issueAccessToken($user)`**
and `TokenIssuer::issueRefreshToken($user)`. Direct benefit: TTLs now
come from `mk_director.auth.ttl.access_seconds` / `refresh_seconds` config
instead of being hardcoded at the call site. The `auth_scope` ability is
now baked correctly via `TokenIssuer::buildAccessAbilities()`.

**`extends Illuminate\Routing\Controller` → `extends Mk\Director\Controllers\BaseController`**.
This is the root cause of the BC. The new base class provides:
- `sendResponse()` / `sendError()` (used by the new envelope above)
- `autoTransform()` (Model → API Resource transparent in `me`)
- `getDebugData()` (EXPLAIN gated by role, see above)
- Plugin instrumentation hook (audit log, multi-tenancy)

### Added

- `TokenIssuer` is now a real, mandatory import in the generated
  AuthController (was mentioned in the docblock only). Login issues both
  access and refresh tokens through it; refresh and reset methods
  document the `TokenIssuer + Sanctum v4 id|plaintext parsing` path.

- New `expires_in` field in the login response, sourced from
  `mk_director.auth.ttl.access_seconds` (default 900 = 15 min).

### Notes for consumers

- **RETO** (which already implemented its own `refresh` method based on
  the v1.3.x stub) will need to:
  1. Re-emit its access + refresh tokens through `TokenIssuer` if it
     wasn't already (most likely it was — `RETO` uses Sanctum v4 which
     is what `TokenIssuer` wraps).
  2. Wrap any `response()->json(...)` calls in `sendResponse()` /
     `sendError()` for envelope consistency.
  3. Update the frontend / mobile code to read `response.data` instead
     of `response` directly for the auth endpoints.
- The `mk:make:auth-user` command's output now also tells the dev
  to implement via `TokenIssuer`.

### Test coverage

- 3 new source-parsing tests in `MakeAuthUserCommandTest`:
  - `auth-user auth-controller stub extends BaseController (bug 1.4.0-001)`
  - `auth-user auth-controller stub uses sendResponse / sendError envelope (bug 1.4.0-002)`
  - `auth-user auth-controller stub uses TokenIssuer::issueAccessToken in login (bug 1.4.0-003)`
- Full Pest suite: **270 passed, 0 failed** (267 + 3 new), 13 pre-existing
  deprecations unrelated.
- End-to-end validated against `apps/sandbox-laravel` with a fresh
  `TestScope`:
  - generated `AuthController` extends `Mk\Director\Controllers\BaseController` ✓
  - all 6 endpoints route through `sendResponse` / `sendError` ✓
  - `TokenIssuer` is instantiated in `login` and `issueAccessToken` called ✓
  - `php artisan route:list` shows 6 routes under `api/test_scope/auth/*` ✓

## [1.3.2] - 2026-06-24

Documentation-only patch. No PHP code changes, no public API change, no
behavior change. The package version is bumped to keep the convention
that every tag in the changelog has a corresponding release.

### Fixed

- **`README.md` — "Magic CRUD Controller" reference was outdated.** The
  feature list pointed at `Mk\Director\Controllers\Controller` (the
  template-method base), but the actual "Magic CRUD" implementation is
  `SmartController` (with the `CRUDSmart` trait). The feature is now
  described accurately: declarative ABM via `extends SmartController`
  + `$mkConfig`, with automatic plugin instrumentation.

- **`DEVELOPER_GUIDE.md` §3 — example was using the manual pattern.**
  The example showed `class SurveyController extends BaseController
  { use CRUDSmart; ... }`, which works but is the verbose way (the
  dev has to remember to add the trait manually). The modern,
  scaffolder-generated way is `class SurveyController extends
  SmartController` (the trait is already on the parent). The example
  is now aligned with what `mk:module` actually generates and what
  the `mk-director-laravel` skill documents.

### Notes for consumers

- Zero impact on existing projects. The `Controller` (template method)
  and `BaseController` classes are still available; this PR only
  modernizes the documentation examples.
- The `mk-director-laravel` skill at
  `.makromania/agency/skills/mk-director-laravel/SKILL.md` was
  audited and was already correct (uses `SmartController`).
- The mk-director monorepo guides
  (`docs/MIGRATION_GUIDE.md`, `docs/API_REFERENCE_LARAVEL.md`,
  `docs/guides/CREATE_MODULE.md`, `docs/guides/GETTING_STARTED.md`,
  `docs/guides/AUTH.md`, `docs/guides/MULTI_TENANT.md`) were audited
  and were already correct (no changes needed).

### Test coverage

- No new tests — these are documentation-only changes.
- Defensive run of the full Pest suite: 267 passed, 0 failed.

## [1.3.1] - 2026-06-24

Patch release. Fixes three real bugs in the `php artisan mk:make:auth-user {Scope}`
scaffolder that were reported by the RETO project (the first real-world consumer
of v1.3.0) immediately after release. None of the fixes change the public API
or the command signature — the generated module is functionally identical,
just correct out of the box.

### Fixed

- **ServiceProvider FQCN was missing the `Providers\` subnamespace** (bug
  1.3.0-001). `MakeAuthUserCommand::registerServiceProvider()` used to write
  `App\Modules\{Scope}\{Scope}ServiceProvider::class` into
  `bootstrap/providers.php`, but the stub generates
  `App\Modules\{Scope}\Providers\{Scope}ServiceProvider` (with the
  subnamespace). Result: Laravel could not resolve the class, the module
  silently failed to load, and `route:list` showed zero routes for the
  scope. The FQCN is now built with the correct subnamespace. The previous
  bug is pinned by a new source-parsing test
  (`MakeAuthUserCommandTest::test(...builds the ServiceProvider FQCN with
  the Providers subnamespace...)`) that asserts both the new form is
  present and the old form is absent.

- **Hardcoded `create_admins_table` migration duplicated the scaffolder's
  output** (bug 1.3.0-002). The package used to ship
  `src/Auth/Database/Migrations/2026_06_10_000006_create_admins_table.php`
  as a leftover from the original Admin scope, and `MkServiceProvider::boot()`
  auto-loaded it via `loadMigrationsFrom`. When a consumer ran
  `php artisan mk:make:auth-user Admin` and then `php artisan migrate`,
  both the package's migration and the scaffolder's generated migration
  tried to create the `admins` table, producing a "Table 'admins' already
  exists" failure. The hardcoded file has been removed: the scaffolder is
  the canonical source for the scope's table. Existing consumers that
  already ran the hardcoded migration keep the entry in their
  `migrations` table and the table itself; subsequent `migrate` calls
  skip the now-missing file. Pinned by
  `MakeAuthUserCommandTest::test(...does NOT ship a hardcoded
  create_admins_table migration...)`.

- **Routes stub produced paths without the `api/` prefix** (bug
  1.3.0-003). `auth-user.routes.stub` used to generate
  `Route::prefix('{{moduleNameLower}}/auth')`, producing
  `/admin/auth/login` — but the `AuthController` docblock, the
  scaffolder's success output, and the CHANGELOG entry for 1.3.0 all
  advertised `/api/admin/auth/login`. Root cause: Laravel 11+
  `loadRoutesFrom` from a ServiceProvider does NOT inherit the
  `apiPrefix` configured in `bootstrap/app.php` (that only applies to
  the central `routes/api.php`). The stub now emits
  `Route::prefix('api/{{moduleNameLower}}/auth')`. The existing test
  that asserted the old (broken) prefix has been updated to match
  the correct behavior.

### Test coverage

- 2 new tests in `MakeAuthUserCommandTest` (one pins the correct
  FQCN, one pins the absence of the hardcoded migration).
- 1 updated test in `MakeAuthUserCommandTest` (the routes prefix
  expectation, which used to encode the bug as the "expected"
  behavior).

### Notes for consumers

- The fixes apply to fresh runs of `php artisan mk:make:auth-user`.
  Consumers that already applied manual workarounds (such as RETO,
  which fixed the FQCN in `bootstrap/providers.php` by hand and
  deleted the scaffolder's duplicate migration) are unaffected —
  their workarounds remain valid; the fix simply removes the
  requirement for those workarounds on future consumers.

## [1.3.0] - 2026-06-24

This release closes the audit-driven gap on auth scaffolding (PRs #7,
#8, #9 of this repo, plus PRs #36, #37, #38 of the monorepo). The
package is now feature-complete on the auth front: every scope
scaffolder, the skill deploy flow, the super-admin creator, and the
hardened config block all ship together.

### Added

- **`php artisan mk:make:auth-user {Scope}`** — scaffolder de un scope de
  autenticación MK completo. Cierra el gap entre la doc
  (`docs/guides/AUTH.md` § "Generating a new scope") y el código: el
  comando se documentaba desde 1.0.0 pero no existía en `src/`.
  Genera: `Models/{Scope}.php` (extends `AuthUser` con
  `setAuthScope` en constructor), migration con `auth_scope` indexado,
  `Http/Controllers/AuthController.php` (login/refresh/logout/me/forgot/reset
  como skeleton con TODOs), `Http/Routes/api.php` con
  `prefix('api/{scope}/auth')` + `mk.auth:{scope}` middleware, y
  `{Scope}ServiceProvider` auto-registrado en `bootstrap/providers.php`
  (Laravel 11+) o `config/app.php` (Laravel 10).
  El command **NO** modifica `config/auth.php` del consumer (decisión
  consciente, least surprise): imprime los snippets a agregar
  (guard + provider).
  Stubs: `src/Stubs/auth-user.{model,migration,auth-controller,routes,service-provider}.stub`.
  Spec: MK-LAR-1.0.2 / MK-LAR-1.0.6.
  Diff: `src/Console/Commands/MakeAuthUserCommand.php` (nuevo, 256 líneas).
  Tests: `tests/Unit/Console/MakeAuthUserCommandTest.php` (10 casos),
  `tests/Unit/MakeAuthUserCommandRegisteredTest.php` (2 casos).

- **`php artisan mk:skill:list`** — lista las skills disponibles para
  el ecosistema MK-Director. Escanea tres ubicaciones: paquete,
  agencia (`~/.mavis/agents/main/skills` + `.makromania/agency/skills`),
  y locales (`.makromania/agency/skills` + `.agents/skills` del
  proyecto actual). Muestra una tabla con `Name / Source / Deployed /
  Path`. Read-only, no toca archivos. Útil como punto de partida
  antes de `mk:skill:deploy`. Diff:
  `src/Console/Commands/MkSkillListCommand.php`. Test:
  `tests/Unit/Console/MkSkillListCommandTest.php` (6 casos).

- **`php artisan mk:skill:deploy {nombre?}`** — deploya una skill al
  proyecto actual. Es **asistente, no invasivo**: no toca config,
  no registra providers, no modifica composer. Solo copia
  `SKILL.md` a la ubicación autodetectada y agrega/actualiza la
  sección `## Skills deployadas` en `AGENTS.md` (idempotente: si la
  skill ya está listada, no duplica; si `AGENTS.md` no existe, lo
  crea con un template mínimo).
  Fuentes de la skill (en orden de prioridad):
  1. `~/.mavis/agents/main/skills/{nombre}/SKILL.md` (Mavis agent)
  2. `.makromania/agency/skills/{nombre}/SKILL.md` (agencia del workspace)
  3. `src/Skills/{nombre}/SKILL.md` (futuro: paquete)
  Destino (en orden de prioridad):
  1. `--to=` explícito del usuario
  2. `.makromania/agency/skills/` si existe
  3. `.agents/skills/` si existe
  4. Prompt al usuario con default `.makromania/agency/skills/`
  Flags: `--to=` para forzar destino, `--dry-run` para simular.
  Diff: `src/Console/Commands/MkSkillDeployCommand.php`. Test:
  `tests/Unit/Console/MkSkillDeployCommandTest.php` (7 casos).

- **`php artisan mk:auth:create-super-admin`** — crea el primer usuario
  super-admin del scope "admin". El command se documentaba en
  `docs/GETTING_STARTED.md` desde 1.0.0 pero nunca se implementó;
  el audit 2026-06-24 cerró ese gap.
  Comportamiento:
  - Pregunta email, name, password (con confirmación). Acepta
    `--email=`, `--name=`, `--password=` para skip prompts en CI.
  - Valida formato de email (FILTER_VALIDATE_EMAIL) y longitud
    mínima de password (>= 8 chars).
  - Falla rápido con mensaje accionable si la clase
    `App\Modules\Admin\Models\Admin` no existe (le dice al dev que
    corra `mk:make:auth-user Admin` primero).
  - Es idempotente: re-correr con el mismo email sale con success
    y un warning, sin duplicar el row.
  - Asigna el role "super-admin" (auto-creado si no existe, con
    guard = auth_scope del user = "admin").
  - Asigna la ability `*` como grant directo (path `ability_user`)
    para que el super-admin no dependa de un seeder adicional.
  Diff: `src/Console/Commands/AuthCreateSuperAdminCommand.php`
  (nuevo, ~150 líneas). Test:
  `tests/Unit/Console/AuthCreateSuperAdminCommandTest.php` (7 casos).

### Changed

- **`php artisan mk:update`** — agrega un step opt-in al final del
  flujo que sugiere al dev revisar y deployar skills nuevas del
  ecosistema. Solo dispara si la respuesta a "¿Querés revisar y
  deployar las skills nuevas del ecosistema MK?" es positiva, y
  se salta en `--dry-run`. Implementado como
  `promptForSkillDeploy()` en `MkUpdateCommand`. Diff:
  `src/Console/Commands/MkUpdateCommand.php`. Test:
  `tests/Unit/MkSkillCommandsTest.php` (4 casos).

### Fixed (R-NEW-001 compliant)

- **`config/mk_director.php` `auth` block ya no rompe DDD.**
  Antes:
  - `'user_model' => \App\Models\User::class` — hardcodeaba el modelo
    default de Laravel, que rompe MME (los modelos viven en
    `App\Modules\<Scope>\Models\<Scope>`, no en `App\Models\*`).
  - `'default_user_type' => env('MK_AUTH_DEFAULT_USER_TYPE', 'App\\Modules\\Admin\\Models\\Admin')` —
    asumía que el consumer tiene un módulo Admin, lo que rompe DDD
    (el paquete no debe conocer los módulos del consumer).
  Después: ambos leen de `env(...)` con default `null`. El consumer los
  define en su propio `config/mk_director.php` publicado, después de
  generar un scope con `mk:make:auth-user {Scope}`.
  Diff: `config/mk_director.php`.
  Test: `tests/Unit/MkDirectorConfigDefaultsTest.php` (4 casos).

### Docs

- The `auth` block del config ya no se etiqueta como `Experimental` —
  el command existe, la migración `auth_users` está consolidada, y
  `AUTH.md` documenta el flujo. El paquete deja de marcarse a sí
  mismo como experimental.
- Monorepo companion PRs: #36 (`mk:make:auth-user` doc), #37
  (`mk-update` npm doc + drift fixes), #38 (replace Tinker snippet
  in `GETTING_STARTED.md`).

## [Unreleased]

### Added

### Changed

### Fixed

### Deprecated

### Removed

### Security

## [1.2.2-rc1] - 2026-06-18

Hardening sprint on top of `1.2.0-rc1`. Closes 6 of the 35 Medium/Low
findings from the original 4R audit; the remaining 29 are deferred to
1.3.0 (see `openspec/changes/2026-06-18-1.2.2-hardening/proposal.md`).

### Security (R-NEW-001: every entry below cites the diff to `src/` and the test that covers it)

- **R2-010 — `BaseController::getDebugData` now requires an authenticated
  user with `super-admin` or `dev` role.** Previously, any request with
  `?debug=true&_debug=1` would get EXPLAIN + raw query bindings — a
  PII / schema leak waiting to happen. The gate is fail-safe: apps
  whose User model does not implement `hasRole()` get an empty debug
  payload, not a 500. Diff: `src/Controllers/BaseController.php`.
  Test: `tests/Unit/BaseControllerDebugGateTest.php` (5 cases).

- **R3-014 — `role_user.user_id` foreign key to `auth_users.id`.** The
  pivot was missing the FK, so deleting an `auth_users` row left orphan
  rows in `role_user`. New migration
  `src/Auth/Database/Migrations/2026_06_18_000001_add_fk_role_user_to_auth_users.php`
  adds the FK with `cascadeOnDelete()`. Idempotent: skips silently if
  the FK already exists or the `role_user` table is missing
  (consumer apps with custom migrations). `down()` is wrapped in
  `try/catch` so re-running on a fresh install does not crash. Test:
  `tests/Unit/Auth/RoleUserFkMigrationTest.php` (3 cases).

### Performance (R4-004 + R2-007)

- **DB::listen no longer runs on reads or on system-table writes.**
  Previously the "magic cache" listener ran on EVERY query and only
  excluded the `cache` table via `str_contains`. Cron writes to
  `jobs`, `migrations`, `sessions`, `password_resets`, and the
  `failed_jobs` table would trigger cache invalidations on tables
  the cache never knew about. The new listener:
  (a) skips a hard-coded `$systemTables` allowlist (migrations,
  cache, cache_locks, sessions, password_resets, password_reset_tokens,
  jobs, job_batches, failed_jobs, telescope_*), and
  (b) only acts on writes (`INSERT` / `UPDATE` / `DELETE`).
  Known limitation (documented in the docblock): the regex does not
  match `REPLACE`, `TRUNCATE`, raw stored-procedure calls, or
  Eloquent `upsert()`. Callers using those patterns must
  `Cache::tags([$table . '_all'])->flush()` manually.
  Diff: `src/MkServiceProvider.php`.
  Test: `tests/Unit/MkServiceProviderCacheListenerTest.php` (5 cases).

### Tooling

- **R1-004 — Pint installed and configured.** `pint.json` declares
  preset `laravel` + rules `declare_strict_types` + `ordered_imports
  alpha`. Scripts added to `composer.json`:
  `composer lint` (pint --test), `composer lint:fix` (pint), and
  `composer security:lint`. **The 93 file diff that Pint suggests
  has NOT been applied in this sprint** — it would contaminate the
  PR with unrelated cosmetic changes. Run `composer lint:fix` locally
  before opening a PR. The sprint is shipped with the tool installed
  and configured; the project-wide apply is deferred to 1.3.0.

- **R2-008 + R2-009 — `php artisan mk:security-lint` command.** New
  source-parsing linter with three checks: `$guarded = []` on
  Eloquent models, missing `belongsTo` foreign keys, and
  `MkMultiTenantPlugin::$tenantColumn` set to a value outside the
  whitelist (`tenant_id`, `client_id`, `org_id`, `company_id`).
  Exit codes: `0` on success, `1` on any error. `--strict` flag
  escalates warnings to failures. `--format=json` for CI integration.
  Source-parsing only — no Laravel app boot required, runs in < 2s
  for 100 models. Diff: `src/Console/Commands/SecurityLintCommand.php`
  (new file, 234 lines), registered in `MkServiceProvider`.
  Test: `tests/Unit/SecurityLintCommandTest.php` (6 cases).
  Spec: `openspec/changes/2026-06-18-1.2.2-hardening/specs/security-lint.md`.

### Observability

- **R1-005 (advisory) — `mk:check` warns on unguarded SmartControllers.**
  The `mk:status` command (`MkCheckCommand`) now scans each
  SmartController subclass and emits a `WARN` finding if the source
  has no obvious auth wiring (no `middleware(`, no `MkAuthenticate` /
  `MkAbility` reference, no auth-aware `__construct`). This is
  advisory, not enforcement: SmartController does not enforce auth by
  itself (BC: pre-existing apps rely on it being a pass-through), and
  the responsibility to add a middleware remains the developer's.
  Diff: `src/Console/Commands/MkCheckCommand.php`.
  Test: `tests/Unit/MkCheckCommandAuthWarningTest.php` (4 cases).

### Deferred to 1.3.0

- **R1-001** ADR `Contracts/` vs `Api/` (architectural decision
  required from Mario).
- **R1-003** phpstan + Larastan. Larastan 3.10.0 (the only Laravel 13
  compatible version as of 2026-06-18) crashes on
  `Undefined constant "Larastan\Larastan\LARAVEL_VERSION"` in
  `LarastanStubFilesExtension.php:25`. Bug is upstream. The
  install/remove dance confirmed the lockfile stays clean if we
  wait for Larastan 3.11+ to ship.
- **R1-005 (test)** end-to-end test of `LintBoundariesCommand` that
  actually invokes `handle()`. Out of scope for hardening.
- **R2-001** rewrite of commit `6303844` (`sec(laravel): mitigate
  SQL injection in ListManager`). Git history is git history.
- 27 additional Medium/Low items from the 4R audit. Tracked in
  `openspec/changes/2026-06-18-1.2.2-hardening/proposal.md` and
  to be re-prioritized at the start of the 1.3.0 cycle.

### Process notes

- All 6 task commits are atomic and follow the
  `<type>(<scope>): <subject>` convention.
- The 3 `sec:`-prefixed commits (R2-010) cite both the diff path and
  the test file. R-NEW-001 satisfied.
- The Pint diff (93 files) is NOT shipped in this sprint — see the
  Tooling section above.
- `phpstan` install was attempted, locked to the package's
  compatibility window, then reverted when the Larastan bug
  surfaced. The lockfile is byte-identical to `1.2.0-rc1`.

## [1.2.0-rc1] - 2026-06-17

### Security (R-NEW-001: every entry below cites the diff to `src/`)

The audit found 57 issues (10 critical, 17 high, 19 medium, 12 low).
This RC closes all 10 P0 and all 17 P0-targeted high-severity findings.
Items #2–#4 below are code-only changes that fail loudly if a consumer
was relying on the previous (looser) behavior — there is no data
migration for those.

- **R4-001 — `HasAbilities::canMk` now delegates to `AbilityResolver`.**
  The resolver caches the resolved ability names per user with a
  configurable TTL (default 300s) and short-circuits via Sanctum
  `currentAccessToken()->can()` before any DB query. Every mutator
  (`giveAbilityTo`, `revokeAbilityTo`, `syncDirectAbilities`,
  `assignRole`, `revokeRole`, `syncRoles`) invalidates the cache so
  the next `canMk` reads fresh data. Commit 656360e.

- **R1-002 — `ability_user` migration published.** The pivot table
  for direct ability grants was referenced by `HasAbilities` but
  the migration was never shipped. Now in
  `src/Auth/Database/Migrations/2026_06_10_000007_create_ability_user_table.php`
  with FK to `abilities`, `uuid('user_id')` matching `auth_users.id`,
  and a unique composite index on `(ability_id, user_id, user_type)`.
  Commit d015cfc.

- **R3-005 — `AuthUser` docblock declares `$id` as `string`.**
  Was `@property int $id` even after the UUID migration. Tests parse
  the source file directly because the class uses Sanctum's
  `HasApiTokens` (not in `composer.json`). Commit 568111d.

- **R4-002 — `MkAuthenticate` eager-loads `roles.abilities` and
  `directAbilities` after the scope resolver runs.** Closes the N+1
  every downstream `canMk` would otherwise trigger. Commit 74bfe95.

- **R2-002 / R2-003 — `MkAbility` rejects empty ability lists and
  short-circuits via Sanctum.** Before: `Route::middleware(['mk.ability:'])`
  silently passed every request through (privilege escalation trap).
  Now: returns 500 + `ERR_MIDDLEWARE_MISCONFIGURED`. Sanctum
  `currentAccessToken()->can($ability)` is checked before the role/
  direct-grants path. Commit 1d5ffdb.

- **R2-004 — `AuthUser` uses `HasTenantMembership`; `TenantResolver`
  validates user↔tenant membership.** The middleware now reads the
  user's tenant via `$user->getTenantId()` and returns 403
  `ERR_TENANT_MISMATCH` if the resolved tenant differs. Prevents
  tenant-A tokens from accessing tenant-B data via header. Commit 5dd846d.

- **R2-005 / R2-006 — `TenantContext` is flushed at request end;
  `HasTenantScope` is per-model opt-in.** `MkServiceProvider::boot`
  registers a `terminating` callback that calls `TenantContext::flush()`
  so long-lived workers (Octane / Swoole) do not leak tenant state
  across requests. `HasTenantScope` now requires `protected static
  bool $usesTenant = true` per model — adding the trait alone is a
  no-op. Commit 9a807da.

- **R2-009 / R2-018 / R4-003 — `MkMultiTenantPlugin` whitelist,
  strict comparison, and mutex with `HasTenantScope`.** The plugin
  rejects any `$tenantColumn` not in the documented whitelist
  (`tenant_id`, `client_id`, `org_id`, `company_id`). `beforeDelete`
  uses strict `!==` (was `!=`) to prevent the int-vs-string coercion
  bug where `'00000000-...' (string) != 0 (int)` was truthy. When the
  model already uses `HasTenantScope` with `$usesTenant = true`, the
  plugin skips its own `where()` to avoid applying the tenant
  predicate twice. Commit 7004a3d.

- **R2-014 / R3-007 / R2-012 — `ListManager` LIKE escape, operator
  whitelist, and `restoreState` sanitize.** `escapeLikeWildcards()`
  wraps `addcslashes($value, '\\%_')` and is used by both `applySearch`
  and the `like` filter operator so a search for `'50%'` matches
  the literal string instead of every row containing `50`. The
  search term is also capped at `mk_director.search.max_length`
  (default 256). `applyFilterOperator` now throws
  `InvalidArgumentException` on unknown operators (was a silent
  fallback to `=`). `restoreState` hashes the storage key with HMAC-
  SHA256 of `app.key` and sanitizes the rehydrated filter/sort state
  against the same whitelists used by the apply path. Commit 373007d.

- **R4-005 / R4-006 / R2-016 — Performance + symlink rejection.**
  `OpenApiController::spec` is wrapped in `Cache::remember()` with a
  24h TTL; `mk:generate-docs` calls `Cache::forget()` on the same
  key. `ModuleProviderRegistry::discover()` caches the discovered
  providers for 1h, keys the cache on `md5(realpath(modules path))`
  so any directory change invalidates automatically, and rejects
  symlinked module directories (R2-016) — a symlink under
  `app/Modules` pointing to `/tmp/evil` would otherwise be discovered
  as a legitimate module. Commit 77ad693.

### Process (R-NEW-001)

- **CI: monorepo now has a `pest-laravel` job** that runs
  `./vendor/bin/pest` against the package and is in the `build`
  dependency chain. Path filter so the job only fires when the
  package or the workflow file changes. This closes the gap that
  allowed the previous sprint (PR #3) to declare 6 security fixes
  and ship only 3 — the monorepo CI never exercised the sub-repo's
  pest suite.
- **Spec: `openspec/specs/architecture/modular-encapsulation.md`**
  documents the rule with the three required scenarios (claim
  without diff, claim with diff but without test, monorepo CI
  does not run pest-laravel). R-NEW-001 is enforced at review time.

### Testing infrastructure

- `MkLaravelTestCase` — boots a minimal Laravel Container with
  config/cache/db/files bindings so the 5 pre-existing pest failures
  (R3-009, R3-010, R3-011) are green. No new composer dependency
  (we deliberately avoided `orchestra/testbench` which would pull
  in the full application kernel).
- `StrictTypesTest` extended to scan `tests/` (was `src/` only) and
  asserts `declare(strict_types=1)` is positioned right after
  `<?php` — 8 pre-existing test files were missing the declaration.
- `AuthUserMigrationTest`, `RoleUserMigrationTest` — refactored to
  parse the migration source directly because the chainable
  `Blueprint` mock fights Laravel's real signatures.

### Documentation

- `docs/UPGRADE_1.2.md` — full upgrade guide covering the breaking
  changes, pre-flight checklist, runbook for the UUID migration,
  rollback procedure.
- `bin/migrate-1.1-to-1.2.php` — standalone CLI script with
  `--dry-run`, `--connection`, `--chunk`, `--help`. Idempotent,
  refuses to run outside CLI, refuses to migrate a table whose id
  is neither BIGINT nor CHAR(36).

### Notes

- **DO NOT publish to Packagist yet.** This RC is tagged locally so
  the team can validate against `apps/sandbox-laravel` before
  publishing. Mario will run `composer publish` from his machine
  after sandbox validation passes.
- 9 of the 12 medium-priority and all 12 low-priority findings from
  the audit are deferred to `1.2.2-hardening` (next sprint).

## [1.2.0] - 2026-06-17

### Security

- **A1 — SQL injection mitigado en `ListManager`.** `applyFilters` y `applyJoins` ahora
  requieren una whitelist explícita (`allowed_filters`, `allowed_joins`). Sin whitelist,
  cero filtros/joins se aplican (comportamiento defensivo por defecto). Cualquier campo
  fuera de la lista es ignorado silenciosamente antes de llegar a la query.

### Changed

- **A2 — `MkAuthMiddleware`: respuesta 401 nativa.** Import de `MkResponse` (clase
  inexistente) removido. Reemplazado por `response()->json(['success' => false,
  'message' => 'Unauthenticated.', 'code' => 'ERR_UNAUTHENTICATED'], 401)`.

- **A3 — Migración `auth_users`: ID como UUID.** `$table->id()` reemplazado por
  `$table->uuid('id')->primary()`. Requiere `HasUuids` en el modelo `AuthUser`.

- **A4 — Migración `role_user`: FK como UUID y default configurable.** `user_id`
  tipado como `uuid` (foreign key coherente con A3). El `user_type` por defecto
  ahora se lee de `config('mk_director.auth.default_user_type', 'App\\Models\\User')`.

- **A5 — `MkAuthenticate`: excepción correcta al faltar autenticación.** Reemplazado
  `MissingAbilityException` (de Sanctum, semántica incorrecta) por
  `AuthenticationException` (framework HTTP estándar de Laravel), pasando el guard
  activo como guards array para que el handler de exceptions produzca un 401 correcto.

- **A6 — `declare(strict_types=1)` en todo el source del paquete.** Todos los
  archivos PHP bajo `src/` tienen la declaración strict_types al inicio. Incluye
  un test automatizado `StrictTypesTest.php` que falla si algún archivo nuevo la omite.

> ⚠️ **Nota de migración**: A3 y A4 son cambios en migraciones de base de datos.
> Si ya corriste las migraciones anteriores en un entorno, necesitás rollback +
> re-run (`php artisan migrate:rollback --step=2 && php artisan migrate`).
> En entornos frescos no hay impacto.

## [1.1.1] - 2026-06-16


### Fixed (1.1.1)

- **`HasTenantScope` now uses `TenantScope` instead of an inline closure** —
  the global-scope class shipped in 1.1.0 was dead code at runtime because
  `HasTenantScope::booted()` registered an inline closure with the same
  `where($column, '=', $tenantId)` logic. The code review that landed
  alongside 1.1.0 flagged this as 🔴 bloqueante (B-3): the
  `TenantScope::apply()` was never invoked, the JSDoc on both classes
  claimed a contract that was not in effect, and the duplication was a
  footgun for any future change.
- **`TenantScope::apply()` is now context-aware.** It resolves the current
  tenant from the `TenantContext` singleton on every apply when no
  explicit `tenantId` was passed to the constructor. The constructor
  still accepts an explicit id (used by the 5 unit tests on
  `TenantScope` and by programmatic scopes); when set, that value wins.
  The "fresh read" semantics that the inline closure had are preserved,
  so the scope is safe under long-lived workers (Octane / Swoole) where
  the tenant can change mid-process.
- **No behavior change at the `HasTenantScope` trait boundary** — the
  trait still registers the scope under the alias `'tenant'`, the opt-in
  guard (`mk_director.tenant.enabled`) is unchanged, and the bypass path
  (`Model::withoutGlobalScope('tenant')`) still works.

### Cross-repo coordination

This 1.1.1 ships in lockstep with the `create-mk-director@1.1.1` CLI,
whose templates now require `makroz/director-laravel: ^1.1`. Publishing
this 1.1.1 is what makes the scaffoldeado de proyectos actually
`composer install`-able end-to-end. The CLI bump is in
`makroz/MK-Director#17` (PR #17, already merged into the monorepo's
`dev`).

## [1.1.0] - 2026-06-12

### Added (1.1.0)

- **Multi-tenant opt-in (M-1 of the 1.1.0 sprint).** Three new
  classes under `Mk\Director\Tenancy\*` ship behind a single
  config flag (`mk_director.tenant.enabled`, default `false`):
  - `TenantScope` — Eloquent `Scope` that filters by `tenant_id`
    on every `apply()`. No-op when no tenant is bound, so
    console / queue jobs see all rows by design.
  - `HasTenantScope` — model trait that auto-registers a
    closure-based global scope at `booted()` time. The closure
    reads `TenantContext` on every query, so the tenant id is
    always fresh (no Octane-style freeze).
  - `TenantContext` — singleton service that holds the current
    tenant id for the duration of a request.
  - `TenantResolver` — HTTP middleware that reads the tenant
    from a header (default `X-Tenant-ID`), a path segment, or a
    subdomain, and writes it into the `TenantContext`. Strict
    mode (default) returns 400 when the tenant cannot be
    resolved.
- **Config flag**: `config/mk_director.php` gains a `tenant` key
  (`enabled`, `resolver`, `header_name`, `model`, `strict`).
  The `MkServiceProvider` always registers the middleware on
  the `api` group, but the middleware itself short-circuits to
  a pass-through when `tenant.enabled = false` (opt-in per
  ADR-003).
- **Sandbox fixtures**: `apps/sandbox-laravel` now ships with
  a `mk_tenants` table, a `DemoTenantableModel` that uses
  `HasTenantScope`, and an end-to-end feature test in
  `tests/Feature/TenantScopeTest.php` that proves isolation
  (header, 400, cross-tenant 404, write-back with the right
  tenant).
- **Documentation**: `docs/guides/MULTI_TENANT.md` is updated
  to reflect the shipped feature (no more "Available from
  v1.1.0" warning — it ships with 1.1.0).

### Changed (1.1.0)

- `branch-alias.dev-main` in `composer.json` bumped from
  `1.0.x-dev` to `1.1.x-dev` to track the new minor line.
- `composer.json` gained `minimum-stability: dev` +
  `prefer-stable: true` so the dev toolchain (Pest 5 has only
  RC releases at the time of this writing) installs cleanly
  without affecting consumers (the dev deps are not
  installed by `composer require`).
- `MkServiceProvider::registerTenantMiddleware()` now always
  registers the middleware on the `api` group. The middleware
  is the one that checks `tenant.enabled` and short-circuits.
  This is more flexible than registering conditionally at
  boot — flipping the config at runtime (e.g. in tests)
  picks up the new state without re-booting the framework.

### Fixed (1.1.0)

- `HasTenantScope`'s closure scope was originally typed as
  `function (Model $model)`, but Eloquent invokes closure
  scopes with the **Builder** (not the model). The
  signature is now `function (Builder $builder)`, and the
  model is obtained via `$builder->getModel()`. This was
  caught and fixed before merge by the sandbox feature
  test in `tests/Feature/TenantScopeTest.php`.

## [1.0.0] - 2026-06-10

### Added (1.0.0)

- **Strict Laravel 13 support**: `makroz/director-laravel` 1.0.0 declares hard
  `^13.0` constraints on `illuminate/support`, `illuminate/database`, and
  `illuminate/http`. Laravel 10/11/12 are no longer supported.
- **PHP 8.4 baseline**: hard `^8.4` requirement (typed const, asymmetric
  visibility, property hooks). PHP 8.2/8.3 are no longer supported.
- **Pest 5 baseline**: `pestphp/pest` and `pestphp/pest-plugin-laravel` upgraded
  to `^5.0` for dev testing.
- **OpenAPI 3.x generation**: the `Mk\Director\Controllers\OpenApiController` and
  `Mk\Director\Services\OpenApiGeneratorService` (already in 0.0.1) now
  target the Laravel 13 schema introspection API (`Schema::getColumns()`)
  and are stable for production B2B usage.
- **SmartController + CRUDSmart trait**: stable, with `beforeList` /
  `beforeSearch` / `afterList` / `beforeCreate` / `afterCreate` /
  `beforeUpdate` / `afterUpdate` / `beforeDelete` / `afterDelete` hooks
  contractually documented via `Mk\Director\Contracts\MkModuleServiceInterface`.
- **ListManager** (`Mk\Director\Managers\ListManager`): stable API for
  pagination, filtering, sorting, search, dynamic includes, with-count,
  and joins.
- **Plugin Manager + MkPluginInterface**:
  - `Mk\Director\Managers\PluginManager` is now stable, with full hook
    coverage (`boot`, `beforeQuery`, `beforeSave`, `afterSave`,
    `beforeDelete`, `afterDelete`, `afterResponse`).
  - `Mk\Director\Plugins\FileStoragePlugin` (auto file uploads) and
    `Mk\Director\Plugins\Enterprise\MkMultiTenantPlugin` +
    `Mk\Director\Plugins\Enterprise\MkAuditLoggerPlugin` ship as
    reference implementations.
- **DTO layer**: `Mk\Director\DTOs\MkDTO` and `Mk\Director\DTOs\DTOFactory`
  provide enum-aware type-safe hydration of DTOs and Eloquent casts
  (datetime, json, int, float, bool).
- **MkServiceProvider** registers the package's commands, config, routes
  (`/mk/openapi.json`, `/mk/docs`), and the global DB→Cache listener
  (the "Magic Cache" feature, opt-in via `mk_director.features.auto_cache`).
- **Artisan commands**:
  - `mk:status` — diagnose SmartControllers and their plugin health.
  - `mk:module {Name}` — scaffold a full MME module
    (Controllers, Contracts, DTOs, Enums, Models, Repositories, Requests,
    Resources, Routes, Services, ServiceProvider, Database/Migrations).
  - `mk:service {Name}` — generate a Service that implements
    `MkModuleServiceInterface`.
  - `mk:dto {Name}` — generate a DTO extending `MkDTO`.
  - `mk:generate-docs` — emit a static OpenAPI 3.x JSON file from all
    registered SmartControllers.
- **Module structure documentation**: see `DEVELOPER_GUIDE.md` for the
  canonical `Mk\Director` namespace layout and MME conventions.

### Changed (1.0.0)

- **Composer constraints** tightened from `^10|^11|^12|^13` → `^13.0`
  for all `illuminate/*` dependencies. This is a hard break for projects
  still on Laravel 12 or earlier.
- **PHP requirement** raised from `^8.2` → `^8.4`.
- **Pest test framework** raised from `^3.0` → `^5.0` (Pest 4 skipped
  deliberately; 5 is the long-term stable line).
- **MkServiceProvider** is now the only auto-discovered provider. The
  `extra.laravel.providers` array in `composer.json` is the source of
  truth (no `config/app.php` registration required on Laravel 11/12/13).

### Removed (1.0.0)

- **Support for Laravel 10, 11, 12**: the package no longer resolves on
  these versions. Downgrade to `0.0.x` if you need them.
- **Support for PHP 8.2 / 8.3**: the package relies on PHP 8.4 language
  features in future minor releases; 1.0.0 still compiles on 8.4 but
  is the floor.

### Fixed (1.0.0)

- `Mk\Director\Models\BaseModelBuilder::cacheGet()` and `cacheFirst()`
  are now consistent about the table-tagging fallback when the cache
  store does not support tags (file / database driver) — they no longer
  crash in CI / SQLite environments.
- `Mk\Director\DTOs\DTOFactory::makeFromArray()` correctly casts
  `datetime` and `timestamp` casts to `Carbon\Carbon` (instead of the
  non-existent `Carbon\DateTime`) via the explicit `Carbon\Carbon::parse()`
  path, fixing a regression introduced in 0.0.1.
- `Mk\Director\DTOs\MkDTO::fromArray()` now throws a clear
  `LogicException` when a `readonly` property is re-hydrated after
  construction, instead of silently failing on PHP 8.4.
- `Mk\Director\Traits\CRUDSmart::getCacheTags()` no longer crashes when
  the controller does not declare an explicit `cache_tags` config — it
  falls back to the model's table name, matching the documented contract.
- `Mk\Director\Services\OpenApiGeneratorService` gracefully degrades to
  `fillable`-based introspection when `Schema::getColumns()` is not
  available (e.g. when doctrine/dbal is not installed), preventing 500s
  on a missing driver in dev.
- `Mk\Director\Managers\PluginManager::auditRequirements()` now reports
  both `error` and `warning` findings without truncating the message,
  and is wired into `mk:status` for end-to-end diagnosability.

### Known issues / Deferred to 1.0.1+

- `Mk\Director\Middleware\MkAuthMiddleware` references a non-existent
  `Mk\Director\Utils\MkResponse` class (a pre-0.0.1 stub that was never
  extracted from the original `condaty` code base). The middleware is
  not auto-registered anywhere and is not loaded by the sandbox
  application, so it does not block 1.0.0 — but the file should be
  either completed (with a proper `MkResponse` utility) or removed in
  1.0.1. Tracking: see `tasks.md` follow-up notes.
- `tests/Unit/ListManagerTest.php` still uses a non-existent
  `new ListManager($request, $config)` constructor — the production
  class is a static-method utility. The sandbox-level
  `tests/Feature/ListManagerApiTest.php` covers the real behaviour
  via the HTTP layer. The unit test is a candidate for deletion in
  1.0.1.

### Security

- No security-relevant changes from 0.0.1 → 1.0.0. The `MkMultiTenantPlugin`
  continues to enforce `client_id` isolation in `beforeQuery`,
  `beforeSave`, and `beforeDelete` — this is the recommended path until
  a `TenantScope` global-scope variant ships in 1.1.
- The global DB→Cache listener (`mk_director.features.auto_cache`) only
  flushes on `INSERT/UPDATE/DELETE` against tables whose names appear
  in the SQL — it does not execute untrusted SQL.

### Upgrade guide from 0.0.x

1. Bump your host application's `composer.json`:
   - `"makroz/director-laravel": "^1.0"`
2. Ensure your host project runs on **PHP 8.4** (`composer.json` → `require.php`).
3. Ensure your host project runs on **Laravel 13**:
   `"laravel/framework": "^13.0"`.
4. If you maintain your own DTOs extending `MkDTO`, audit any `readonly`
   properties — the new hydration guard will surface
   re-initialization attempts as `LogicException`.
5. Update dev dependencies in your host project: `pestphp/pest ^5.0`,
   `pestphp/pest-plugin-laravel ^5.0`.
6. Re-run `php artisan package:discover` after `composer update` so
   `MkServiceProvider` is registered.

[1.0.0]: https://github.com/makromania/mk-director/releases/tag/mk-laravel-v1.0.0
