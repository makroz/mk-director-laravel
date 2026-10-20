<?php

declare(strict_types=1);

use Illuminate\Routing\Route;
use Mk\Director\Help\MkHelpSync;
use Mk\Director\Tests\Concerns\BootsPushApp;
use Mk\Director\Tests\MkLaravelTestCase;

/**
 * Las rutas de los manuales con la cadena de middleware REAL
 * (`['api', 'mk.auth:admin']`). Cada scope ve SÓLO sus audiencias; los
 * permisos no esconden nada (el usuario de prueba no tiene ninguno).
 */
uses(MkLaravelTestCase::class, BootsPushApp::class);

afterEach(fn () => $this->tearDownHttpApp());

/** @param  array<string, array<string, mixed>>  $scopes */
function bootHelpApp(object $test, array $scopes): string
{
    $test->bootPushApp([], ['help' => [
        'content_path' => __DIR__.'/../../Fixtures/help',
        'articles_table' => 'mk_help_articles',
        'routes' => ['register' => true, 'scopes' => $scopes],
    ]]);
    (require __DIR__.'/../../../src/Database/Migrations/2026_10_22_000001_create_mk_help_articles_table.php')->up();
    expect(app(MkHelpSync::class)->run()->failed())->toBeFalse();

    return 'Bearer '.$test->pushUser('Ana')[1];
}

/** @return array<string, Route> las rutas `mk.help.*`, por nombre */
function helpRoutes($app): array
{
    $routes = $app['router']->getRoutes();
    $routes->refreshNameLookups();

    return collect($routes->getRoutes())
        ->filter(fn (Route $r) => str_starts_with((string) $r->getName(), 'mk.help.'))
        ->keyBy(fn (Route $r) => $r->getName())
        ->all();
}

/** @return array{0: int, 1: array<string, mixed>} */
function helpGet(object $test, string $uri, ?string $auth): array
{
    $response = $test->httpGet($uri, $auth === null ? [] : ['HTTP_AUTHORIZATION' => $auth]);

    return [$response->getStatusCode(), json_decode((string) $response->getContent(), true) ?? []];
}

const HELP_ADMIN_SCOPE = ['admin' => [
    'prefix' => 'api/admin', 'middleware' => ['api', 'mk.auth:admin'], 'audiences' => ['admin', 'business'],
]];

test('routes are absent unless register is on', function () {
    expect(helpRoutes($this->bootHttpApp(stdClass::class)))->toBe([]);
    $this->tearDownHttpApp();

    $app = $this->bootHttpApp(stdClass::class, ['help' => ['routes' => ['register' => false, 'scopes' => HELP_ADMIN_SCOPE]]]);

    expect(helpRoutes($app))->toBe([]);
});

test('routes mount per scope with its middleware', function () {
    bootHelpApp($this, HELP_ADMIN_SCOPE);

    $routes = helpRoutes($this->httpApp);

    expect(array_keys($routes))->toEqualCanonicalizing(['mk.help.admin.tree', 'mk.help.admin.show'])
        ->and($routes['mk.help.admin.tree']->uri())->toBe('api/admin/help/tree')
        ->and($routes['mk.help.admin.show']->uri())->toBe('api/admin/help/articles/{slug}')
        ->and($routes['mk.help.admin.tree']->middleware())->toBe(['api', 'mk.auth:admin']);
});

test('without a session the manual answers 401', function () {
    bootHelpApp($this, HELP_ADMIN_SCOPE);

    [$status] = helpGet($this, '/api/admin/help/tree', null);

    expect($status)->toBe(401);
});

test('the tree only carries the audiences of its scope, nested and in order', function () {
    $auth = bootHelpApp($this, HELP_ADMIN_SCOPE);

    [$status, $body] = helpGet($this, '/api/admin/help/tree', $auth);

    expect($status)->toBe(200)
        ->and($body['success'])->toBeTrue()
        ->and($body['data'])->toBe([
            ['slug' => 'admin', 'title' => 'Manual del administrador', 'children' => [
                ['slug' => 'admin/primeros-pasos', 'title' => 'Primeros pasos', 'children' => [
                    ['slug' => 'admin/primeros-pasos/aprobar', 'title' => 'Aprobar una verificación', 'children' => []],
                    ['slug' => 'admin/primeros-pasos/entrar', 'title' => 'Entrar al panel', 'children' => []],
                ]],
            ]],
            ['slug' => 'business', 'title' => 'Cómo funciona', 'children' => [
                ['slug' => 'business/glosario', 'title' => 'Glosario', 'children' => []],
            ]],
        ]);
});

test('another scope sees only its own audiences', function () {
    $auth = bootHelpApp($this, HELP_ADMIN_SCOPE + ['staff' => [
        'prefix' => 'api/staff', 'middleware' => ['api', 'mk.auth:admin'], 'audiences' => ['member'],
    ]]);

    [, $tree] = helpGet($this, '/api/staff/help/tree', $auth);
    [$adminStatus] = helpGet($this, '/api/staff/help/articles/admin/primeros-pasos/entrar', $auth);

    expect(array_column($tree['data'], 'slug'))->toBe(['member'])
        ->and($adminStatus)->toBe(404);
});

test('show serves a nested slug and 404s an article of another audience', function () {
    $auth = bootHelpApp($this, HELP_ADMIN_SCOPE);

    [$status, $body] = helpGet($this, '/api/admin/help/articles/admin/primeros-pasos/entrar', $auth);
    [$memberStatus, $memberBody] = helpGet($this, '/api/admin/help/articles/member/retirar', $auth);

    expect($status)->toBe(200)
        ->and($body['data']['slug'])->toBe('admin/primeros-pasos/entrar')
        ->and($body['data']['title'])->toBe('Entrar al panel')
        ->and($body['data']['audience'])->toBe('admin')
        ->and($body['data']['body'])->toBe("Escribí tu correo y tu contraseña.\n")
        ->and($body['data']['updatedAt'])->toBeString()
        ->and($memberStatus)->toBe(404)
        ->and($memberBody['success'])->toBeFalse()
        ->and($memberBody['data'])->toBeNull();
});

test('an article with an ability is visible to a user without it and carries the ability', function () {
    $auth = bootHelpApp($this, HELP_ADMIN_SCOPE);

    [$status, $body] = helpGet($this, '/api/admin/help/articles/admin/primeros-pasos/aprobar', $auth);

    expect($status)->toBe(200)
        ->and($body['data']['ability'])->toBe('admin.kyc.approve');
});
