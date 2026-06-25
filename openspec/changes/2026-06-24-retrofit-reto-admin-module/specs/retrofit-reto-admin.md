# Spec — RETO Admin retrofit (R-RET-001)

**Versión**: 0.1 (draft)
**Fecha**: 2026-06-24
**Rule**: R-G-033-D (workflow iterativo)
**SDD**: `openspec/changes/2026-06-24-retrofit-reto-admin-module/`
**Status**: BLOCKED — espera v1.5.0+ del paquete.

---

## Requirement: RETROFIT-001 — Inventario de rama huérfana (INMEDIATO)

**SHALL**: antes de cerrar la rama `makromania/260624-0511--admin-module`, documentar todos sus archivos en `inventory.md` con tier + acción.

**Tier classification**:
- **Tier 1 — Boilerplate regenerable**: cualquier archivo que `mk:module` o `mk:make:auth-user` regenera con v1.5.0+. Acción: regenerar.
- **Tier 2 — Lógica única RETO-only**: archivos con lógica de negocio específica de RETO (no del paquete). Acción: regenerar base + portar lógica sobre el stub.
- **Tier 3 — Solo docs/referencia**: Postman collections, OpenAPI specs, AGENTS.md. Acción: regenerar (auto-gen) o mantener como docs.
- **Tier 4 — Tests como spec**: tests Feature que documentan comportamiento esperado. Acción: regenerar + portar casos RETO.

---

## Requirement: RETROFIT-002 — Re-scaffoldear con v1.5.0+

**SHALL** (post-v1.5.0 publish): RETO regenera el módulo Admin con:

```bash
composer require makroz/director-laravel:^1.5.0
php artisan mk:module Admin --with-rbac
php artisan mk:make:auth-user Admin --login-field=ci --with-auth-rbac
```

**Output esperado**:
- 11+ archivos del módulo Admin generados (R-PKG-008 spec).
- AuthController con RBAC + audit log + rate limit (R-PKG-010 spec).
- Model + migration + LoginRequest con `ci` en lugar de `email` (R-PKG-009 spec).
- `mk:discover-abilities` operativo (R-PKG-007 spec).

---

## Requirement: RETROFIT-003 — Portar lógica RETO-only

**SHALL**: sobre los stubs generados, portar:

1. **Profile fields custom** en `Admin` model (override del fillable).
2. **Lógica de AdminService** (RBAC integration manual del branch huérfano).
3. **Tests Feature** que cubren casos RETO-específicos.

**Out-of-scope**:
- DiscoverAbilitiesCommand — ya viene del paquete (R-PKG-007).
- Role/Ability controllers — ya vienen del paquete (R-PKG-008).
- AuthController RBAC checks — ya viene del paquete (R-PKG-010).

---

## Requirement: RETROFIT-004 — Verificar MME + tests

**SHALL**:
- `php artisan mk:lint:boundaries` verde (R-MK-001).
- `./vendor/bin/pest --filter=Admin` verde.
- `mk:discover-abilities --module=Admin --dry-run` retorna lista esperada.
- `php artisan serve` + curl manual a `/api/admin/auth/login` con `ci` retorna 200 con tokens.
- `curl /api/admin/auth/me` con Bearer retorna 200 con admin data.

---

## Escenarios

### Scenario 1: Inventario completo
```
Given el branch huérfano `makromania/260624-0511--admin-module` tiene 43 archivos
When corro el análisis de inventario
Then `inventory.md` lista los 43 archivos con tier asignado
And Tier 1 tiene ~20 archivos (boilerplate)
And Tier 2 tiene ~10 archivos (lógica única)
And Tier 3 tiene ~5 archivos (docs)
And Tier 4 tiene ~3 archivos (tests)
```

### Scenario 2: Regeneración con v1.5.0
```
Given v1.5.0 está publicada con R-PKG-007/008/009/010 mergeados
And RETO está en branch makromania/260624-1605--bump-director-1.3.1
When bumpeo a v1.5.0 y corro `mk:module Admin --with-rbac`
Then el scaffolder genera 11+ archivos correctamente
And `mk:lint:boundaries` pasa
```

### Scenario 3: Login con ci (RETO Bolivia)
```
Given RETO regeneró AuthController con `--login-field=ci --with-auth-rbac`
When un admin hace POST /api/admin/auth/login con {ci: "1234567", password: "..."}
Then retorna 200 con access_token + refresh_token + admin data
And se emite audit event `auth.login.success`
And rate limit middleware registra el intento
```

### Scenario 4: RBAC check en /me
```
Given config tiene `auth.abilities.me = 'auth.me.read'`
And el admin logueado NO tiene esa ability
When hace GET /api/admin/auth/me con Bearer token
Then retorna 403 Forbidden
```

---

## Anti-patterns

- ❌ Mergear rama huérfana directamente — diverge del paquete.
- ❌ Re-implementar features que ya están en v1.5.0 — usar scaffolders.
- ❌ Skip releerura de skills — la base cambió.
- ❌ Inventario sin tier classification — pierde valor de aprendizaje.

---

## Cross-references

- SDD: `openspec/changes/2026-06-24-retrofit-reto-admin-module/`
- Depends on: R-PKG-007, R-PKG-008, R-PKG-009, R-PKG-010 (published).
- Source: rama huérfana `makromania/260624-0511--admin-module`.
- Skills: `~/.makromania/agency/skills/mk-director-laravel/SKILL.md` (post-v1.5.0 update).
- Regla: `~/.makromania/agency/global/rules_orchestration.md` § R-G-033.
