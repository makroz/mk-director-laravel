# R-RET-001 — Tasks

**Status**: BLOCKED — espera R-PKG-007/008/009/010.

---

## Track 0 — Inventario (ESTE sprint, inmediato)

### T0.1 — Clonar y leer rama huérfana
```bash
git -C projects/reto/reto-api fetch origin
git -C projects/reto/reto-api checkout makromania/260624-0511--admin-module
```
**Output**: archivos en working tree.

### T0.2 — Catalogar archivos
**Output**: `inventory.md` (en este SDD change) con:
- Tabla de archivos (path, LOC, tier, acción).
- Identificar lógica RETO-only que vale la pena portar.
- Identificar assumptions del paquete v1.3.0 que ya no aplican.

### T0.3 — Documentar learnings
**Output**: `learnings.md` (en este SDD change) con:
- Patrones que RETO descubrió que el paquete no proveía (ahora son R-PKG-007/008/009/010).
- Bugs o gaps encontrados.
- Sugerencias para futuros sprints del paquete.

### T0.4 — Cerrar rama huérfana (DESPUÉS del inventario)
```bash
# NO MERGEAR. Solo cerrar localmente hasta que el retrofit esté listo.
git -C projects/reto/reto-api branch -D makromania/260624-0511--admin-module
```
**Output**: rama borrada local. El inventario queda en SDD change.

---

## Track 1 — Espera (post-v1.5.0 publish)

### T1.1 — Verificar v1.5.0 publicada
```bash
composer show makroz/director-laravel  # verificar ^1.5.0 disponible
```

### T1.2 — Notificación de Mario
Confirmar con Mario que v1.5.0 está lista para adopción RETO.

---

## Track 2 — Retrofit (post-v1.5.0)

### T2.1 — Releer skills actualizadas
- `~/.makromania/agency/skills/mk-director-laravel/SKILL.md` + 8+ references.
- 6+ nuevos references según R-PKG-007/008/009/010.

### T2.2 — Crear rama limpia
```bash
git -C projects/reto/reto-api checkout -b makromania/260624-XXXX--reto-admin-with-v150 origin/dev
```

### T2.3 — Bumpear paquete
```bash
cd projects/reto/reto-api
composer require makroz/director-laravel:^1.5.0
```

### T2.4 — Borrar módulo Admin viejo
```bash
rm -rf app/Modules/Admin
# Revertir config snippets en config/auth.php, config/mk_director.php
```

### T2.5 — Regenerar con v1.5.0
```bash
php artisan mk:module Admin --with-rbac
php artisan mk:make:auth-user Admin --login-field=ci --with-auth-rbac
```

### T2.6 — Portar lógica RETO-only
- Profile fields en Admin Model (override del generado).
- Lógica de negocio en AdminService.
- Custom validators si los hay.

### T2.7 — Regenerar tests
- Re-correr suite Feature, ajustar a nueva estructura.
- Agregar tests para nuevos endpoints (RBAC checks, audit log).

### T2.8 — Verificar MME
```bash
php artisan mk:lint:boundaries
```

### T2.9 — Discover abilities
```bash
php artisan mk:discover-abilities --module=Admin --dry-run
# Verificar que encuentra las abilities esperadas.
php artisan mk:discover-abilities --module=Admin --force
```

### T2.10 — Smoke test end-to-end
- `php artisan migrate`
- `php artisan serve`
- curl a /api/admin/auth/login con `ci` + password.
- curl a /api/admin/auth/me con Bearer token.

### T2.11 — Pest tests
```bash
./vendor/bin/pest --filter=Admin
```
Todos verdes.

### T2.12 — PR a dev
```bash
git push origin makromania/260624-XXXX--reto-admin-with-v150
gh pr create --base dev --title "feat(admin): RETO Admin module on v1.5.0+"
```

---

## Definition of Done

- [ ] T0.1..T0.4: inventario completo + learnings + rama cerrada (sprint actual).
- [ ] T1.1..T1.2: v1.5.0 publicada + Mario notificado.
- [ ] T2.1..T2.12: retrofit completo con smoke test + PR mergeado.
- [ ] RETO corre en v1.5.0+ con módulo Admin funcional.
