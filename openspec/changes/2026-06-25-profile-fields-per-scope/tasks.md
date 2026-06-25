# R-PKG-011 tasks

## T0 — SDD (status: open)

- [x] proposal.md (este sprint)
- [x] state.yaml v1 (status: design)
- [x] tasks.md (este archivo)
- [x] specs/profile-fields.md (scenarios)
- [x] design.md (ADRs D1-D10)

## T1 — Implementation (status: open)

### T1.1 — Command signature (flag opt-in)
- [ ] Agregar `--profile-fields=` a `$signature` con default `null` (no fields)
- [ ] Agregar `--verify-email` (boolean) a `$signature` con default `false`
- [ ] Actualizar `$description` con ejemplos de uso combinados

### T1.2 — Resolvers (validación)
- [ ] `resolveProfileFields(string $raw): ?array` — split CSV, validar identifier, fail-fast en duplicados
- [ ] `resolveVerifyEmail(bool $raw): bool` — boolean cast (Laravel ya lo hace)

### T1.3 — Replacements construction en handle()
- [ ] `$profileFieldsReplacements` array (conditional based on profileFields)
- [ ] `$verifyEmailReplacements` array (conditional based on verifyEmail)
- [ ] `array_merge` con `$loginFieldReplacements` y `$rbacReplacements` → `$extraReplacements`

### T1.4 — auth-user.model.stub updates
- [ ] `{{profileFieldsFillableEntries}}` — conditional entries para `$fillable`
- [ ] `{{profileFieldsCastEntries}}` — conditional entries para `$casts`
- [ ] `{{profileFieldsDocblock}}` — conditional docblock entries
- [ ] `{{emailVerifiedAtCastEntry}}` ya existe (R-PKG-009) — verificar interacción

### T1.5 — auth-user.migration.stub updates
- [ ] `{{profileFieldsColumns}}` — conditional columns en tabla del scope
- [ ] `{{emailVerifiedAtColumn}}` ya existe (R-PKG-009) — refactorizar para que dependa de `--verify-email` O `--login-field=email`

### T1.6 — auth-user.auth-controller.stub updates
- [ ] Endpoint `PATCH /me` con profile fields validation + update
- [ ] Endpoint `POST /register` con profile fields validation + create
- [ ] Endpoint `GET /email/verify/{id}/{hash}` (conditional `--verify-email`)
- [ ] Endpoint `POST /email/resend` (conditional `--verify-email`)
- [ ] Notification dispatch en register (conditional)
- [ ] Verificar que MustVerifyEmail interface se sigue manejando correctamente

### T1.7 — auth-user.routes.stub updates
- [ ] `Route::patch('me', ...)` agregar en middleware group
- [ ] `Route::post('register', ...)` agregar en públicas (sin throttle por default)
- [ ] `{{emailVerifyRoutes}}` conditional — agrega verify + resend routes
- [ ] `Route::middleware('verified')` opcional en grupo protegido (conditional)

### T1.8 — Tests Pest
- [ ] `tests/Unit/Console/MakeAuthUserProfileFieldsTest.php` (source-parsing)
  - Signature includes --profile-fields
  - Signature includes --verify-email
  - resolveProfileFields valida input
  - resolveProfileFields rechaza duplicados
  - handle() construye profileFieldsReplacements correctamente
  - handle() construye verifyEmailReplacements correctamente
  - BC: default sin flag = idéntico a v1.5.0-rc4
  - Ortogonalidad: 4 flags combinables
- [ ] `tests/Unit/Auth/AuthUserProfileFieldsTest.php` (encapsulación)
  - Mock 2 scopes (Admin + Member) con --profile-fields distintos
  - Verificar que tabla `admins` tiene `dni` pero NO `phone`
  - Verificar que tabla `members` tiene `phone` pero NO `dni`
  - Verificar que Admin::$fillable no incluye `phone`
  - Verificar que Member::$fillable no incluye `dni`
- [ ] `tests/Unit/Console/MakeAuthUserVerifyEmailTest.php` (source-parsing)
  - Signature includes --verify-email
  - email_verified_at column condicional
  - Cast email_verified_at condicional
  - Endpoints /email/verify y /email/resend condicionales

### T1.9 — R-G-032 sync (no negociable)

**Packagist-side (locations 1-3):**
- [ ] `CHANGELOG.md` — sección [1.5.0-rc5] con Added/Compatibility/Examples/Spec
- [ ] `DEVELOPER_GUIDE.md` — § 3.9 Profile fields + § 3.10 Email verification
- [ ] `README.md` — Comandos Artisan table con --profile-fields row

**Skill reference 13 (locations 4 + 4b):**
- [ ] `.makromania/agency/skills/mk-director-laravel/SKILL.md` — index update
- [ ] `.makromania/agency/skills/mk-director-laravel/references/13-profile-fields-per-scope.md` — NEW (~350 líneas)

**Monorepo docs (locations 6-8):**
- [ ] `projects/mk-director/docs/guides/GETTING_STARTED.md` — sección "Profile fields per-scope" con ejemplos Bolivia
- [ ] `projects/mk-director/docs/guides/AUTH.md` — callout sobre --profile-fields y --verify-email
- [ ] `projects/mk-director/docs/API_REFERENCE_LARAVEL.md` — sección mk:make:auth-user --profile-fields + --verify-email

**Verified no change needed:**
- `mk-director-core/SKILL.md` — R-PKG-011 es Laravel-only, no toca contratos TS
- `mk-director-web/SKILL.md` — idem
- `mk-director-mobile/SKILL.md` — idem
- `projects/mk-director/CHANGELOG.md` — DEFERRED, se agrupa con v1.5.0 GA per RELEASE_AT_END
- `projects/mk-director/docs/API_REFERENCE_MOBILE.md` — sin cambio cross-stack
- `projects/mk-director/docs/API_REFERENCE_WEB.md` — sin cambio cross-stack
- `~/.makromania/agency/global/rules_orchestration.md` — R-G-033 ya cubre dogfooding-first
- `projects/mk-director/AGENTS.md` — ya referencia R-PKG-011 en catálogo de sub-changes
- `mariogfos/reto-api` — BC notification via R-RET-001 cuando rc5 → GA

## T2 — PRs coordinados (status: open)

- [ ] PR #1: makroz/mk-director-laravel — feat + tests + R-G-032 locations 1-3, 4, 4b
- [ ] PR #2: makroz/MK-Director — monorepo docs sync locations 6-8
- [ ] PR #3: mariogfos/humandirector (clonado en /tmp/) — skill reference 13 + SKILL.md index

## T3 — Cierre (status: open)

- [ ] 3 PRs squash-merged por Mario
- [ ] Tag v1.5.0-rc5 contra `origin/dev` HEAD post-merge
- [ ] `sleep 45 && composer show makroz/director-laravel --all | head -1` verificar Packagist
- [ ] state.yaml v3 → status: closed
- [ ] R-RET-001 state.yaml v3 → marcar R-PKG-011 ✅ en depends_on_progress
- [ ] Memory entry tipo: release
- [ ] `mavis cron delete main r-pkg-011-pr-watch`
- [ ] Telegram a Mario: "R-PKG-011 cerrado, v1.5.0-rc5 publicado. Lote completo (5 RCs). ¿Aprobás GA v1.5.0 + bumpear RETO?"