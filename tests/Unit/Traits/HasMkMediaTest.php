<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Traits;

use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Mk\Director\Enums\MkEmbedProvider;
use Mk\Director\Enums\MkMediaKind;
use Mk\Director\Models\MkMedia;
use Mk\Director\Tests\Concerns\UsesDatabase;
use Mk\Director\Tests\TestCase;
use Mk\Director\Traits\HasMkMedia;

uses(TestCase::class, UsesDatabase::class);

/**
 * Dueño con PK bigint — el caso de Condaty.
 */
class MediaOwnerBigint extends EloquentModel
{
    use HasMkMedia;

    protected $table = 'owners_bigint';

    public $timestamps = false;

    protected $guarded = [];
}

/**
 * Dueño con PK uuid (string) — el caso de RETO.
 */
class MediaOwnerUuid extends EloquentModel
{
    use HasMkMedia;

    protected $table = 'owners_uuid';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];
}

/**
 * Dueño con SoftDeletes — para verificar que un soft delete NO se lleva la media.
 */
class MediaOwnerSoft extends EloquentModel
{
    use HasMkMedia;
    use SoftDeletes;

    protected $table = 'owners_soft';

    public $timestamps = false;

    protected $guarded = [];
}

beforeEach(function () {
    $this->setUpDatabase();
    $this->runPackageMigration('2026_07_19_000001_create_mk_media_table.php');

    Schema::create('owners_bigint', function (Blueprint $table): void {
        $table->id();
    });
    Schema::create('owners_uuid', function (Blueprint $table): void {
        $table->uuid('id')->primary();
    });
    Schema::create('owners_soft', function (Blueprint $table): void {
        $table->id();
        $table->softDeletes();
    });

    // El trait borra archivos con Storage::disk()->delete(). El paquete no
    // bootea un filesystem real en tests, así que pinchamos un stub que
    // REGISTRA los deletes para poder assertear sobre ellos.
    $this->deletedFiles = new \ArrayObject;
    Container::getInstance()->instance('filesystem', new class($this->deletedFiles)
    {
        public function __construct(private \ArrayObject $log) {}

        public function disk(?string $name = null): object
        {
            return new class($this->log, $name)
            {
                public function __construct(private \ArrayObject $log, private ?string $disk) {}

                public function delete(string $path): bool
                {
                    $this->log[] = [$this->disk, $path];

                    return true;
                }

                public function url(string $path): string
                {
                    return "https://cdn.test/{$this->disk}/{$path}";
                }
            };
        }
    });
});

afterEach(function () {
    $this->tearDownDatabase();
});

/**
 * Comunicaciones Fase 1 PR 1 — tabla mk_media + HasMkMedia.
 *
 * Todos estos tests corren contra sqlite real (trait UsesDatabase). El estilo
 * source-grep del paquete no podría verificar ninguno.
 */
test('la migración crea mk_media con su índice de galería', function () {
    expect(Schema::hasTable('mk_media'))->toBeTrue();
    expect(Schema::hasColumns('mk_media', [
        'mediable_type', 'mediable_id', 'collection', 'kind',
        'disk', 'path', 'provider', 'provider_id', 'source_url',
        'thumbnail_url', 'mime_type', 'size', 'width', 'height',
        'duration', 'position', 'meta',
    ]))->toBeTrue();
});

test('la migración es idempotente (guard hasTable)', function () {
    // Un segundo up() no debe explotar por tabla duplicada.
    $this->runPackageMigration('2026_07_19_000001_create_mk_media_table.php');

    expect(Schema::hasTable('mk_media'))->toBeTrue();
});

test('LA DECISIÓN CLAVE: la misma tabla sirve a un dueño bigint y a uno uuid', function () {
    // Éste es el test que justifica `string('mediable_id')` en vez de
    // `morphs()` (clava bigint) o `uuidMorphs()` (clava uuid). Si alguien
    // "optimiza" la columna a uno de los dos tipos, este test lo caza.
    $bigint = MediaOwnerBigint::create([]);
    $uuid = MediaOwnerUuid::create(['id' => '9f1c2d3e-4a5b-6c7d-8e9f-0a1b2c3d4e5f']);

    $bigint->attachMedia(['kind' => MkMediaKind::Image, 'disk' => 'public', 'path' => 'a.jpg']);
    $uuid->attachMedia(['kind' => MkMediaKind::Image, 'disk' => 'public', 'path' => 'b.jpg']);

    expect($bigint->media()->count())->toBe(1);
    expect($uuid->media()->count())->toBe(1);

    // Y NO se cruzan: un id '1' bigint no debe matchear con un uuid.
    expect($bigint->media()->first()->path)->toBe('a.jpg');
    expect($uuid->media()->first()->path)->toBe('b.jpg');
});

test('media() viene ordenada por position con id como desempate', function () {
    $owner = MediaOwnerBigint::create([]);

    $owner->attachMedia(['kind' => MkMediaKind::Image, 'path' => 'c.jpg', 'position' => 5]);
    $owner->attachMedia(['kind' => MkMediaKind::Image, 'path' => 'a.jpg', 'position' => 1]);
    $owner->attachMedia(['kind' => MkMediaKind::Image, 'path' => 'b.jpg', 'position' => 1]);

    expect($owner->media()->pluck('path')->all())->toBe(['a.jpg', 'b.jpg', 'c.jpg']);
});

