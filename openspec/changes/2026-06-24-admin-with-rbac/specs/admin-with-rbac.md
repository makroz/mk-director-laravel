# Spec — mk:module --with-rbac (R-PKG-008)

**Versión**: 0.1 (draft)
**Fecha**: 2026-06-24
**Rule**: R-G-033-A (triage GO)
**SDD**: `openspec/changes/2026-06-24-admin-with-rbac/`

---

## Requirement: RBAC-001 — Flag --with-rbac en mk:module

**SHALL**: `php artisan mk:module {Name} --with-rbac` genera un módulo completo con RBAC: User (configurable), Role, Ability, pivots, policies y service.

**Casos de uso**:
1. App que necesita RBAC desde día 1 (admin panel, multi-tenant con permisos).
2. Cualquier bounded context que requiera "usuarios del sistema con roles".

---

## Requirement: RBAC-002 — Tres modelos generados

**SHALL**: el scaffolder genera 3 Eloquent models con sus migrations:

```php
// User (Admin, Member, Customer — configurable)
class Admin extends Model
{
    protected $fillable = ['name', 'email', 'password'];
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }
    public function hasAbility(string $ability): bool
    {
        return $this->roles->flatMap->abilities->pluck('name')->contains($ability);
    }
}

// Role
class Role extends Model
{
    protected $fillable = ['name', 'description'];
    public function abilities(): BelongsToMany
    {
        return $this->belongsToMany(Ability::class);
    }
}

// Ability
class Ability extends Model
{
    protected $fillable = ['name', 'description'];
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }
}
```

---

## Requirement: RBAC-003 — Pivots con FK

**SHALL**: las migrations de pivots declaran FK constraints (R-RISK-001 — schema integrity):

```php
Schema::create('role_user', function (Blueprint $table) {
    $table->foreignId('role_id')->constrained()->cascadeOnDelete();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->primary(['role_id', 'user_id']);
});
```

**Rationale**: pivots sin FK permiten IDs huérfanos (R3-014 de la base de conocimiento del sprint 1.2.2-hardening).

---

## Requirement: RBAC-004 — Policies auto-generadas

**SHALL**: el scaffolder genera una Policy por modelo con default-deny:

```php
class AdminPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        return $user->hasRole('super-admin') ? true : null; // super-admin bypass
    }

    public function viewAny(User $user): bool
    {
        return $user->hasAbility('admin.admins.viewAny');
    }

    public function view(User $user, Admin $admin): bool
    {
        return $user->hasAbility('admin.admins.view');
    }
    // ... create, update, delete
}
```

**Convenciones**:
- Ability name = `{scope}.{resource}.{action}` (e.g. `admin.admins.viewAny`).
- `before()` method da bypass a `super-admin` role (estándar de facto).
- Default-deny: si no hay ability explícita, no se permite.

---

## Requirement: RBAC-005 — RbacService helper

**SHALL**: el scaffolder genera `app/Modules/{Name}/Services/RbacService.php`:

```php
class RbacService
{
    public function assignRole(User $user, Role $role): void { ... }
    public function revokeRole(User $user, Role $role): void { ... }
    public function syncAbilities(Role $role, array $abilityNames): void { ... }
    public function userHasAbility(User $user, string $ability): bool { ... }
}
```

**Uso**:
```php
app(RbacService::class)->assignRole($admin, $role);
```

---

## Escenarios

### Scenario 1: Scaffolding básico
```
Given corro `php artisan mk:module Admin --with-rbac`
When el comando termina
Then existen 11+ archivos en app/Modules/Admin/
And las migrations tienen FK constraints
And AdminServiceProvider está auto-registrado en bootstrap/providers.php
```

### Scenario 2: Policy enforcement
```
Given un Admin con role `editor` que tiene ability `admin.posts.view`
And NO tiene ability `admin.posts.delete`
When hace GET /api/admin/posts
Then recibe 200 (puede ver)
When hace DELETE /api/admin/posts/1
Then recibe 403 (no puede eliminar)
```

### Scenario 3: Super-admin bypass
```
Given un Admin con role `super-admin`
When intenta cualquier acción protegida
Then recibe 200 (bypass automático)
```

### Scenario 4: FK integrity
```
Given role_user table con role_id=999 (no existe en roles)
When intento insertar el row
Then la FK constraint rechaza con SQLSTATE 23000
```

---

## Anti-patterns

- ❌ Generar `Admin extends AuthUser` — admin NO es un auth scope. AuthUser es para login. Admin es un user interno.
- ❌ Policies permisivas por default — default-deny.
- ❌ Pivots sin FK — R-RISK-001.
- ❌ Hardcodear nombres `admin`, `role`, `ability` — usar `{{ModuleName}}`.

---

## Cross-references

- SDD: `openspec/changes/2026-06-24-admin-with-rbac/`
- Companion: R-PKG-007 (discover abilities)
- Source: rama RETO huérfana `makromania/260624-0511--admin-module`
- Regla: `~/.makromania/agency/global/rules_orchestration.md` § R-G-033
