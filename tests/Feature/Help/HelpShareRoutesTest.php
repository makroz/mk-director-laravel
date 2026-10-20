<?php

declare(strict_types=1);

use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mk\Director\Help\MkHelpShareLinks;
use Mk\Director\Help\MkHelpSync;
use Mk\Director\Help\Models\MkHelpShareLink;
use Mk\Director\Tests\Concerns\BootsPushApp;
use Mk\Director\Tests\Concerns\PushTestUser;
use Mk\Director\Tests\MkLaravelTestCase;

/**
 * Los enlaces públicos de los manuales con la cadena de middleware REAL. Las
 * rutas del dialogo van con sesión y con `canMk(share.ability)`; las públicas
 * van sin sesión y con su throttle. Lo compartido se ve entero, y nada fuera
 * de lo compartido.
 */
uses(MkLaravelTestCase::class, BootsPushApp::class);

afterEach(function () {
    Carbon::setTestNow();
    $this->tearDownHttpApp();
});

const HELP_SHARE_ABILITY = 'admin.help.share';

/**
 * @param  array<string, mixed>  $share  overrides de `help.share`
 * @param  list<mixed>  $publicMiddleware
 */
function bootShareApp(object $test, array $share = [], array $publicMiddleware = ['api', ThrottleRequests::class.':60,1']): void
{
    $test->bootPushApp([], ['help' => [
        'content_path' => __DIR__.'/../../Fixtures/help',
        'articles_table' => 'mk_help_articles',
        'share_links_table' => 'mk_help_share_links',
        'routes' => [
            'register' => true,
            'scopes' => [
                'admin' => ['prefix' => 'api/admin', 'middleware' => ['api', 'mk.auth:admin'], 'audiences' => ['admin', 'business'], 'share' => true],
                'staff' => ['prefix' => 'api/staff', 'middleware' => ['api', 'mk.auth:admin'], 'audiences' => ['member'], 'share' => false],
            ],
            'public' => ['prefix' => 'api/help', 'middleware' => $publicMiddleware],
        ],
        'share' => $share + ['ability' => HELP_SHARE_ABILITY, 'max_hours' => 720, 'public_url' => 'https://panel.test/compartido'],
    ]]);

    foreach (glob(__DIR__.'/../../../src/Database/Migrations/2026_10_22_*_mk_help_*.php') ?: [] as $migration) {
        (require $migration)->up();
    }
    expect(app(MkHelpSync::class)->run()->failed())->toBeFalse();
}

/** @return array{0: PushTestUser, 1: string} el usuario y su header `Authorization` */
function sharer(object $test, string $name, bool $canShare = true): array
{
    [$user, $token] = $test->pushUser($name);

    if ($canShare) {
        // `giveAbilityTo()` crea la ability con id autoincremental; acá la tabla es uuid.
        $id = DB::table('abilities')->where('name', HELP_SHARE_ABILITY)->value('id');
        if ($id === null) {
            $id = (string) Str::uuid();
            DB::table('abilities')->insert(['id' => $id, 'name' => HELP_SHARE_ABILITY]);
        }
        $user->directAbilities()->attach($id, $user->abilityPivotExtras());
    }

    return [$user, 'Bearer '.$token];
}

/** @return array{0: int, 1: array<string, mixed>} */
function shareCall(object $test, string $method, string $uri, ?string $auth = null, array $body = []): array
{
    $headers = $auth === null ? [] : ['HTTP_AUTHORIZATION' => $auth];
    $response = match ($method) {
        'GET' => $test->httpGet($uri, $headers),
        'POST' => $test->httpPost($uri, $body, $headers),
        'DELETE' => $test->httpDelete($uri, $headers),
    };

    return [$response->getStatusCode(), json_decode((string) $response->getContent(), true) ?? []];
}

function helpRouteNamed(string $name): ?Route
{
    $routes = app('router')->getRoutes();
    $routes->refreshNameLookups();

    return $routes->getByName($name);
}

/** El token de la URL que devolvió el `store`. */
function tokenOf(string $url): string
{
    return substr($url, strrpos($url, '/') + 1);
}

test('share is forbidden without the ability, and with no ability configured nobody shares', function () {
    bootShareApp($this);
    [, $auth] = sharer($this, 'Ana', canShare: false);

    [$list] = shareCall($this, 'GET', '/api/admin/help/share-links', $auth);
    [$store] = shareCall($this, 'POST', '/api/admin/help/share-links', $auth, ['target' => 'manual:admin', 'hours' => 24]);
    [$anonymous] = shareCall($this, 'GET', '/api/admin/help/share-links');

    expect($list)->toBe(403)
        ->and($store)->toBe(403)
        ->and($anonymous)->toBe(401)
        ->and(DB::table('mk_help_share_links')->count())->toBe(0);

    $this->tearDownHttpApp();
    bootShareApp($this, ['ability' => null]);
    [, $holder] = sharer($this, 'Beto');

    [$nobody] = shareCall($this, 'POST', '/api/admin/help/share-links', $holder, ['target' => 'manual:admin', 'hours' => 24]);

    expect($nobody)->toBe(403)->and(DB::table('mk_help_share_links')->count())->toBe(0);
});