test('attachMedia manda al final de SU colección, no del total', function () {
    $owner = MediaOwnerBigint::create([]);

    $owner->attachMedia(['kind' => MkMediaKind::Image, 'path' => 'g1.jpg', 'collection' => 'gallery']);
    $owner->attachMedia(['kind' => MkMediaKind::Image, 'path' => 'g2.jpg', 'collection' => 'gallery']);
    $cover = $owner->attachMedia(['kind' => MkMediaKind::Image, 'path' => 'c.jpg', 'collection' => 'cover']);

    expect($owner->mediaFrom('gallery')->pluck('position')->all())->toBe([1, 2]);
    // La cover arranca su propia numeración: si `attachMedia` contara el total,
    // acá saldría 3.
    expect($cover->position)->toBe(1);
});

test('mediaFrom aísla las colecciones', function () {
    $owner = MediaOwnerBigint::create([]);

    $owner->attachMedia(['kind' => MkMediaKind::Image, 'path' => 'g.jpg', 'collection' => 'gallery']);
    $owner->attachMedia(['kind' => MkMediaKind::Image, 'path' => 'c.jpg', 'collection' => 'cover']);

    expect($owner->mediaFrom('gallery')->pluck('path')->all())->toBe(['g.jpg']);
    expect($owner->mediaFrom('cover')->pluck('path')->all())->toBe(['c.jpg']);
    expect($owner->media()->count())->toBe(2);
});

test('borrar el dueño borra su media y su archivo, sin tocar la de otro dueño', function () {
    // Una relación polimórfica NO puede tener FK, así que no hay
    // cascadeOnDelete que salve: si el boot del trait no corre, quedan filas
    // huérfanas — el bug exacto del legacy.
    $victim = MediaOwnerBigint::create([]);
    $bystander = MediaOwnerBigint::create([]);

    $victim->attachMedia(['kind' => MkMediaKind::Image, 'disk' => 's3', 'path' => 'gone.jpg']);
    $bystander->attachMedia(['kind' => MkMediaKind::Image, 'disk' => 's3', 'path' => 'stays.jpg']);

    $victim->delete();

    expect(MkMedia::count())->toBe(1);
    expect(MkMedia::first()->path)->toBe('stays.jpg');
    // Y el archivo se borró del disk DE LA FILA, no de un disk global.
    expect(iterator_to_array($this->deletedFiles))->toBe([['s3', 'gone.jpg']]);
});

test('un embed no intenta borrar ningún archivo', function () {
    $owner = MediaOwnerBigint::create([]);
    $owner->attachMedia([
        'kind' => MkMediaKind::Embed,
        'provider' => MkEmbedProvider::YouTube,
        'provider_id' => 'dQw4w9WgXcQ',
        'source_url' => 'https://youtu.be/dQw4w9WgXcQ',
    ]);

    $owner->delete();

    expect(MkMedia::count())->toBe(0);
    expect(iterator_to_array($this->deletedFiles))->toBe([]);
});

test('un SOFT delete del dueño NO se lleva la media', function () {
    // Restaurar un post tiene que devolverte sus fotos.
    $owner = MediaOwnerSoft::create([]);
    $owner->attachMedia(['kind' => MkMediaKind::Image, 'disk' => 'public', 'path' => 'keep.jpg']);

    $owner->delete();

    expect(MkMedia::count())->toBe(1);
    expect(iterator_to_array($this->deletedFiles))->toBe([]);
});

test('un FORCE delete del dueño sí se lleva la media', function () {
    $owner = MediaOwnerSoft::create([]);
    $owner->attachMedia(['kind' => MkMediaKind::Image, 'disk' => 'public', 'path' => 'bye.jpg']);

    $owner->forceDelete();

    expect(MkMedia::count())->toBe(0);
    expect(iterator_to_array($this->deletedFiles))->toBe([['public', 'bye.jpg']]);
});

test('kind castea a MkMediaKind y meta a array al releer de la base', function () {
    $owner = MediaOwnerBigint::create([]);
    $owner->attachMedia([
        'kind' => MkMediaKind::Video,
        'disk' => 'public',
        'path' => 'v.mp4',
        'meta' => ['codec' => 'h264'],
        'duration' => 42,
    ]);

    $fresh = MkMedia::first();

    expect($fresh->kind)->toBe(MkMediaKind::Video);
    expect($fresh->meta)->toBe(['codec' => 'h264']);
    expect($fresh->duration)->toBe(42);
});

test('la url resuelve por el disk DE LA FILA, y por source_url si es embed', function () {
    $owner = MediaOwnerBigint::create([]);
    $owner->attachMedia(['kind' => MkMediaKind::Image, 'disk' => 's3', 'path' => 'x.jpg']);
    $owner->attachMedia([
        'kind' => MkMediaKind::Embed,
        'source_url' => 'https://youtu.be/abc',
    ]);

    $all = $owner->media()->get();

    expect($all[0]->url)->toBe('https://cdn.test/s3/x.jpg');
    expect($all[1]->url)->toBe('https://youtu.be/abc');
});
