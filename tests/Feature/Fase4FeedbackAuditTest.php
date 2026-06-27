<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Feature;

use Mk\Director\Tests\MkLaravelTestCase;
use ReflectionClass;

/**
 * Audit-driven regression tests for RETO feedback fase 4 (R-PKG-017).
 *
 * Pinea los 5 gaps nuevos reportados en
 * `.makromania/projects/reto/modules/admin/FEEDBACK-TO-MK-DIRECTOR.md`
 * (clean rebuild RETO sobre v1.6.0-rc6). Cada test es regression: si el bug
 * vuelve, el test falla.
 *
 * **Bugs pineados**:
 *  - BUG-NEW-21: declare(strict_types=1) mal ubicado en routes/api.php (CRITICAL)
 *  - BUG-NEW-22: AdminRepository::syncRoles/syncDirectAbilities sin user_type (CRITICAL)
 *  - BUG-NEW-23: Hash::check() RuntimeException en TokenIssuer::rotateRefreshToken (CRITICAL)
 *  - BUG-NEW-24: Admin::newFactory() retorna AdminFactory sin import (medium)
 *  - BUG-NEW-25: profile fields docblock indentación drift con 5+ fields (low)
 *  - OBS: pivotExtras() ahora público (BC-safe improvement)
 *
 * **Patrón** (mk-director-implementation.md § "Audit-driven pre-tag discovery"):
 * source-parsing + reflection-based isolation. Cada test pinea una pieza de
 * código/texto que el fix introdujo, sin ejecutar el command end-to-end
 * (eso requiere `apps/sandbox-laravel`, no incluido en el paquete).
 *
 * **Cuándo correr**: `vendor/bin/pest tests/Feature/Fase4FeedbackAuditTest.php`
 * antes de tag de cualquier RC que toque el scaffolder `mk:make:auth-user`,
 * `TokenIssuer`, `HasRoles`, `HasAbilities` o stubs de profile fields.
 *
 * Spec: MK-LAR-1.6.0-rc7 (R-PKG-017).
 */
uses(MkLaravelTestCase::class);

/**
 * Helper: lee un stub del paquete (src/Stubs/...).
 */
function stubContentsFase4(string $relativePath): string
{
    $path = dirname(__DIR__, 2).'/src/Stubs/'.$relativePath;

    if (! file_exists($path)) {
        test()->fail("Stub no encontrado: {$path}");
    }

    return (string) file_get_contents($path);
}

/**
 * Helper: lee un archivo PHP del paquete (excluyendo vendor).
 */
function pkgFileContentsFase4(string $relativePath): string
{
    $path = dirname(__DIR__, 2).'/'.$relativePath;

    if (! file_exists($path)) {
        test()->fail("Archivo no encontrado: {$path}");
    }

    return (string) file_get_contents($path);
}

// ─── BUG-NEW-21 — declare(strict_types=1) preservado en extendRoutesWithCrud ─

test('BUG-NEW-21: extendRoutesWithCrud tiene declarePattern para preservar declare(strict_types=1)', function () {
    $src = pkgFileContentsFase4('src/Console/Commands/MakeAuthUserCommand.php');

    // El método debe tener un $declarePattern que detecta `<?php\n\ndeclare(...);` patterns.
    $extendMethod = extractMethodBody($src, 'extendRoutesWithCrud');

    expect($extendMethod)->toContain('$declarePattern');
    expect($extendMethod)->toMatch('/\\$declarePattern\s*=/');
    // El pattern regex debe capturar el `declare(...)` en algún grupo.
    expect($extendMethod)->toMatch('/declare\\\\s\*\\\\\(/');
    expect($extendMethod)->toContain('R-PKG-017 BUG-NEW-21');
});

