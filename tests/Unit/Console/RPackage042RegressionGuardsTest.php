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
    private const STUBS_DIR = __DIR__.'/../../../src/Stubs';

    private const CONFIG_FILE = __DIR__.'/../../../config/mk_director.php';

    private const MIDDLEWARE_FILE = __DIR__.'/../../../src/Auth/Middleware/MkAuthenticate.php';

    // ─────────────────────────────────────────────────────────────────────
    // FASE18-05 — direct_abilities: NUNCA incondicional (solo whenLoaded)
    // ─────────────────────────────────────────────────────────────────────

    public function test_admin_resource_stub_only_emits_direct_abilities_when_loaded(): void
    {
        $stubPath = self::STUBS_DIR.'/auth-user/admin-resource.stub';
        $this->assertFileExists($stubPath, "Stub not found: {$stubPath}");

        $content = file_get_contents($stubPath);

        // HISTORIA — este guard cambió de forma, no de intención:
        //
        // FASE18-05 sacó `direct_abilities` del Resource default porque se
        // emitía SIEMPRE: cargaba la relación como side effect y duplicaba
        // `'abilities' =>` (que ya es la unión efectiva vía
        // getEffectiveAbilities()). El guard original prohibía el string
        // `'direct_abilities' =>` entero.
        //
        // Después, el endpoint unificado de accesos (4cb7f32) lo volvió a
        // agregar A PROPÓSITO — pero envuelto en `whenLoaded`. Motivo (está
        // documentado en el stub): la UI de gestión de accesos necesita
        // distinguir los grants DIRECTOS de los heredados del rol; si
        // pre-llena desde `abilities` mezcla ambos y no puede quitar/limpiar
        // los directos de forma coherente.
        //
        // O sea: el guard original era demasiado ancho. Lo que FASE18-05
        // protegía de verdad no era "el campo no debe existir" — era **"no
        // se emite incondicionalmente"**. `whenLoaded` satisface eso: sin la
        // relación cargada el campo ni aparece, no hay side effect y no hay
        // over-emission. Esa es la invariante que se pinea acá.
        //
        // Si un refactor futuro emite `direct_abilities` sin `whenLoaded`,
        // este test falla — que es exactamente lo que queremos.

        if (! str_contains($content, "'direct_abilities' =>")) {
            // Volver al default sin el campo también es válido.
            $this->assertStringNotContainsString(
                '$this->directAbilities',
                $content,
                'Si el stub no expone `direct_abilities`, tampoco debe tocar $this->directAbilities.'
            );

            return;
        }

        $this->assertMatchesRegularExpression(
            "/'direct_abilities'\s*=>\s*\\\$this->whenLoaded\(\s*'directAbilities'/",
            $content,
            'FASE18-05: si `admin-resource.stub` expone `direct_abilities`, DEBE ser vía '.
            "`\$this->whenLoaded('directAbilities', ...)`. Emitirlo incondicionalmente carga la ".
            "relación como side effect y duplica `'abilities' =>` (que ya es la unión efectiva)."
        );

        // Y `$this->directAbilities` solo puede usarse DENTRO del closure de
        // whenLoaded — nunca suelto en el array de retorno.
        $unguarded = preg_replace(
            "/'direct_abilities'\s*=>\s*\\\$this->whenLoaded\([^\n]*\n/",
            '',
            $content
        );
        $this->assertStringNotContainsString(
            '$this->directAbilities',
            (string) $unguarded,
            'FASE18-05: $this->directAbilities solo puede aparecer dentro del closure de whenLoaded.'
        );
    }

    public function test_me_permissions_controller_stub_exists_and_returns_breakdown(): void
    {
        $stubPath = self::STUBS_DIR.'/auth-user/me-permissions-controller.stub';
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
        $stubPath = self::STUBS_DIR.'/cors.php.stub';
        $this->assertFileExists($stubPath, "Stub not found: {$stubPath}");

        $content = file_get_contents($stubPath);

        $this->assertStringContainsString("'api/*'", $content,
            "cors.php.stub debe pinear 'api/*' en `paths` (endpoints del paquete).");
        $this->assertStringContainsString("'supports_credentials'", $content,
            "cors.php.stub debe pinear 'supports_credentials' (Sanctum SPA flow).");

        // FEEDBACK-2 N3: `allowed_origins` DEBE leer `FRONTEND_ORIGINS` DIRECTO
        // del env, NO vía `config('mk_director...')`. Laravel carga config en
        // orden alfabético → `cors.php` corre antes que `mk_director.php`, el
        // lookup devuelve null y cae al default 3000, rompiendo el login del
        // browser para cualquier origin ≠ 3000. Este guard evita la regresión.
        $this->assertStringContainsString("env('FRONTEND_ORIGINS'", $content,
            "cors.php.stub debe leer 'allowed_origins' desde env('FRONTEND_ORIGINS') DIRECTO (N3: load-order).");
        $this->assertStringNotContainsString("config('mk_director.frontend.frontend_origins'", $content,
            'cors.php.stub NO debe leer origins vía config() (N3: se evalúa antes que mk_director.php → null → default 3000).');
    }

    public function test_cors_stub_has_force_cors_path_for_extra_paths(): void
    {
        $stubPath = self::STUBS_DIR.'/cors.php.stub';
        $this->assertFileExists($stubPath, "Stub not found: {$stubPath}");
        $content = file_get_contents($stubPath);

        // El stub tiene un placeholder {{extraCorsPaths}} que el scaffolder
        // reemplaza según el flag --with-auth-rbac. Si un refactor elimina
        // este placeholder, el scaffolder pine config/cors.php sin el
        // 'sanctum/csrf-cookie' cuando se usa Sanctum SPA.
        $this->assertStringContainsString('{{extraCorsPaths}}', $content,
            'cors.php.stub debe tener un placeholder {{extraCorsPaths}} que el scaffolder '.
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
        $this->assertStringContainsString('http://localhost:3000', $content,
            'Default dev-friendly: http://localhost:3000 (Next.js dev server).');
        $this->assertStringContainsString('http://127.0.0.1:3000', $content,
            'Default dev-friendly: http://127.0.0.1:3000 (loopback).');
        $this->assertStringContainsString('FRONTEND_ORIGINS', $content,
            'config debe leer de env var FRONTEND_ORIGINS (override para prod).');
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
