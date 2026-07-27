<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Http\Resources;

use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Mk\Director\Enums\MkEmbedProvider;
use Mk\Director\Enums\MkMediaKind;
use Mk\Director\Http\Resources\MkMediaResource;
use Mk\Director\Models\MkMedia;
use Mk\Director\Tests\Concerns\UsesDatabase;
use Mk\Director\Tests\TestCase;

uses(TestCase::class, UsesDatabase::class);

/**
 * `MkMediaResource` — el shape es CONTRATO PÚBLICO.
 *
 * Estos tests corren contra sqlite real y filas reales, no contra el source
 * del archivo. Un test que grepea `toContain('provider_id')` pasa igual si el
 * campo se serializa con el valor equivocado, o si `kind` deja de resolverse
 * a su `->value` int y empieza a salir el objeto enum — que es exactamente el
 * tipo de cambio que rompe un front sin romper ningún test.
 *
 * El assert que importa es `array_keys(...)->toBe([...])`: pinea el set de
 * claves EXACTO. Sacar una clave o renombrarla pone esto en rojo, que es el
 * punto — hay dos fronts consumiendo este JSON.
 */
beforeEach(function () {
    $this->setUpDatabase();
    $this->runPackageMigration('2026_07_19_000001_create_mk_media_table.php');

    // `MkMedia::getUrlAttribute()` resuelve el disk DE LA FILA con
    // `Storage::disk($this->disk)->url(...)`. El paquete no bootea un
    // filesystem real en tests: stub que devuelve una URL predecible por disk,
    // para poder assertear que se usó el disk de la fila y no uno global.
    Container::getInstance()->instance('filesystem', new class
    {
        public function disk(?string $name = null): object
        {
            return new class($name)
            {
                public function __construct(private ?string $disk) {}

                public function url(string $path): string
                {
                    return "https://cdn.test/{$this->disk}/{$path}";
                }
            };
        }
    });

    $this->request = Request::create('/');
});

afterEach(function () {
    $this->tearDownDatabase();
});

/**
 * El orden de esta lista es el orden de `toArray()`. Vive en una sola
 * constante para que los dos tests de shape (imagen y embed) no puedan
 * divergir entre sí.
 *
 * @return list<string>
 */
function mkMediaContractKeys(): array
{
    return [
        'id',
        'kind',
        'kind_label',
        'position',
        'url',
        'mime_type',
        'width',
        'height',
        'duration',
        'provider',
        'provider_label',
        'provider_id',
        'source_url',
        'thumbnail_url',
    ];
}

test('una imagen serializa las 14 claves del contrato con la url resuelta contra el disk de SU fila', function () {
    $media = MkMedia::create([
        'mediable_type' => 'post',
        'mediable_id' => '9f1c-uuid',
        'collection' => 'gallery',
        'kind' => MkMediaKind::Image,
        'disk' => 's3-fotos',
        'path' => 'posts/portada.webp',
        'mime_type' => 'image/webp',
        'size' => 84_512,
        'width' => 1920,
        'height' => 1080,
        'position' => 3,
    ]);

    $payload = (new MkMediaResource($media))->resolve($this->request);

    expect(array_keys($payload))->toBe(mkMediaContractKeys());

    expect($payload)->toMatchArray([
        'id' => $media->id,
        // 🔴 int, NO el objeto enum ni el nombre del case.
        'kind' => 1,
        'kind_label' => 'Imagen',
        'position' => 3,
        'url' => 'https://cdn.test/s3-fotos/posts/portada.webp',
        'mime_type' => 'image/webp',
        'width' => 1920,
        'height' => 1080,
        'duration' => null,
        // Un archivo propio no tiene nada de embed.
        'provider' => null,
        'provider_label' => null,
        'provider_id' => null,
        'source_url' => null,
        'thumbnail_url' => null,
    ]);
});

test('un embed serializa provider, provider_id y source_url; la url cae en la source_url', function () {
    $media = MkMedia::create([
        'mediable_type' => 'post',
        'mediable_id' => '9f1c-uuid',
        'kind' => MkMediaKind::Embed,
        'provider' => MkEmbedProvider::YouTube,
        'provider_id' => 'dQw4w9WgXcQ',
        'source_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
        'thumbnail_url' => 'https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg',
        'position' => 0,
    ]);

    $payload = (new MkMediaResource($media))->resolve($this->request);

    expect(array_keys($payload))->toBe(mkMediaContractKeys());

    expect($payload)->toMatchArray([
        'kind' => 3,
        'kind_label' => 'Enlace externo',
        // Un embed no tiene archivo nuestro: `url` es la source_url, no null.
        'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
        'mime_type' => null,
        'provider' => 1,
        'provider_label' => 'YouTube',
        // 🔴 Sin esto el cliente tiene que re-parsear la URL con su propia
        // regex para armar `youtube.com/embed/{id}`.
        'provider_id' => 'dQw4w9WgXcQ',
        'source_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
        'thumbnail_url' => 'https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg',
    ]);
});

test('un embed sin miniatura serializa thumbnail_url null sin perder ninguna clave', function () {
    // No es un caso de borde: si el oEmbed del proveedor falla, el embed se
    // guarda igual y la miniatura queda null. El cliente TIENE que
    // contemplarlo, así que la clave debe seguir presente.
    $media = MkMedia::create([
        'mediable_type' => 'post',
        'mediable_id' => '1',
        'kind' => MkMediaKind::Embed,
        'provider' => MkEmbedProvider::TikTok,
        'provider_id' => '7123456789',
        'source_url' => 'https://www.tiktok.com/@x/video/7123456789',
        'thumbnail_url' => null,
    ]);

    $payload = (new MkMediaResource($media))->resolve($this->request);

    expect(array_keys($payload))->toBe(mkMediaContractKeys());
    expect($payload['thumbnail_url'])->toBeNull();
    expect($payload['provider_label'])->toBe('TikTok');
});

test('una colección de media serializa una lista de payloads en orden', function () {
    foreach ([2, 0, 1] as $position) {
        MkMedia::create([
            'mediable_type' => 'post',
            'mediable_id' => '1',
            'kind' => MkMediaKind::Image,
            'disk' => 'public',
            'path' => "p{$position}.jpg",
            'position' => $position,
        ]);
    }

    $collection = MkMediaResource::collection(
        MkMedia::query()->orderBy('position')->get(),
    );

    $payloads = array_map(
        fn (MkMediaResource $r): array => $r->resolve($this->request),
        $collection->collection->all(),
    );

    expect($payloads)->toHaveCount(3);
    expect(array_column($payloads, 'position'))->toBe([0, 1, 2]);
    expect(array_column($payloads, 'url'))->toBe([
        'https://cdn.test/public/p0.jpg',
        'https://cdn.test/public/p1.jpg',
        'https://cdn.test/public/p2.jpg',
    ]);
});
