<?php

declare(strict_types=1);

use Illuminate\Routing\Route;
use Mk\Director\Enums\MkUploadStatus;
use Mk\Director\Models\MkUpload;
use Mk\Director\Tests\Concerns\BootsPushApp;
use Mk\Director\Tests\Concerns\FakeS3;
use Mk\Director\Tests\Concerns\FileBytes;
use Mk\Director\Tests\MkLaravelTestCase;

/**
 * Las rutas de la subida directa con la cadena de middleware REAL
 * (`['api', 'mk.auth:admin']`) y el `config/mk_director.php` del paquete: los
 * perfiles que se usan acá son los DEFAULTS que recibe un consumer.
 *
 * 🔴 La pertenencia se afirma en la BASE y en los comandos al storage, no sólo
 * en el código HTTP.
 */
uses(MkLaravelTestCase::class, BootsPushApp::class);

afterEach(fn () => $this->tearDownHttpApp());

/** El bloque `storage` tal cual lo trae el paquete (la config usa `app_path()`: se lee de una app booteada). */
function packageStorageConfig(object $test): array
{
    $storage = $test->bootHttpApp(stdClass::class)['config']->get('mk_director.storage');
    $test->tearDownHttpApp();

    return $storage;
}

/** @param  array<string, array{prefix: string, middleware: list<string>}>  $scopes */
function bootUploadApp(object $test, array $scopes): FakeS3
{
    $storage = packageStorageConfig($test);
    $storage['public_disk'] = 'r2_public';
    $storage['routes'] = ['register' => true, 'scopes' => $scopes];

    $test->bootPushApp([], ['storage' => $storage]);
    (require __DIR__.'/../../../src/Database/Migrations/2026_10_21_000001_create_mk_uploads_table.php')->up();

    return FakeS3::disk('r2_public');
}

/** @return array<string, Route> las rutas `mk.uploads.*`, por nombre */
function uploadRoutes($app): array
{
    $routes = $app['router']->getRoutes();
    $routes->refreshNameLookups();

    return collect($routes->getRoutes())
        ->filter(fn (Route $r) => str_starts_with((string) $r->getName(), 'mk.uploads.'))
        ->keyBy(fn (Route $r) => $r->getName())
        ->all();
}

const ADMIN_UPLOAD_SCOPE = ['admin' => ['prefix' => 'api/admin', 'middleware' => ['api', 'mk.auth:admin']]];

test('routes are off by default', function () {
    expect(uploadRoutes($this->bootHttpApp(stdClass::class)))->toBe([]);
});

test('scopes alone do not mount the routes without register', function () {
    $storage = packageStorageConfig($this);
    $storage['routes'] = ['register' => false, 'scopes' => ADMIN_UPLOAD_SCOPE];

    expect(uploadRoutes($this->bootHttpApp(stdClass::class, ['storage' => $storage])))->toBe([]);
});

test('routes mount per scope with its middleware', function () {
    bootUploadApp($this, ADMIN_UPLOAD_SCOPE + [
        'member' => ['prefix' => 'api/member', 'middleware' => ['api', 'mk.auth:member']],
    ]);

    $routes = uploadRoutes($this->httpApp);

    expect(array_keys($routes))->toEqualCanonicalizing([
        'mk.uploads.admin.start', 'mk.uploads.admin.complete', 'mk.uploads.admin.abort',
        'mk.uploads.member.start', 'mk.uploads.member.complete', 'mk.uploads.member.abort',
    ])
        ->and($routes['mk.uploads.admin.start']->uri())->toBe('api/admin/uploads')
        ->and($routes['mk.uploads.admin.start']->methods())->toContain('POST')
        ->and($routes['mk.uploads.admin.complete']->uri())->toBe('api/admin/uploads/{upload}/complete')
        ->and($routes['mk.uploads.admin.abort']->uri())->toBe('api/admin/uploads/{upload}')
        ->and($routes['mk.uploads.admin.abort']->methods())->toContain('DELETE')
        ->and($routes['mk.uploads.admin.start']->middleware())->toBe(['api', 'mk.auth:admin'])
        ->and($routes['mk.uploads.member.start']->uri())->toBe('api/member/uploads')
        ->and($routes['mk.uploads.member.start']->middleware())->toBe(['api', 'mk.auth:member']);
});

