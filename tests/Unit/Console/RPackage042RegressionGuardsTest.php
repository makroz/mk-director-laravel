<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Console;

use PHPUnit\Framework\TestCase;

/**
 * R-PKG-042 FASE18-05 + FASE18-07 — source-parsing tests duros para los
 * stubs scaffoldeados por `mk:make:auth-user`.
 *
 * Estos tests pinean los contratos críticos pineados por R-PKG-042:
 *   1. **FASE18-05**: `admin-resource.stub` NO contiene `direct_abilities`
 *      (regression guard contra el bug class de over-emission que el
 *      HALLAZGO-NEW-FASE18-05 detectó).
 *   2. **FASE18-07**: `cors.php.stub` existe, tiene `paths: ['api/*']`
 *      y `supports_credentials` (lo que el scaffolder pinea en
 *      `config/cors.php` del consumer).
 *   3. **FASE18-07**: `mk_director.php` config tiene `frontend.frontend_origins`
 *      con default dev-friendly (http://localhost:3000, http://127.0.0.1:3000).
 *   4. **FASE18-05**: `me-permissions-controller.stub` existe y tiene
 *      `direct_abilities`, `effective_abilities`, `roles_with_abilities`
 *      en el return array (consumido por el endpoint opt-in).
 *   5. **FASE17-02**: `MkAuthenticate.php` middleware pinea el envelope
 *      canónico R-PKG-024 en el 401 response.
 *
 * **Por qué source-parsing y no e2e**: HALLAZGO-NEW-03 cross-project lesson
 * (pine pineado en HALLAZGO-NEW-FASE18-01). Source-parsing pinea la INTENCIÓN
 * del fix (estructura OK), no la EFECTIVIDAD (runtime funciona). Para validar
 * efectividad end-to-end, ver R-PKG-041 pre-flight e2e scaffolder.
 *
 * Los source-parsing tests pinean que el código pineado por R-PKG-042 no
 * se revierta silenciosamente en un próximo refactor. Si un dev borra
 * `'supports_credentials' =>` del stub `cors.php.stub` (creyendo que es
 * opcional), este test falla en CI.
 */
class RPackage042RegressionGuardsTest extends TestCase
{
    private const STUBS_DIR = __DIR__ . '/../../../src/Stubs';
    private const CONFIG_FILE = __DIR__ . '/../../../config/mk_director.php';
    private const MIDDLEWARE_FILE = __DIR__ . '/../../../src/Auth/Middleware/MkAuthenticate.php';

    // ─────────────────────────────────────────────────────────────────────
    // FASE18-05 — direct_abilities removal
    // ─────────────────────────────────────────────────────────────────────

    public function test_admin_resource_stub_does_NOT_pinear_direct_abilities(): void
    {
        $stubPath = self::STUBS_DIR . '/auth-user/admin-resource.stub';
        $this->assertFileExists($stubPath, "Stub not found: {$stubPath}");

        $content = file_get_contents($stubPath);

        // Regression guard: el campo `'direct_abilities' =>` se pineaba en el
        // return array del Resource y duplicaba `'abilities' =>` (que ya es
        // la unión efectiva via getEffectiveAbilities()). Si un refactor
        // futuro lo agrega de nuevo, este test falla.
        //
        // NOTA: el string "direct_abilities" puede aparecer en COMMENTS del
        // stub (documentación del HALLAZGO-NEW-FASE18-05) — eso es OK. Lo
        // que NO debe aparecer es la línea de código `'direct_abilities' =>`
        // (key de array de retorno) ni `$this->directAbilities->pluck(...)`
        // (over-emission del side effect de cargar directAbilities en
        // AdminResource sin haberlo whenLoaded).
        $this->assertStringNotContainsString(
            "'direct_abilities' =>",
            $content,
            "FASE18-05 regression: `'direct_abilities' =>` no debe pinearse en admin-resource.stub. " .
            "El campo se removió del default Resource (over-emission cuando un admin tiene " .
            "grants directos que coinciden con los del role). Si tu UI necesita el desglose, " .
            "pinear el endpoint opt-in con `mk:make:auth-user --with-permissions-endpoint`."
        );
        $this->assertStringNotContainsString(
            '$this->directAbilities',
            $content,
            "FASE18-05 regression: \$this->directAbilities no debe pinearse en admin-resource.stub. " .
            "El Resource default ya no expone direct abilities — esa lógica se movió a " .
            "MePermissionsController (endpoint opt-in)."
        );
    }

