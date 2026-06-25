# R-PKG-012 tasks

## T0 — SDD (status: done en esta sesión de setup)

- [x] proposal.md (sprint overview + scope + R-G-033-A + riesgos)
- [x] state.yaml v1 (status: design, R-G-033-A ✅, riesgos pineados)
- [x] tasks.md (este archivo)
- [x] specs/profile-fields-types.md (scenarios)
- [x] design.md (ADRs D1-D10 + tabla de tipos)

## T1 — Implementation (status: open, próxima sesión)

### T1.1 — Command: parser extendido
- [ ] Refactor `resolveProfileFields()` para split `key[:type]` por `:`
- [ ] Validar tipo contra lista cerrada (8 tipos)
- [ ] Default `string` cuando no se especifica tipo (BC)
- [ ] Manejo de error: tipo desconocido / tipo con case incorrecto

### T1.2 — Resolver de tipos
- [ ] Nueva constante o method: `getProfileFieldTypeConfig(string $type): array`
- [ ] Devuelve `{column_call, cast, validation_rule}`
- [ ] Table-driven, fácil de extender

### T1.3 — Replacements construction en handle()
- [ ] `buildProfileFieldsReplacements()` actualizado para usar type config
- [ ] `{{profileFieldsColumns}}` por tipo (string, integer, date, etc.)
- [ ] `{{profileFieldsCastEntries}}` por tipo (integer, boolean, date, datetime, array)
- [ ] `{{profileFieldsValidationRules}}` por tipo (registered como JSON PHP)

### T1.4 — Stubs updates
- [ ] `auth-user.migration.stub`: usar `{{profileFieldsColumns}}` con tipos
- [ ] `auth-user.model.stub`: agregar `{{profileFieldsCastEntries}}` después de email_verified_at
- [ ] `auth-user.auth-controller.stub`: register() + updateProfile() usan `{{profileFieldsValidationRules}}`

### T1.5 — Tests Pest
- [ ] `tests/Unit/Console/MakeAuthUserProfileFieldsTypesTest.php` (NEW, ~20 tests source-parsing)
  - 8 tipos soportados (string, text, int, decimal, bool, date, datetime, json)
  - Default `string` cuando no se especifica tipo
  - Tipo desconocido → fail-fast
  - BC: --profile-fields=name,dni (sin tipos) sigue funcionando
  - Ortogonalidad con --login-field, --with-auth-rbac, --verify-email
- [ ] `tests/Unit/Auth/AuthUserProfileFieldsTypesTest.php` (NEW, ~8 tests encapsulación)
  - Verificar tipos generados por scope (Admin con date, Member con json)
  - Cast entries presentes en model stub
  - Validation rules en controller stub

### T1.6 — R-G-032 sync packagist-side
- [ ] `CHANGELOG.md` — sección [1.6.0-rc1] con Added/Compatibility/Anti-patterns/Spec
- [ ] `DEVELOPER_GUIDE.md` — § 3.11 Profile fields with custom types (tabla de 8 tipos)
- [ ] `README.md` — row actualizada en Comandos Artisan (--profile-fields=name:string,...)

### T1.7 — R-G-032 sync monorepo-side
- [ ] `projects/mk-director/docs/guides/GETTING_STARTED.md` — Step 12 (opcional, custom types)
- [ ] `projects/mk-director/docs/guides/AUTH.md` — callout sobre tipos custom
- [ ] `projects/mk-director/docs/API_REFERENCE_LARAVEL.md` — sección --profile-fields con tipos

### T1.8 — Skill reference 14
- [ ] `.makromania/agency/skills/mk-director-laravel/SKILL.md` — index update
- [ ] `.makromania/agency/skills/mk-director-laravel/references/14-profile-fields-types.md` — NEW (~350 líneas)

## T2 — PRs coordinados (status: open, próxima sesión)

- [ ] PR #1: makroz/mk-director-laravel — feat + tests + R-G-032 locations 1-3, 4, 4b
- [ ] PR #2: makroz/MK-Director — monorepo docs sync locations 6-8
- [ ] PR #3: mariogfos/humandirector (clonado en /tmp/) — skill reference 14 + SKILL.md index

## T3 — Cierre (status: open, próxima sesión)

- [ ] 3 PRs squash-merged por Mario
- [ ] Tag v1.6.0-rc1 contra `origin/dev` HEAD post-merge
- [ ] `sleep 45 && composer show makroz/director-laravel --all | head -1` verificar Packagist
- [ ] state.yaml v3 → status: closed
- [ ] R-RET-001 state.yaml → marcar R-PKG-012 ✅ en depends_on_progress
- [ ] Memory entry tipo: release
- [ ] `mavis cron delete main r-pkg-012-pr-watch`
- [ ] Telegram a Mario: "R-PKG-012 cerrado, v1.6.0-rc1 publicado. Lote ahora 6 RCs. ¿Aprobás GA v1.5.0 + bumpear RETO, o seguimos acumulando?"