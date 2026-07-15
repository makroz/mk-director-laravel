<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Console;

use Mk\Director\Console\Commands\MakeAuthUserCommand;
use Mk\Director\Tests\MkLaravelTestCase;

/**
 * Source-parsing + reflection-based regression tests for
 * change `2026-07-15-profile-edit-password-otp`, Phase 3 (PR3):
 * ADR-5 (profile widening: email/phone/avatar on `updateProfile`) +
 * ADR-6 (scaffolder safety: additive stub pins + snapshot/regression tests).
 *
 * Pattern: reflection-invoke `buildUpdateProfileMethod()` directly with
 * representative args and assert on the returned PHP source string — this
 * is the package's own established "reflection-based isolation" convention
 * (see `AuthUserFeedbackAuditTest.php` docblock: "No se ejecuta el command
 * end-to-end ... En su lugar se invocan métodos protected del command via
 * Reflection"), NOT a fresh test pattern invented for this change.
 *
 * Covers:
 *   (a) new profile fields (avatar/email/phone) generate correctly when
 *       opted in (a `:file` field present in --profile-fields).
 *   (b) existing generated output unchanged when NOT opted in (no file
 *       field) — no FileStoragePlugin wiring emitted, no drift.
 *   (c) the real bug this phase fixes: `detectFileFields()` fed the FLAT
 *       `$profileFields` map (`key => 'file'` string) at the model-level
 *       call site, so `getAvatarUrlAttribute()` was NEVER emitted — closes
 *       the FEEDBACK10 gap referenced in design ADR-5/ADR-6.
 *   (d) Phase 2 OTP routes remain pinned (no regression from this phase).
 *
 * Spec: `.makromania/projects/mk-director/openspec/changes/2026-07-15-profile-edit-password-otp/design.md` ADR-5, ADR-6.
 */
uses(MkLaravelTestCase::class);

function widePackageRoot(): string
{
    return dirname(__DIR__, 3);
}

function wideCommandSource(): string
{
    $path = widePackageRoot().'/src/Console/Commands/MakeAuthUserCommand.php';
    expect(file_exists($path))->toBeTrue();

    return (string) file_get_contents($path);
}

function wideInvoke(string $method, array $args): mixed
{
    $command = new MakeAuthUserCommand;
    $ref = new \ReflectionMethod($command, $method);
    $ref->setAccessible(true);

    return $ref->invoke($command, ...$args);
}

// ── (a) opted-in: representative scope with avatar:file + email/phone ──────

test('ADR-5: buildUpdateProfileMethod() widens avatar (file field) to real file/image validation + FileStoragePlugin wiring', function () {
    $profileFields = [
        'name' => ['type' => 'string', 'unique' => false],
        'email' => ['type' => 'string', 'unique' => false],
        'phone' => ['type' => 'string', 'unique' => false],
        'avatar' => ['type' => 'file', 'unique' => false],
    ];

    $out = wideInvoke('buildUpdateProfileMethod', [
        'Admin', 'admin', 'email', $profileFields, [], ['avatar'], 'admins',
    ]);

    // Avatar: sometimes + real file/image validation (NOT the generic
    // ['nullable', 'string'] shared with register()/CRUD store).
    expect($out)->toContain("'avatar' => ['sometimes', 'file', 'image', 'max:4096']");

    // FileStoragePlugin wiring is emitted (updateProfile() never called
    // PluginManager pre-ADR-5 — this endpoint isn't a CRUDSmart controller).
    expect($out)->toContain('app(\\Mk\\Director\\Managers\\PluginManager::class)');
    expect($out)->toContain('->fireBeforeSave($request, $data, \'update\')');
    expect($out)->toContain("'avatar' => 'avatar'"); // identity map (post-R-PKG-050 convention)
    expect($out)->toContain("'path' => 'uploads/admin'");
});

test('ADR-5: buildUpdateProfileMethod() widens email to sometimes/required/email + unique-ignoring-self', function () {
    $profileFields = [
        'name' => ['type' => 'string', 'unique' => false],
        'email' => ['type' => 'string', 'unique' => false],
        'phone' => ['type' => 'string', 'unique' => false],
    ];

    $out = wideInvoke('buildUpdateProfileMethod', [
        'Admin', 'admin', 'email', $profileFields, [], [], 'admins',
    ]);

    // Real email format check + uniqueness ignoring the current row — closes
    // the gap where a duplicate email on update caused a 500 DB integrity
    // violation instead of a 422 ValidationException.
    expect($out)->toContain(
        "'email' => ['sometimes', 'nullable', 'email', 'max:255', \\Illuminate\\Validation\\Rule::unique('admins', 'email')->ignore(\$user->getKey())]"
    );
});

test('ADR-5: buildUpdateProfileMethod() keeps name required-when-present and phone sometimes/nullable (exact ADR-5 literal rules)', function () {
    $profileFields = [
        'name' => ['type' => 'string', 'unique' => false],
        'email' => ['type' => 'string', 'unique' => false],
        'phone' => ['type' => 'string', 'unique' => false],
    ];

    $out = wideInvoke('buildUpdateProfileMethod', [
        'Admin', 'admin', 'email', $profileFields, [], [], 'admins',
    ]);

    expect($out)->toContain("'name' => ['sometimes', 'required', 'string', 'max:255']");
    expect($out)->toContain("'phone' => ['sometimes', 'nullable', 'string', 'max:255']");
});

test('ADR-5: buildUpdateProfileMethod() returns via me() for the canonical envelope (standardized, no bespoke only())', function () {
    $out = wideInvoke('buildUpdateProfileMethod', [
        'Admin', 'admin', 'email', ['name' => ['type' => 'string', 'unique' => false]], [], [], 'admins',
    ]);

    expect($out)->toContain('return $this->me($request);');
    expect($out)->not->toContain('->only([');
    expect($out)->not->toContain("'Perfil actualizado.'");
});

