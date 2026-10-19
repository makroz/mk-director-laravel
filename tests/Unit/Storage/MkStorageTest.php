<?php

declare(strict_types=1);

use Mk\Director\Storage\MkStorage;
use Mk\Director\Tests\TestCase;

uses(TestCase::class);

/**
 * `MkStorage`: los dos roles de disk y el link de un archivo guardado.
 *
 * Los disks `s3` usan credenciales falsas: armar un link (plano o firmado) es
 * local —un HMAC—, nunca toca la red. Así se mide el link de verdad, no un mock.
 */
beforeEach(function () {
    config()->set('filesystems.disks.r2_public', [
        'driver' => 's3', 'key' => 'test-key', 'secret' => 'test-secret', 'region' => 'auto',
        'bucket' => 'reto-public-test', 'endpoint' => 'https://acct.r2.cloudflarestorage.com',
        'url' => 'https://pub-test.r2.dev', 'use_path_style_endpoint' => true,
    ]);
    config()->set('filesystems.disks.r2_private', [
        'driver' => 's3', 'key' => 'test-key', 'secret' => 'test-secret', 'region' => 'auto',
        'bucket' => 'reto-private-test', 'endpoint' => 'https://acct.r2.cloudflarestorage.com',
        'use_path_style_endpoint' => true,
    ]);
});

test('sin configurar, los roles son los disks de siempre: public y local', function () {
    config()->set('mk_director.storage', []);

    $storage = new MkStorage;

    expect($storage->diskName('public'))->toBe('public')
        ->and($storage->diskName('private'))->toBe('local');
});

test('cada rol es el disk que la app configuró', function () {
    config()->set('mk_director.storage', ['public_disk' => 'r2_public', 'private_disk' => 'r2_private']);

    $storage = new MkStorage;

    expect($storage->diskName('public'))->toBe('r2_public')
        ->and($storage->diskName('private'))->toBe('r2_private');
});

test('un rol que no existe explota en vez de caer a un disk cualquiera', function () {
    expect(fn () => (new MkStorage)->diskName('shared'))->toThrow(InvalidArgumentException::class, 'shared');
});

test('el link de un disk público es la URL plana del bucket', function () {
    config()->set('mk_director.storage', ['public_disk' => 'r2_public', 'private_disk' => 'r2_private']);

    expect((new MkStorage)->url('r2_public', 'media/images/foto.webp'))
        ->toBe('https://pub-test.r2.dev/media/images/foto.webp');
});

test('el link de un disk privado está FIRMADO y vence', function () {
    // 🔴 EL CASO QUE JUSTIFICA LA CLASE. Un `->url()` sobre un bucket privado
    // devuelve un link sin firma que el bucket rechaza: la imagen se ve rota y
    // nadie sabe por qué.
    config()->set('mk_director.storage', ['public_disk' => 'r2_public', 'private_disk' => 'r2_private', 'signed_url_ttl' => 600]);

    $url = (new MkStorage)->url('r2_private', 'kyc/abc/front');

    expect($url)->toStartWith('https://acct.r2.cloudflarestorage.com/reto-private-test/kyc/abc/front?')
        ->toContain('X-Amz-Signature=')
        ->toContain('X-Amz-Expires=600');
});

test('un disk con visibility private también firma, aunque no sea el del rol', function () {
    config()->set('mk_director.storage', ['public_disk' => 'r2_public', 'private_disk' => 'local']);
    config()->set('filesystems.disks.r2_private.visibility', 'private');

    expect((new MkStorage)->url('r2_private', 'courses/1/cover.webp'))->toContain('X-Amz-Signature=');
});

test('un path vacío no tiene link, y una URL absoluta se devuelve tal cual', function () {
    $storage = new MkStorage;

    expect($storage->url('r2_public', null))->toBeNull()
        ->and($storage->url('r2_public', ''))->toBeNull()
        ->and($storage->url('r2_public', 'https://cdn.example.com/a.jpg'))->toBe('https://cdn.example.com/a.jpg');
});