test('share is disabled on a scope without share, and the public routes need a scope that shares', function () {
    bootShareApp($this);
    [, $auth] = sharer($this, 'Ana');

    [$staff] = shareCall($this, 'GET', '/api/staff/help/share-links', $auth);
    [$staffStore] = shareCall($this, 'POST', '/api/staff/help/share-links', $auth, ['target' => 'manual:member', 'hours' => 24]);

    expect($staff)->toBe(404)->and($staffStore)->toBe(404)
        ->and(helpRouteNamed('mk.help.staff.share-links.index'))->toBeNull()
        ->and(helpRouteNamed('mk.help.admin.share-links.index'))->not->toBeNull()
        ->and(helpRouteNamed('mk.help.shared'))->not->toBeNull();

    $this->tearDownHttpApp();
    $this->bootPushApp([], ['help' => ['routes' => ['register' => true, 'scopes' => [
        'staff' => ['prefix' => 'api/staff', 'middleware' => ['api', 'mk.auth:admin'], 'audiences' => ['member']],
    ]]]]);

    expect(helpRouteNamed('mk.help.shared'))->toBeNull();
});

test('a created link opens the public view without a session, and only its url carries the token', function () {
    bootShareApp($this);
    [, $auth] = sharer($this, 'Ana');

    [$status, $body] = shareCall($this, 'POST', '/api/admin/help/share-links', $auth, ['target' => 'slug:admin/primeros-pasos/entrar', 'hours' => 24]);
    $url = $body['data']['url'];

    expect($status)->toBe(201)
        ->and($url)->toStartWith('https://panel.test/compartido/')
        ->and(strlen(tokenOf($url)))->toBe(48)
        ->and($body['data']['status'])->toBe('active')
        ->and($body['data']['uses'])->toBe(0);

    [$open, $shared] = shareCall($this, 'GET', '/api/help/shared/'.tokenOf($url));
    [, $list] = shareCall($this, 'GET', '/api/admin/help/share-links', $auth);

    expect($open)->toBe(200)
        ->and($shared['data']['target'])->toBe('slug:admin/primeros-pasos/entrar')
        ->and($shared['data']['tree'])->toBeNull()
        ->and($shared['data']['article']['title'])->toBe('Entrar al panel')
        ->and($list['data'])->toHaveCount(1)
        ->and($list['data'][0])->not->toHaveKey('url')
        ->and($list['data'][0]['uses'])->toBe(1)
        ->and($list['data'][0]['lastUsedAt'])->toBeString()
        ->and(json_encode($list))->not->toContain(tokenOf($url));
});

test('the target must be something the scope sees, and hours stay within max_hours', function () {
    bootShareApp($this);
    [, $auth] = sharer($this, 'Ana');

    foreach ([
        ['target' => 'manual:member', 'hours' => 24],
        ['target' => 'slug:member/retirar', 'hours' => 24],
        ['target' => 'slug:admin/no-existe', 'hours' => 24],
        ['target' => 'admin', 'hours' => 24],
        ['target' => 'manual:admin', 'hours' => 721],
        ['target' => 'manual:admin', 'hours' => 0],
        ['target' => 'manual:admin'],
    ] as $body) {
        [$status] = shareCall($this, 'POST', '/api/admin/help/share-links', $auth, $body);

        expect($status)->toBe(422);
    }

    expect(DB::table('mk_help_share_links')->count())->toBe(0);
});

test('an expired link gives 410', function () {
    bootShareApp($this);
    [, $auth] = sharer($this, 'Ana');
    [, $body] = shareCall($this, 'POST', '/api/admin/help/share-links', $auth, ['target' => 'manual:admin', 'hours' => 24]);
    $token = tokenOf($body['data']['url']);

    Carbon::setTestNow(Carbon::now()->addHours(25));

    [$shared, $gone] = shareCall($this, 'GET', '/api/help/shared/'.$token);
    [$article] = shareCall($this, 'GET', "/api/help/shared/{$token}/articles/admin");

    // El access token de la sesión también venció: el estado, del modelo.
    expect($shared)->toBe(410)
        ->and($gone['success'])->toBeFalse()
        ->and($gone['data'])->toBeNull()
        ->and($article)->toBe(410)
        ->and(MkHelpShareLink::query()->sole()->status())->toBe('expired');
});

test('a revoked link gives 410', function () {
    bootShareApp($this);
    [, $auth] = sharer($this, 'Ana');
    [, $body] = shareCall($this, 'POST', '/api/admin/help/share-links', $auth, ['target' => 'manual:admin', 'hours' => 24]);
    $token = tokenOf($body['data']['url']);

    [$before] = shareCall($this, 'GET', '/api/help/shared/'.$token);
    [$revoked] = shareCall($this, 'DELETE', '/api/admin/help/share-links/'.$body['data']['id'], $auth);
    [$after] = shareCall($this, 'GET', '/api/help/shared/'.$token);
    [$article] = shareCall($this, 'GET', "/api/help/shared/{$token}/articles/admin");
    [, $list] = shareCall($this, 'GET', '/api/admin/help/share-links', $auth);
    [$unknown] = shareCall($this, 'GET', '/api/help/shared/'.str_repeat('x', 48));

    expect($before)->toBe(200)
        ->and($revoked)->toBe(200)
        ->and($after)->toBe(410)
        ->and($article)->toBe(410)
        ->and($list['data'][0]['status'])->toBe('revoked')
        ->and($list['data'][0]['revokedAt'])->toBeString()
        ->and($unknown)->toBe(410);
});