test('BUG-NEW-21: extendRoutesWithCrud mantiene orden declare → use statements (no al revés)', function () {
    $src = pkgFileContentsFase4('src/Console/Commands/MakeAuthUserCommand.php');

    $extendMethod = extractMethodBody($src, 'extendRoutesWithCrud');

    // Debe tener la rama `if (preg_match($declarePattern, ...))` que inserta
    // los use statements DESPUÉS del declare, no antes.
    expect($extendMethod)->toMatch('/if\s*\(\s*preg_match\s*\(\s*\$declarePattern/s');

    // La línea de inserción debe usar la posición del match (no del `<?php`).
    expect($extendMethod)->toContain('$declareMatch[0][1] + strlen($declareMatch[0][0])');
});

// ─── BUG-NEW-22 — pivotExtras() público + scaffolder lo usa en Repository ─────

test('BUG-NEW-22: HasRoles::pivotExtras() ahora es public (BC-safe, usado por Repository scaffoldeado)', function () {
    $src = pkgFileContentsFase4('src/Auth/Concerns/HasRoles.php');

    // La visibilidad debe ser `public`, no `protected`.
    expect($src)->toMatch('/public\s+function\s+pivotExtras\s*\(\s*\)\s*:\s*array/');
    expect($src)->not->toMatch('/protected\s+function\s+pivotExtras\s*\(\s*\)\s*:\s*array/');
});

test('BUG-NEW-22: HasAbilities::abilityPivotExtras() ahora es public (BC-safe)', function () {
    $src = pkgFileContentsFase4('src/Auth/Concerns/HasAbilities.php');

    expect($src)->toMatch('/public\s+function\s+abilityPivotExtras\s*\(\s*\)\s*:\s*array/');
    expect($src)->not->toMatch('/protected\s+function\s+abilityPivotExtras\s*\(\s*\)\s*:\s*array/');
});

test('BUG-NEW-22: AdminRepository stub usa pivotExtras() y abilityPivotExtras() en sync', function () {
    $stub = stubContentsFase4('auth-user/admin-repository.stub');

    // syncRoles debe invocar ->pivotExtras() en el payload del sync.
    expect($stub)->toContain('->pivotExtras()');

    // syncDirectAbilities debe invocar ->abilityPivotExtras() en el payload del sync.
    expect($stub)->toContain('->abilityPivotExtras()');

    // Debe haber comentario R-PKG-017 BUG-NEW-22 explicando el cambio.
    expect($stub)->toContain('R-PKG-017 BUG-NEW-22');

    // NO debe quedar la versión hardcodeada con Admin::class en el payload del sync.
    expect($stub)->not->toContain("'user_type' => Admin::class");
});

// ─── BUG-NEW-23 — Hash::check RuntimeException → InvalidRefreshTokenException ──
//
// R-PKG-018 BUG-NEW-26 fix descubrió que BUG-NEW-23 era un SÍNTOMA — la causa
// raíz era que Sanctum v4.3.2 hashea tokens con SHA256, no bcrypt, y el código
// usaba `Hash::check()` (bcrypt). El catch de BUG-NEW-23 mitigaba el 500 → 401,
// pero el refresh NUNCA funcionaba.
//
// BUG-NEW-26 fix: usar `hash_equals(hash('sha256', ...), ...)` directamente.
// El try/catch queda como defense-in-depth por si Sanctum rota de algoritmo
// en el futuro.