    public function test_me_permissions_controller_stub_exists_and_returns_breakdown(): void
    {
        $stubPath = self::STUBS_DIR . '/auth-user/me-permissions-controller.stub';
        $this->assertFileExists($stubPath, "Stub not found: {$stubPath}");

        $content = file_get_contents($stubPath);

        // El endpoint opt-in debe retornar el desglose completo de abilities.
        $this->assertStringContainsString("'direct_abilities'", $content,
            "MePermissionsController stub debe pinear 'direct_abilities' en el response.");
        $this->assertStringContainsString("'effective_abilities'", $content,
            "MePermissionsController stub debe pinear 'effective_abilities' (= getEffectiveAbilities()).");
        $this->assertStringContainsString("'roles_with_abilities'", $content,
            "MePermissionsController stub debe pinear 'roles_with_abilities' (detalle de cada role con abilities).");
    }

    // ─────────────────────────────────────────────────────────────────────
    // FASE18-07 — cors.php stub
    // ─────────────────────────────────────────────────────────────────────

    public function test_cors_stub_exists_and_has_api_paths(): void
    {
        $stubPath = self::STUBS_DIR . '/cors.php.stub';
        $this->assertFileExists($stubPath, "Stub not found: {$stubPath}");

        $content = file_get_contents($stubPath);

        $this->assertStringContainsString("'api/*'", $content,
            "cors.php.stub debe pinear 'api/*' en `paths` (endpoints del paquete).");
        $this->assertStringContainsString("'supports_credentials'", $content,
            "cors.php.stub debe pinear 'supports_credentials' (Sanctum SPA flow).");
        $this->assertStringContainsString("mk_director.frontend.frontend_origins", $content,
            "cors.php.stub debe leer 'allowed_origins' desde config(mk_director.frontend.frontend_origins).");
    }

    public function test_cors_stub_has_force_cors_path_for_extra_paths(): void
    {
        $stubPath = self::STUBS_DIR . '/cors.php.stub';
        $this->assertFileExists($stubPath, "Stub not found: {$stubPath}");
        $content = file_get_contents($stubPath);

        // El stub tiene un placeholder {{extraCorsPaths}} que el scaffolder
        // reemplaza según el flag --with-auth-rbac. Si un refactor elimina
        // este placeholder, el scaffolder pine config/cors.php sin el
        // 'sanctum/csrf-cookie' cuando se usa Sanctum SPA.
        $this->assertStringContainsString('{{extraCorsPaths}}', $content,
            "cors.php.stub debe tener un placeholder {{extraCorsPaths}} que el scaffolder " .
            "reemplaza según --with-auth-rbac (suma 'sanctum/csrf-cookie' cuando aplica).");
    }

    public function test_mk_director_config_has_frontend_origins_default(): void
    {
        $this->assertFileExists(self::CONFIG_FILE);

        $content = file_get_contents(self::CONFIG_FILE);

        $this->assertStringContainsString("'frontend'", $content,
            "config/mk_director.php debe tener una sección 'frontend' (R-PKG-042 FASE18-07).");
        $this->assertStringContainsString("'frontend_origins'", $content,
            "config/mk_director.php debe tener la key 'frontend.frontend_origins'.");
        $this->assertStringContainsString("http://localhost:3000", $content,
            "Default dev-friendly: http://localhost:3000 (Next.js dev server).");
        $this->assertStringContainsString("http://127.0.0.1:3000", $content,
            "Default dev-friendly: http://127.0.0.1:3000 (loopback).");
        $this->assertStringContainsString("FRONTEND_ORIGINS", $content,
            "config debe leer de env var FRONTEND_ORIGINS (override para prod).");
    }

    // ─────────────────────────────────────────────────────────────────────
    // FASE17-02 — MkAuthenticate envelope
    // ─────────────────────────────────────────────────────────────────────

    public function test_mk_authenticate_middleware_pineia_envelope_canonico(): void
    {
        $this->assertFileExists(self::MIDDLEWARE_FILE);

        $content = file_get_contents(self::MIDDLEWARE_FILE);

        // El middleware debe pinear 'success', 'message', 'data', '__extraData',
        // 'debugMsg' (envelope canónico R-PKG-024).
        $this->assertStringContainsString("'success'", $content);
        $this->assertStringContainsString("'message'", $content);
        $this->assertStringContainsString("'data'", $content);
        $this->assertStringContainsString("'__extraData'", $content);
        $this->assertStringContainsString("'debugMsg'", $content);
        $this->assertStringContainsString("'auth_scope'", $content,
            "Envelope debe pinear 'auth_scope' en __extraData (cross-stack contract).");
    }
}