test('a shared manual lists every article of its audience, ability or not, and nothing of another', function () {
    bootShareApp($this);
    [, $auth] = sharer($this, 'Ana');
    [, $body] = shareCall($this, 'POST', '/api/admin/help/share-links', $auth, ['target' => 'manual:admin', 'hours' => 24]);
    $token = tokenOf($body['data']['url']);

    [$status, $shared] = shareCall($this, 'GET', '/api/help/shared/'.$token);

    expect($status)->toBe(200)
        ->and($shared['data']['target'])->toBe('manual:admin')
        ->and($shared['data']['tree'])->toBe([
            ['slug' => 'admin', 'title' => 'Manual del administrador', 'children' => [
                ['slug' => 'admin/primeros-pasos', 'title' => 'Primeros pasos', 'children' => [
                    ['slug' => 'admin/primeros-pasos/aprobar', 'title' => 'Aprobar una verificación', 'children' => []],
                    ['slug' => 'admin/primeros-pasos/entrar', 'title' => 'Entrar al panel', 'children' => []],
                ]],
            ]],
        ])
        ->and($shared['data']['article']['slug'])->toBe('admin');

    foreach (['admin', 'admin/primeros-pasos', 'admin/primeros-pasos/entrar', 'admin/primeros-pasos/aprobar'] as $slug) {
        [$article, $payload] = shareCall($this, 'GET', "/api/help/shared/{$token}/articles/{$slug}");

        expect($article)->toBe(200)->and($payload['data']['slug'])->toBe($slug);
    }

    // `aprobar` lleva ability (nadie la tiene acá) y relaciona `business/glosario`, fuera del manual.
    [, $approve] = shareCall($this, 'GET', "/api/help/shared/{$token}/articles/admin/primeros-pasos/aprobar");
    [$business] = shareCall($this, 'GET', "/api/help/shared/{$token}/articles/business/glosario");

    expect($approve['data']['ability'])->toBe('admin.kyc.approve')
        ->and($approve['data']['related'])->toBe([['slug' => 'admin/primeros-pasos/entrar', 'title' => 'Entrar al panel']])
        ->and($business)->toBe(404);
});

test('an article outside the shared target is 404', function () {
    bootShareApp($this);
    [, $auth] = sharer($this, 'Ana');
    [, $body] = shareCall($this, 'POST', '/api/admin/help/share-links', $auth, ['target' => 'slug:admin/primeros-pasos/aprobar', 'hours' => 24]);
    $token = tokenOf($body['data']['url']);

    [$own, $payload] = shareCall($this, 'GET', "/api/help/shared/{$token}/articles/admin/primeros-pasos/aprobar");
    [$sibling] = shareCall($this, 'GET', "/api/help/shared/{$token}/articles/admin/primeros-pasos/entrar");
    [$parent] = shareCall($this, 'GET', "/api/help/shared/{$token}/articles/admin");
    [$member] = shareCall($this, 'GET', "/api/help/shared/{$token}/articles/member/retirar");

    expect($own)->toBe(200)
        ->and($payload['data']['related'])->toBe([])
        ->and($sibling)->toBe(404)
        ->and($parent)->toBe(404)
        ->and($member)->toBe(404);
});

test('nobody revokes or lists someone else\'s link', function () {
    bootShareApp($this);
    [, $ana] = sharer($this, 'Ana');
    [, $beto] = sharer($this, 'Beto');
    [, $body] = shareCall($this, 'POST', '/api/admin/help/share-links', $ana, ['target' => 'manual:admin', 'hours' => 24]);

    [$revoke] = shareCall($this, 'DELETE', '/api/admin/help/share-links/'.$body['data']['id'], $beto);
    [, $betoList] = shareCall($this, 'GET', '/api/admin/help/share-links', $beto);
    [$still] = shareCall($this, 'GET', '/api/help/shared/'.tokenOf($body['data']['url']));

    expect($revoke)->toBe(404)
        ->and($betoList['data'])->toBe([])
        ->and($still)->toBe(200)
        ->and(DB::table('mk_help_share_links')->whereNotNull('revoked_at')->count())->toBe(0);
});

test('the public route is throttled', function () {
    bootShareApp($this, [], ['api', ThrottleRequests::class.':3,1']);
    [$user] = sharer($this, 'Ana');
    $token = tokenOf(app(MkHelpShareLinks::class)->create($user, 'manual:admin', 24)['url']);

    $statuses = array_map(fn () => shareCall($this, 'GET', '/api/help/shared/'.$token)[0], range(1, 4));

    expect($statuses)->toBe([200, 200, 200, 429]);
});
