<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Embeds;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Http;
use Mk\Director\Embeds\MkEmbed;
use Mk\Director\Embeds\MkEmbedService;
use Mk\Director\Enums\MkEmbedProvider;
use Mk\Director\Enums\MkMediaKind;
use Mk\Director\Tests\TestCase;

uses(TestCase::class);

function embedService(?Repository $cache = null): MkEmbedService
{
    return new MkEmbedService(cache: $cache, timeout: 1, cacheTtl: 60);
}

// ─── detect(): sólo regex, sin red ────────────────────────────────────────────

it('reconoce todos los formatos de URL de YouTube', function (string $url) {
    $embed = embedService()->detect($url);

    expect($embed?->provider)->toBe(MkEmbedProvider::YouTube)
        ->and($embed?->providerId)->toBe('dQw4w9WgXcQ');
})->with([
    'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
    'https://youtube.com/watch?v=dQw4w9WgXcQ',
    'https://m.youtube.com/watch?v=dQw4w9WgXcQ',
    'https://www.youtube.com/watch?list=PL123&v=dQw4w9WgXcQ',
    'https://youtu.be/dQw4w9WgXcQ',
    'https://www.youtube.com/shorts/dQw4w9WgXcQ',
    'https://www.youtube.com/embed/dQw4w9WgXcQ',
    'https://www.youtube.com/live/dQw4w9WgXcQ',
]);

it('no deja que el query string se cuele adentro del id de YouTube', function () {
    // El id de YouTube es de 11 caracteres EXACTOS. Sin acotarlo, un patrón
    // goloso se lleva `&t=30s` adentro del id y el embed apunta a la nada.
    $embed = embedService()->detect('https://youtu.be/dQw4w9WgXcQ?t=30s');

    expect($embed?->providerId)->toBe('dQw4w9WgXcQ');
});

it('reconoce TikTok canónico y el link corto de compartir', function (string $url, string $id) {
    $embed = embedService()->detect($url);

    expect($embed?->provider)->toBe(MkEmbedProvider::TikTok)
        ->and($embed?->providerId)->toBe($id);
})->with([
    ['https://www.tiktok.com/@scout2015/video/6718335390845095173', '6718335390845095173'],
    // El botón de compartir de TikTok da ESTE formato: es el que la gente
    // realmente pega, así que no soportarlo haría sentir la feature rota.
    ['https://vm.tiktok.com/ZMhvQ8KLp/', 'ZMhvQ8KLp'],
]);

it('reconoce posts, reels y tv de Instagram', function (string $url) {
    $embed = embedService()->detect($url);

    expect($embed?->provider)->toBe(MkEmbedProvider::Instagram)
        ->and($embed?->providerId)->toBe('fA9uwTtkSN');
})->with([
    'https://www.instagram.com/p/fA9uwTtkSN/',
    'https://www.instagram.com/reel/fA9uwTtkSN/',
    'https://instagram.com/tv/fA9uwTtkSN/',
]);

it('devuelve null para una URL de un proveedor desconocido', function (string $url) {
    // null NO es un error: significa "guardalo como link plano".
    expect(embedService()->detect($url))->toBeNull();
})->with([
    'https://vimeo.com/123456789',
    'https://example.com/algo',
    'no soy una url',
    '',
]);

it('arma la miniatura de YouTube por convención, sin red', function () {
    // Http::fake() sin definiciones hace fallar cualquier request real, así
    // que si esto pasa es porque efectivamente NO se llamó a nadie.
    Http::fake();

    $embed = embedService()->resolve('https://youtu.be/dQw4w9WgXcQ');

    expect($embed?->thumbnailUrl)->toBe('https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg');
    Http::assertNothingSent();
});

// ─── resolve(): oEmbed, caché y degradación ───────────────────────────────────

it('enriquece con el thumbnail del oEmbed', function () {
    Http::fake([
        'www.tiktok.com/oembed*' => Http::response([
            'thumbnail_url' => 'https://cdn.tiktok.test/thumb.jpg',
            'title' => 'Un video',
            'author_name' => 'scout2015',
        ]),
    ]);

    $embed = embedService()->resolve('https://www.tiktok.com/@scout2015/video/6718335390845095173');

    expect($embed?->thumbnailUrl)->toBe('https://cdn.tiktok.test/thumb.jpg')
        ->and($embed?->title)->toBe('Un video')
        ->and($embed?->authorName)->toBe('scout2015');
});