test('BUG-NEW-23 + R-PKG-018 BUG-NEW-26: TokenIssuer usa hash_equals con SHA256 (no Hash::check), con try/catch defense-in-depth', function () {
    $src = pkgFileContentsFase4('src/Auth/Services/TokenIssuer.php');

    // Extraer el método rotateRefreshToken.
    $method = extractMethodBody($src, 'rotateRefreshToken');

    // FIX raíz (R-PKG-018 BUG-NEW-26): hash_equals + SHA256.
    expect($method)->toContain("hash_equals(\n                \$tokenModel->token,\n                hash('sha256', \$plaintext),");
    expect($method)->toContain("hash('sha256', \$plaintext)");

    // NO debe usar Hash::check para comparar tokens Sanctum.
    expect($method)->not->toMatch('/Hash::check\s*\(\s*\\\$plaintext\s*,\s*\\\$tokenModel->token/');

    // Defense-in-depth (subsume de BUG-NEW-23): el try/catch se mantiene
    // como safety net por si Sanctum rota a otro algoritmo.
    expect($method)->toContain('try {');
    expect($method)->toContain('catch (\\RuntimeException $e)');

    // El catch sigue lanzando InvalidRefreshTokenException::hashMismatch().
    expect($method)->toContain('InvalidRefreshTokenException::hashMismatch()');

    // Documentación actualizada.
    expect($method)->toContain('R-PKG-017 BUG-NEW-23');
    expect($method)->toContain('R-PKG-018 BUG-NEW-26');
    expect($method)->toContain('SHA256');
    expect($method)->toContain('Sanctum v4.3.2');
});

test('BUG-NEW-23 + R-PKG-018 BUG-NEW-26: rotateRefreshToken tiene try/catch con hash_equals (defense-in-depth)', function () {
    $src = pkgFileContentsFase4('src/Auth/Services/TokenIssuer.php');

    $method = extractMethodBody($src, 'rotateRefreshToken');

    // El método debe tener `hash_equals` dentro de un bloque `try { ... }`.
    expect($method)->toMatch('/try\s*\{[\s\S]*?hash_equals\([\s\S]*?\}\s*catch\s*\(\s*\\\\?RuntimeException/s');
});

// ─── BUG-NEW-24 — Admin::newFactory() retorna AdminFactory con import correcto ──

test('BUG-NEW-24: factoryHasFactoryUse incluye import de {scope}Factory cuando --with-crud activo', function () {
    $src = pkgFileContentsFase4('src/Console/Commands/MakeAuthUserCommand.php');

    // El bloque que setea el placeholder debe emitir el `use` de AdminFactory.
    // Extraer el array $factoryReplacements completo.
    $factoryReplacements = extractArrayBody($src, '$factoryReplacements = [');

    // Debe tener el `use App\Modules\{$scope}\Database\Factories\{$scope}Factory;`.
    // En código fuente PHP, los `\` aparecen como `\\` (doble escape).
    expect($factoryReplacements)->toContain('use App\\\\Modules\\\\{$scope}\\\\Database\\\\Factories\\\\{$scope}Factory;');

    // Debe documentar el R-PKG-017 BUG-NEW-24 fix.
    expect($factoryReplacements)->toContain('R-PKG-017 BUG-NEW-24');
});

test('BUG-NEW-24: stub auth-user.model.stub tiene el placeholder factoryHasFactoryUse listo', function () {
    $stub = stubContentsFase4('auth-user.model.stub');

    expect($stub)->toContain('{{factoryHasFactoryUse}}');
    expect($stub)->toContain('{{factoryNewFactoryMethod}}');
});

// ─── BUG-NEW-25 — profile fields docblock indentación drift con 5+ fields ──────

test('BUG-NEW-25: buildProfileFieldsReplacements emite docblock con cierre \\n (no \\n\\n que era drift-prone)', function () {
    $src = pkgFileContentsFase4('src/Console/Commands/MakeAuthUserCommand.php');

    // El cierre del docblock debe ser `\n     */` (NO `\n     */\n\n`).
    // Buscamos en la función buildProfileFieldsReplacements el patrón específico.
    $method = extractMethodBody($src, 'buildProfileFieldsReplacements');

    // Debe haber el cierre con `\n` simple (NO `\n\n`).
    expect($method)->toContain('"     */\n"');

    // NO debe quedar la versión vieja con `\n\n` que era drift-prone.
    expect($method)->not->toContain('"     */\n\n"');

    // Debe documentar el R-PKG-017 BUG-NEW-25 fix.
    expect($method)->toContain('R-PKG-017 BUG-NEW-25');
});

