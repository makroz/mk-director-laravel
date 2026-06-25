# Spec — DiscoverAbilitiesCommand (R-PKG-007)

**Versión**: 0.1 (draft pre-triage)
**Fecha**: 2026-06-24
**Rule**: R-G-033-A (criterio genérico)
**SDD**: `openspec/changes/2026-06-24-discover-abilities-to-core/`

---

## Requirement: DA-001 — Comando CLI para auto-descubrir abilities

**SHALL**: El paquete provee `php artisan mk:discover-abilities` que escanea controllers y reporta abilities declaradas via atributo PHP o docblock.

**Casos de uso**:
1. Dry-run durante desarrollo: ver qué abilities se declararían sin tocar la DB.
2. Aplicar en setup inicial: poblar la tabla `abilities` con las abilities declaradas.
3. Sync post-deploy: detectar abilities nuevas o removidas y aplicar diff.

**Interfaz**:
```bash
php artisan mk:discover-abilities [options]
  --module=*       # scope: solo este módulo (multiple)
  --dry-run        # mostrar sin aplicar
  --json           # output en formato JSON (CI-friendly)
  --force          # aplicar sin confirmación
  --path=          # path custom (default: app/Modules)
```

---

## Requirement: DA-002 — Atributo PHP #[Ability]

**SHALL**: Cualquier método de controller puede declarar su ability via atributo PHP:

```php
use Mk\Director\Auth\Attributes\Ability;

class AdminController extends SmartController
{
    #[Ability('admin.users.list', 'Listar usuarios admin')]
    public function index(Request $request) { ... }

    #[Ability('admin.users.create', 'Crear usuario admin')]
    public function store(StoreAdminRequest $request) { ... }
}
```

**Parsing**: el comando escanea todos los métodos públicos de controllers bajo el path configurado y lee el atributo si existe.

---

## Requirement: DA-003 — Docblock fallback

**SHALL** (compatible con PHP < 8.4 o sin usar atributos): se acepta docblock `@mk-ability`:

```php
/**
 * @mk-ability admin.users.list|Listar usuarios admin
 */
public function index(Request $request) { ... }
```

**Prioridad**: atributo PHP gana sobre docblock si ambos están presentes.

---

## Requirement: DA-004 — Path y namespace configurables

**SHALL**: el path default es `app/Modules` y el namespace default es `App\Modules`. Ambos configurables via `config/mk_director.php`:

```php
'abilities' => [
    'discovery' => [
        'enabled' => true,
        'paths' => ['app/Modules'],
        'namespaces' => ['App\\Modules'],
        'auto_apply_on_boot' => false,
    ],
],
```

**Rationale**: no asumir la estructura de RETO. Otros proyectos pueden tener `src/Modules`, `modules/`, etc.

---

## Escenarios

### Scenario 1: Dry-run en sandbox

```
Given un sandbox con AdminController que tiene #[Ability('admin.users.list', '...')] en index()
When corro `php artisan mk:discover-abilities --dry-run`
Then el output muestra una tabla con las abilities encontradas
And NO se modifica la DB
And exit code es 0
```

### Scenario 2: Aplicar abilities nuevas

```
Given la tabla `abilities` tiene 5 registros
And AdminController declara 7 abilities (2 nuevas)
When corro `php artisan mk:discover-abilities --force`
Then se insertan 2 registros nuevos en `abilities`
And las 5 existentes se mantienen (idempotente)
And exit code es 0
```

### Scenario 3: Detección de abilities removidas

```
Given la tabla `abilities` tiene 7 registros
And AdminController declara solo 5 abilities (2 fueron removidas del código)
When corro `php artisan mk:discover-abilities --dry-run`
Then el output marca 2 abilities como "ORPHAN" (existen en DB pero no en código)
And NO las elimina (el dev decide qué hacer)
```

### Scenario 4: Path custom

```
Given un proyecto con módulos en `src/Modules/` en vez de `app/Modules/`
And `config/mk_director.php` tiene `'paths' => ['src/Modules']`
When corro `php artisan mk:discover-abilities`
Then el comando escanea `src/Modules/` correctamente
And encuentra las abilities declaradas
```

---

## Anti-patterns

- ❌ Hardcodear path `app/Modules/{Name}/Http/Controllers/` — debe ser configurable.
- ❌ Asumir namespace `App\Modules\*` — configurable.
- ❌ Asumir nombre de tabla `abilities` — leer del modelo `Ability::class` configurado.
- ❌ Eliminar abilities automáticamente al detectar orphans — el dev decide.

---

## Cross-references

- SDD: `openspec/changes/2026-06-24-discover-abilities-to-core/`
- Source (RETO): `projects/reto/reto-api/app/Modules/Admin/Console/Commands/DiscoverAbilitiesCommand.php`
- Regla: `~/.makromania/agency/global/rules_orchestration.md` § R-G-033