test('start through the route answers 201 with the part urls, owned by the session user', function () {
    bootUploadApp($this, ADMIN_UPLOAD_SCOPE);
    [$ana, $token] = $this->pushUser('Ana');

    $response = $this->httpPost('/api/admin/uploads', [
        'profile' => 'image', 'filename' => 'foto.jpg', 'bytes' => 9 * 1024 * 1024, 'mime' => 'image/jpeg',
        'owner_id' => 'someone-else',
    ], ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);

    $body = json_decode((string) $response->getContent(), true);
    $row = MkUpload::query()->sole();

    expect($response->getStatusCode())->toBe(201)
        ->and($body['success'])->toBeTrue()
        ->and($body['data']['upload_id'])->toBe($row->id)
        ->and($body['data']['part_size'])->toBe(8 * 1024 * 1024)
        ->and($body['data']['part_urls'])->toHaveCount(2)
        ->and($body['data']['expires_at'])->toBeString()
        ->and($row->owner_id)->toBe($ana->id)
        ->and($row->key)->toStartWith('media/images/');
});

test('a type the profile does not take answers 422 with its code', function () {
    $s3 = bootUploadApp($this, ADMIN_UPLOAD_SCOPE);
    [, $token] = $this->pushUser('Ana');

    $response = $this->httpPost('/api/admin/uploads', [
        'profile' => 'image', 'filename' => 'setup.exe', 'bytes' => 10, 'mime' => 'application/x-msdownload',
    ], ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);

    expect($response->getStatusCode())->toBe(422)
        ->and(json_decode((string) $response->getContent(), true)['__extraData']['code'])->toBe('ERR_UPLOAD_TYPE')
        ->and($s3->commands)->toBe([]);
});

test('complete of another user\'s upload through the route is 404 and leaves it started', function () {
    $s3 = bootUploadApp($this, ADMIN_UPLOAD_SCOPE);
    [, $anaToken] = $this->pushUser('Ana');
    [, $betoToken] = $this->pushUser('Beto');

    $this->httpPost('/api/admin/uploads', ['profile' => 'image', 'filename' => 'foto.jpg', 'bytes' => 10, 'mime' => 'image/jpeg'], ['HTTP_AUTHORIZATION' => 'Bearer '.$anaToken]);
    $id = MkUpload::query()->sole()->id;

    $response = $this->httpPost("/api/admin/uploads/{$id}/complete", ['etags' => ['"e1"']], ['HTTP_AUTHORIZATION' => 'Bearer '.$betoToken]);
    $abort = $this->httpDelete("/api/admin/uploads/{$id}", ['HTTP_AUTHORIZATION' => 'Bearer '.$betoToken]);

    expect($response->getStatusCode())->toBe(404)
        ->and($abort->getStatusCode())->toBe(404)
        ->and($s3->named('CompleteMultipartUpload'))->toBe([])
        ->and($s3->named('AbortMultipartUpload'))->toBe([])
        ->and(MkUpload::query()->sole()->status)->toBe(MkUploadStatus::Started);

    $s3->stores(FileBytes::jpeg(1200, 800));
    $done = $this->httpPost("/api/admin/uploads/{$id}/complete", ['etags' => ['"e1"']], ['HTTP_AUTHORIZATION' => 'Bearer '.$anaToken]);
    expect($done->getStatusCode())->toBe(200)
        ->and(json_decode((string) $done->getContent(), true)['data'])->toMatchArray([
            'upload_id' => $id, 'mime' => 'image/jpeg', 'bytes' => strlen(FileBytes::jpeg(1200, 800)), 'width' => 1200, 'height' => 800,
        ]);
});

test('unauthenticated start is 401 and opens nothing', function (array $middleware) {
    $s3 = bootUploadApp($this, ['admin' => ['prefix' => 'api/admin', 'middleware' => $middleware]]);

    $response = $this->httpPost('/api/admin/uploads', ['profile' => 'image', 'filename' => 'foto.jpg', 'bytes' => 10, 'mime' => 'image/jpeg']);

    expect($response->getStatusCode())->toBe(401)
        ->and($s3->commands)->toBe([])
        ->and(MkUpload::query()->count())->toBe(0);
})->with([
    'con mk.auth' => [['api', 'mk.auth:admin']],
    // Un scope configurado sin `mk.auth`: el controller falla cerrado igual.
    'sin mk.auth' => [['api']],
]);