it('devuelve el embed igual cuando el oEmbed falla', function (int $status) {
    // 🔴 LA PROPIEDAD QUE IMPORTA. Un tercero caído degrada el muro a
    // "embed sin miniatura", NO lo rompe. Meta ya rompió estos endpoints una
    // vez en 2020; puede volver a hacerlo.
    Http::fake(['*' => Http::response('', $status)]);

    $embed = embedService()->resolve('https://www.instagram.com/p/fA9uwTtkSN/');

    expect($embed)->toBeInstanceOf(MkEmbed::class)
        ->and($embed?->providerId)->toBe('fA9uwTtkSN')
        ->and($embed?->thumbnailUrl)->toBeNull();
})->with([400, 401, 404, 429, 500, 503]);

it('devuelve el embed igual cuando la red explota', function () {
    Http::fake(fn () => throw new \RuntimeException('Connection timed out'));

    $embed = embedService()->resolve('https://www.instagram.com/p/fA9uwTtkSN/');

    expect($embed)->toBeInstanceOf(MkEmbed::class)
        ->and($embed?->thumbnailUrl)->toBeNull();
});

it('no pisa un dato bueno con un string vacío del proveedor', function () {
    // Los proveedores mandan "" y null indistintamente para "no tengo esto".
    Http::fake([
        '*' => Http::response(['thumbnail_url' => '', 'title' => '   ']),
    ]);

    $embed = embedService()->resolve('https://www.tiktok.com/@a/video/123');

    expect($embed?->thumbnailUrl)->toBeNull()
        ->and($embed?->title)->toBeNull();
});

it('cachea la respuesta y no vuelve a pegarle al proveedor', function () {
    $cache = new Repository(new ArrayStore);
    Http::fake(['*' => Http::response(['thumbnail_url' => 'https://cdn.test/t.jpg'])]);

    $service = embedService($cache);
    $url = 'https://www.tiktok.com/@a/video/123';

    $service->resolve($url);
    $service->resolve($url);

    Http::assertSentCount(1);
});

it('cachea TAMBIÉN el fallo', function () {
    // Sin esto, una URL que el proveedor no reconoce le vuelve a pegar en
    // CADA request que muestre el post: un embed roto en un feed muy visitado
    // se convierte en un martilleo constante contra un tercero.
    $cache = new Repository(new ArrayStore);
    Http::fake(['*' => Http::response('', 404)]);

    $service = embedService($cache);
    $url = 'https://www.tiktok.com/@a/video/123';

    $service->resolve($url);
    $service->resolve($url);

    Http::assertSentCount(1);
});

it('funciona sin caché configurado', function () {
    // El caché es nullable a propósito: un consumer sin `cache` configurado
    // (o un test) tiene que poder usar el servicio igual.
    Http::fake(['*' => Http::response(['thumbnail_url' => 'https://cdn.test/t.jpg'])]);

    $embed = embedService(null)->resolve('https://www.tiktok.com/@a/video/123');

    expect($embed?->thumbnailUrl)->toBe('https://cdn.test/t.jpg');
});

// ─── El puente con mk_media ───────────────────────────────────────────────────

it('traduce el embed a columnas de mk_media', function () {
    $embed = new MkEmbed(
        provider: MkEmbedProvider::YouTube,
        providerId: 'dQw4w9WgXcQ',
        sourceUrl: 'https://youtu.be/dQw4w9WgXcQ',
        thumbnailUrl: 'https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg',
        title: 'Never Gonna Give You Up',
    );

    $attributes = $embed->toMediaAttributes();

    expect($attributes['kind'])->toBe(MkMediaKind::Embed)
        ->and($attributes['provider'])->toBe(MkEmbedProvider::YouTube)
        ->and($attributes['provider_id'])->toBe('dQw4w9WgXcQ')
        ->and($attributes['meta'])->toBe(['title' => 'Never Gonna Give You Up'])
        // Un embed NO tiene archivo propio en ningún disk nuestro.
        ->and($attributes)->not->toHaveKey('disk')
        ->and($attributes)->not->toHaveKey('path');
});

it('deja meta en null cuando no hay nada que guardar', function () {
    $embed = new MkEmbed(
        provider: MkEmbedProvider::Instagram,
        providerId: 'abc',
        sourceUrl: 'https://www.instagram.com/p/abc/',
    );

    expect($embed->toMediaAttributes()['meta'])->toBeNull();
});
