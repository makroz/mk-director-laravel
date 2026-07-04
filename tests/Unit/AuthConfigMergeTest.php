<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Mk\Director\Auth\Services\TokenIssuer;
use Mk\Director\Tests\TestCase;
use Mockery;

uses(TestCase::class);

afterEach(function () {
    Mockery::close();
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication(null);
});

/**
 * F1.3 (LAR-02 + LAR-08 + XPK-12 HIGH) — Real auth config in mk_director.php.
 *
 * Pre-fix, config/auth_defaults.php carried the canonical `auth.guards`,
 * `auth.tables.*`, `auth.ttl.access_seconds`, `auth.ttl.refresh_seconds`
 * settings, but MkServiceProvider only merged config/mk_director.php — so
 * `config('mk_director.auth.tables.abilities', 'abilities')` and friends
 * silently fell through to the fallback default. Worse, the `refresh.rotate_on_refresh`
 * setting read by TokenIssuer wasn't available at all in the package's
 * canonical config — making the security toggle a footgun with no UI surface.
 *
 * Fix: merge auth_defaults.php into config/mk_director.php (the only file
 * `vendor:publish --tag=mk-config` publishes) with explicit env vars, then
 * delete the orphan config/auth_defaults.php.
 *
 * BC: the canonical config keys (`auth.ttl.access_seconds`,
 * `auth.tables.abilities`) match what TokenIssuer and MakeAuthUser stub
 * already read — so this is purely additive for consumers, while making
 * the settings actually configurable.
 */
test('mk_director.php exposes auth.guards map (admin and member by default)', function () {
    $src = (string) file_get_contents(__DIR__.'/../../config/mk_director.php');

    expect($src)->toContain("'guards'");
    expect($src)->toContain("'admin'");
    expect($src)->toContain("'member'");
    // Los guards deben leer env vars, no estar hardcodeados a 'web'.
    expect($src)->toContain('MK_AUTH_GUARD_ADMIN');
    expect($src)->toContain('MK_AUTH_GUARD_MEMBER');
});

test('mk_director.php exposes auth.tables (roles, abilities, role_user, ability_role)', function () {
    $src = (string) file_get_contents(__DIR__.'/../../config/mk_director.php');

    expect($src)->toContain("'tables'");

    // Cuatro tablas requeridas por el stub y por el make:auth-user scaffolder.
    expect($src)->toContain("'roles'");
    expect($src)->toContain("'abilities'");
    expect($src)->toContain("'role_user'");
    expect($src)->toContain("'ability_role'");

    // Cada una con env var + default que matchea la convención del repo.
    expect($src)->toContain('MK_AUTH_TABLES_ROLES');
    expect($src)->toContain('MK_AUTH_TABLES_ABILITIES');
    expect($src)->toContain('MK_AUTH_TABLES_ROLE_USER');
    expect($src)->toContain('MK_AUTH_TABLES_ABILITY_ROLE');
});

test('mk_director.php exposes auth.ttl.access_seconds with env var (BC: preserved name)', function () {
    $src = (string) file_get_contents(__DIR__.'/../../config/mk_director.php');

    expect($src)->toContain("'ttl'");
    expect($src)->toContain("'access_seconds'");
    expect($src)->toContain('MK_AUTH_TTL_ACCESS_SECONDS');
    // Default BC: 15 min (15 * 60 = 900).
    expect($src)->toMatch('/MK_AUTH_TTL_ACCESS_SECONDS.*15\s*\*\s*60/s');
});

test('mk_director.php exposes auth.ttl.refresh_seconds with env var (BC: preserved name)', function () {
    $src = (string) file_get_contents(__DIR__.'/../../config/mk_director.php');

    expect($src)->toContain("'refresh_seconds'");
    expect($src)->toContain('MK_AUTH_TTL_REFRESH_SECONDS');
    // Default BC: 7 días (7 * 24 * 60 * 60 = 604800).
    expect($src)->toMatch('/MK_AUTH_TTL_REFRESH_SECONDS.*7\s*\*\s*24\s*\*\s*60\s*\*\s*60/s');
});

test('mk_director.php exposes auth.refresh.rotate_on_refresh as bool (XPK-12 fix)', function () {
    $src = (string) file_get_contents(__DIR__.'/../../config/mk_director.php');

    expect($src)->toContain("'refresh'");
    expect($src)->toContain("'rotate_on_refresh'");
    expect($src)->toContain('MK_AUTH_REFRESH_ROTATE_ON_REFRESH');

    // Default DEBE ser false (BC-safe). No auto-rotación a menos que el consumer lo pida.
    // El cast a bool debe ser explícito para que string "false"/"0" del env se respete.
    expect($src)->toMatch('/MK_AUTH_REFRESH_ROTATE_ON_REFRESH.*false/s');
    // filter_var FILTER_VALIDATE_BOOLEAN es el patrón canónico para bool envs
    // (Laravel-style: 'true'|'1'|yes → true; 'false'|'0'|no|'' → false).
    expect($src)->toContain('FILTER_VALIDATE_BOOLEAN');
});