// ── (b) NOT opted in: no file field → no plugin wiring, no drift ───────────

test('ADR-6: buildUpdateProfileMethod() emits NO FileStoragePlugin wiring when the scope has no :file profile field', function () {
    $profileFields = [
        'name' => ['type' => 'string', 'unique' => false],
        'email' => ['type' => 'string', 'unique' => false],
        'phone' => ['type' => 'string', 'unique' => false],
    ];

    $out = wideInvoke('buildUpdateProfileMethod', [
        'Member', 'member', 'email', $profileFields, [], [], 'members',
    ]);

    expect($out)->not->toContain('PluginManager');
    expect($out)->not->toContain('fireBeforeSave');
    expect($out)->not->toContain("'file'");
    expect($out)->not->toContain("'image'");

    // Still valid, still returns via me() — the widened name/email/phone
    // rules are NOT gated behind file-field opt-in (they're always-present
    // defaults per `defaultProfileFields()`), only the plugin wiring is.
    expect($out)->toContain('public function updateProfile(');
    expect($out)->toContain('return $this->me($request);');
});

test('ADR-6 regression guard: source calls buildUpdateProfileMethod() with the widened signature (scope, scopeLower, loginField, profileFieldsRaw, requiredFields, fileFieldNames, scopePlural)', function () {
    $source = wideCommandSource();

    expect($source)->toMatch(
        '/protected function buildUpdateProfileMethod\(\s*string \$scope,\s*string \$scopeLower,\s*string \$loginField,\s*array \$profileFields,\s*array \$requiredFields,\s*array \$fileFieldNames,\s*string \$scopePlural,?\s*\): string/'
    );

    // Call site passes $profileFieldsRaw (meta arrays), NOT the exported
    // rules string — register() keeps using $profileRulesPhp untouched.
    expect($source)->toMatch(
        '/\$this->buildUpdateProfileMethod\(\s*\$scope,\s*\$scopeLower,\s*\$loginField,\s*\$profileFieldsRaw,\s*\$requiredFields,\s*\$fileFieldNames,\s*\$scopePlural,?\s*\)/'
    );
});

// ── (c) the real bug this phase fixes: detectFileFields() fed flat map ─────

test('ADR-5/ADR-6 BUG FIX regression: detectFileFields() called with $profileFieldsRaw (meta arrays), NOT the flat $profileFields map, at the model-placeholder call site', function () {
    $source = wideCommandSource();

    // The single, correct computation (feeds both the model placeholders
    // AND buildUpdateProfileMethod()).
    expect($source)->toContain('$fileFieldNames = $this->detectFileFields($profileFieldsRaw);');

    // The `detectFileFields($profileFields)` CODE call (not comments) must
    // appear EXACTLY ONCE in the whole file — inside `generateCrudPack()`,
    // where its OWN local parameter (also named $profileFields) legitimately
    // receives meta arrays (the CRUD pack call site was never buggy; only
    // the top-level handle() call site was, because it shadowed the name
    // with the flat legacy map).
    preg_match_all('/\$fileFieldNames = \$this->detectFileFields\(\$profileFields\);/', $source, $matches);
    expect($matches[0])->toHaveCount(1);
});

test('ADR-5/ADR-6 BUG FIX regression: detectFileFields() returns [] when fed the flat legacy map (documents WHY the bug existed)', function () {
    // Flat legacy shape: key => type string (what handle() built pre-fix
    // for its OWN $profileFields variable at line ~339).
    $flatMap = ['avatar' => 'file'];

    $out = wideInvoke('detectFileFields', [$flatMap]);

    expect($out)->toBe([]); // proves the flat map can never be detected correctly.
});

test('ADR-5/ADR-6 BUG FIX regression: detectFileFields() correctly detects :file fields when fed $profileFieldsRaw meta arrays', function () {
    $rawMap = [
        'avatar' => ['type' => 'file', 'unique' => false],
        'phone' => ['type' => 'string', 'unique' => false],
    ];

    $out = wideInvoke('detectFileFields', [$rawMap]);

    expect($out)->toBe(['avatar']);
});

test('ADR-5/ADR-6: end-to-end wiring — correctly-detected file field feeds buildFileFieldsAccessors() and emits getAvatarUrlAttribute (closes the FEEDBACK10 gap)', function () {
    $rawMap = ['avatar' => ['type' => 'file', 'unique' => false]];

    $fileFieldNames = wideInvoke('detectFileFields', [$rawMap]);
    $accessors = wideInvoke('buildFileFieldsAccessors', [$fileFieldNames]);

    expect($fileFieldNames)->toBe(['avatar']);
    expect($accessors)->toContain('public function getAvatarUrlAttribute(): ?string');
    expect($accessors)->toContain('Storage::url($this->avatar)');
});

// ── (d) Phase 2 OTP routes remain intact (no regression from this phase) ───

test('Phase 2 regression guard: OTP password/code routes + password/change remain pinned in the routes stub after Phase 3 changes', function () {
    $stub = (string) file_get_contents(widePackageRoot().'/src/Stubs/auth-user.routes.stub');

    expect($stub)->toContain("password/code/request");
    expect($stub)->toContain("password/code/confirm");
    expect($stub)->toContain("password/change");
});

test('Phase 2 regression guard: updateProfileRoute placeholder (PATCH me) is still pinned unconditionally in the routes stub', function () {
    $stub = (string) file_get_contents(widePackageRoot().'/src/Stubs/auth-user.routes.stub');

    expect($stub)->toContain('{{updateProfileRoute}}');
});