test('BUG-NEW-25: stub auth-user.model.stub separa el placeholder del próximo bloque con blank line', function () {
    $stub = stubContentsFase4('auth-user.model.stub');

    // El placeholder debe estar seguido de blank line + `/**` del próximo bloque (no `/**` pegado).
    // Buscamos: `{{profileFieldsDocblock}}\n\n    /**` (con blank line entre el placeholder y el próximo /**).
    expect($stub)->toMatch('/\{\{profileFieldsDocblock\}\}\s*\n\s*\n\s*\/\*\*/m');
});

test('BUG-NEW-25: buildProfileFieldsReplacements mantiene indentación 4/** 5/* (alineada PSR-12)', function () {
    $src = pkgFileContentsFase4('src/Console/Commands/MakeAuthUserCommand.php');

    $method = extractMethodBody($src, 'buildProfileFieldsReplacements');

    // El `/**` debe tener 4 espacios de indent.
    expect($method)->toContain('"    /**\\n"');

    // Los `@property` deben tener 5 espacios.
    expect($method)->toContain('"     * @property');
});

// ─── OBS: pivotExtras() + abilityPivotExtras() documentados en el header ─────

test('OBS: pivotExtras() documenta el cambio de visibilidad BC-safe en su docblock', function () {
    $src = pkgFileContentsFase4('src/Auth/Concerns/HasRoles.php');

    expect($src)->toContain('R-PKG-017 BUG-NEW-22');
    expect($src)->toContain('visibilidad cambiada de `protected` a `public`');
});

test('OBS: abilityPivotExtras() documenta el cambio de visibilidad BC-safe en su docblock', function () {
    $src = pkgFileContentsFase4('src/Auth/Concerns/HasAbilities.php');

    expect($src)->toContain('R-PKG-017 BUG-NEW-22');
});

// ─── Helpers ────────────────────────────────────────────────────────────────

/**
 * Extrae el cuerpo de un método (entre su opener `protected function X()` o
 * `public function X()` y el cierre de la primera llave `}` balanceada).
 *
 * Aproximación robusta sin PHP-Parser: cuenta profundidad de llaves.
 */
function extractMethodBody(string $src, string $methodName): string
{
    // Encuentra la declaración del método (acepta public|protected|private).
    $pattern = '/(public|protected|private)\s+function\s+'.preg_quote($methodName, '/').'\s*\(/';

    if (! preg_match($pattern, $src, $m, PREG_OFFSET_CAPTURE)) {
        test()->fail("Método {$methodName} no encontrado en el archivo.");
    }

    // Encuentra el primer `{` después de la firma del método.
    $start = strpos($src, '{', $m[0][1]);
    if ($start === false) {
        test()->fail("No se encontró `{{` para el método {$methodName}.");
    }

    // Cuenta profundidad de llaves.
    $depth = 0;
    $pos = $start;
    $len = strlen($src);
    while ($pos < $len) {
        $char = $src[$pos];
        if ($char === '{') {
            $depth++;
        } elseif ($char === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($src, $start, $pos - $start + 1);
            }
        }
        $pos++;
    }

    test()->fail("Llaves no balanceadas para {$methodName}.");
}

/**
 * Extrae el cuerpo de un array literal PHP entre `[` y su `]` balanceado.
 */
function extractArrayBody(string $src, string $searchMarker): string
{
    $start = strpos($src, $searchMarker);
    if ($start === false) {
        test()->fail("Marcador {$searchMarker} no encontrado.");
    }

    $openBracket = strpos($src, '[', $start);
    if ($openBracket === false) {
        test()->fail("No se encontró `[` después del marcador.");
    }

    $depth = 0;
    $pos = $openBracket;
    $len = strlen($src);
    while ($pos < $len) {
        $char = $src[$pos];
        if ($char === '[') {
            $depth++;
        } elseif ($char === ']') {
            $depth--;
            if ($depth === 0) {
                return substr($src, $openBracket, $pos - $openBracket + 1);
            }
        }
        $pos++;
    }

    test()->fail('Llaves del array no balanceadas.');
}