test('config/auth_defaults.php was removed (no longer an orphan config file)', function () {
    // El archivo era código muerto — MkServiceProvider solo carga mk_director.php
    // vía mergeConfigFrom, así que auth_defaults.php nunca se mergeaba.
    // Tras el merge, el archivo debería estar borrado para evitar confusión.
    expect(file_exists(__DIR__.'/../../config/auth_defaults.php'))->toBeFalse();
});

test('MkServiceProvider does not load config/auth_defaults.php (single source of truth)', function () {
    $src = (string) file_get_contents(__DIR__.'/../../src/MkServiceProvider.php');

    // El provider debe mergear solo mk_director.php — auth_defaults.php ya no existe.
    expect($src)->not->toContain('auth_defaults.php');
    expect($src)->toContain("mergeConfigFrom(__DIR__.'/../config/mk_director.php'");
});

test('TokenIssuer reads rotate_on_refresh as bool and deletes old token when true', function () {
    $src = (string) file_get_contents(__DIR__.'/../../src/Auth/Services/TokenIssuer.php');

    // 1. Lee la config key canónica (sin typos).
    expect($src)->toContain("'mk_director.auth.refresh.rotate_on_refresh'");

    // 2. Casteo explícito a (bool) — string "false"/"0" del env debe respetarse.
    expect($src)->toMatch('/\(bool\)\s*\$this->readConfigInt\(\s*[\'"]mk_director\.auth\.refresh\.rotate_on_refresh[\'"]/');

    // 3. Branch true → borra viejo + emite nuevo.
    expect($src)->toMatch('/if\s*\(\s*\$rotateOnRefresh\s*\)\s*\{[^}]*\$tokenModel->delete/s');
    expect($src)->toMatch('/if\s*\(\s*\$rotateOnRefresh\s*\)\s*\{[^}]*\$this->issueRefreshToken/s');
});

test('TokenIssuer reads auth.ttl.access_seconds and auth.ttl.refresh_seconds from config', function () {
    $src = (string) file_get_contents(__DIR__.'/../../src/Auth/Services/TokenIssuer.php');

    expect($src)->toContain("'mk_director.auth.ttl.access_seconds'");
    expect($src)->toContain('15 * 60');
    expect($src)->toContain("'mk_director.auth.ttl.refresh_seconds'");
    expect($src)->toContain('7 * 24 * 60 * 60');
});

test('TokenIssuer returns the configured access TTL when container has config (functional)', function () {
    // Boot a fresh container with config('mk_director.auth.ttl.access_seconds') = 600.
    $container = new Container;
    Container::setInstance($container);
    $container->instance('config', new ConfigRepository([
        'mk_director' => [
            'auth' => [
                'ttl' => [
                    'access_seconds' => 600,
                    'refresh_seconds' => 604800,
                ],
                'refresh' => [
                    'rotate_on_refresh' => false,
                ],
                'tables' => [
                    'abilities' => 'abilities',
                ],
            ],
        ],
    ]));
    Facade::setFacadeApplication($container);

    // TokenIssuer sin constructor args debe leer de la config.
    $issuer = new TokenIssuer;
    $reflection = new \ReflectionClass($issuer);
    $method = $reflection->getMethod('accessTtl');
    $method->setAccessible(true);

    expect($method->invoke($issuer))->toBe(600);
});

test('TokenIssuer returns the configured refresh TTL when container has config (functional)', function () {
    $container = new Container;
    Container::setInstance($container);
    $container->instance('config', new ConfigRepository([
        'mk_director' => [
            'auth' => [
                'ttl' => [
                    'access_seconds' => 900,
                    'refresh_seconds' => 86400, // 1 día
                ],
                'refresh' => [
                    'rotate_on_refresh' => false,
                ],
                'tables' => [
                    'abilities' => 'abilities',
                ],
            ],
        ],
    ]));
    Facade::setFacadeApplication($container);

    $issuer = new TokenIssuer;
    $reflection = new \ReflectionClass($issuer);
    $method = $reflection->getMethod('refreshTtl');
    $method->setAccessible(true);

    expect($method->invoke($issuer))->toBe(86400);
});

test('TokenIssuer falls back to defaults when container has no config (readConfigInt default path)', function () {
    // Container vacío → config() null → default 15*60 (900) para access.
    $container = new Container;
    Container::setInstance($container);
    Facade::setFacadeApplication($container);

    $issuer = new TokenIssuer;
    $reflection = new \ReflectionClass($issuer);
    $method = $reflection->getMethod('accessTtl');
    $method->setAccessible(true);

    expect($method->invoke($issuer))->toBe(15 * 60);
});
