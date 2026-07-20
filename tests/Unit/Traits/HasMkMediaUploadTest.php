<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Mk\Director\Enums\MkMediaKind;
use Mk\Director\Models\MkMedia;
use Mk\Director\Tests\Concerns\UsesDatabase;
use Mk\Director\Tests\TestCase;
use Mk\Director\Traits\HasMkMedia;

uses(TestCase::class, UsesDatabase::class);

/**
 * `HasMkMedia::attachUploadedFile()` — el pipeline de subida.
 *
 * 🔴 ARCHIVO APARTE DE `HasMkMediaTest`, Y NO POR PROLIJIDAD.
 * Ese archivo reemplaza el binding `filesystem` por un stub que REGISTRA los
 * deletes, que es lo correcto para lo que testea. Acá hace falta lo contrario:
 * un disk local de VERDAD (el que arma `MkLaravelTestCase` en un directorio
 * temporal). Convivir en el mismo archivo significaría que el `beforeEach` de
 * uno rompa al otro.
 *
 * Y el disk real es el punto. Un mock de `Storage` confirma que LLAMAMOS a
 * `put`, no que el archivo haya quedado guardado. Justo la mitad del método
 * que puede fallar —el path que devuelve `store()`, el contenido que después
 * hay que releer para medir la imagen— quedaría sin verificar.
 */
class UploadOwner extends EloquentModel
{
    use HasMkMedia;

    protected $table = 'upload_owners';

    public $timestamps = false;

    protected $guarded = [];
}

beforeEach(function () {
    $this->setUpDatabase();
    $this->runPackageMigration('2026_07_19_000001_create_mk_media_table.php');

    Schema::create('upload_owners', function (Blueprint $table): void {
        $table->id();
    });
});

afterEach(function () {
    // El disk es un directorio temporal por proceso: se limpia para que una
    // corrida no herede archivos de la anterior.
    Storage::disk('local')->deleteDirectory('gallery');
    Storage::disk('local')->deleteDirectory('cover');
    Storage::disk('local')->deleteDirectory('default');
});

// ─── attachUploadedFile(): el pipeline de subida ──────────────────────────────

/**
 * Estos tests corren contra un disk local REAL (directorio temporal, ver
 * `MkLaravelTestCase`), no contra un mock de la facade.
 *
 * 🔴 Y ES DELIBERADO. Un mock de `Storage` confirma que LLAMAMOS a `put`, no
 * que el archivo haya quedado en el disk. Justo la mitad del método que puede
 * fallar —el path que devuelve `store()`, el contenido que después hay que
 * releer para medir la imagen— queda sin verificar. Con un disk real, si el
 * archivo no está, el test se cae.
 */
test('guarda el archivo y deduce kind Image del MIME', function () {
    $owner = UploadOwner::create([]);

    $file = UploadedFile::fake()->image('foto.jpg', 120, 80);
    $media = $owner->attachUploadedFile($file, 'gallery');

    expect($media->kind)->toBe(MkMediaKind::Image)
        ->and($media->collection)->toBe('gallery')
        ->and($media->mime_type)->toStartWith('image/')
        ->and($media->size)->toBeGreaterThan(0)
        // El archivo EXISTE en el disk, no sólo la fila.
        ->and(Storage::disk($media->disk)->exists($media->path))->toBeTrue();
});

test('mide ancho y alto de la imagen', function () {
    // Las dimensiones se leen del archivo YA GUARDADO. Si se leyeran del
    // temporal —que `store()` ya movió—, `getimagesize()` devolvería false y
    // las dos columnas quedarían en null SIN error.
    $owner = UploadOwner::create([]);

    $media = $owner->attachUploadedFile(UploadedFile::fake()->image('foto.jpg', 320, 240));

    expect($media->width)->toBe(320)
        ->and($media->height)->toBe(240);
});

test('deduce kind Video y deja duration en null', function () {
    // `duration` en null es HONESTO: leerla exige ffprobe y el paquete no va a
    // arrastrar esa dependencia. Un cero fingido sería peor.
    $owner = UploadOwner::create([]);

    $media = $owner->attachUploadedFile(
        UploadedFile::fake()->create('clip.mp4', 128, 'video/mp4')
    );

    expect($media->kind)->toBe(MkMediaKind::Video)
        ->and($media->duration)->toBeNull()
        ->and($media->width)->toBeNull();
});

test('RECHAZA un archivo que no es imagen ni video', function () {
    // `mk_media` guarda imágenes y videos. Un PDF no tiene dónde ir y aceptarlo
    // crearía una fila con un `kind` mentido que la UI no sabe mostrar.
    $owner = UploadOwner::create([]);

    expect(fn () => $owner->attachUploadedFile(
        UploadedFile::fake()->create('contrato.pdf', 10, 'application/pdf')
    ))->toThrow(InvalidArgumentException::class);

    expect(MkMedia::count())->toBe(0);
});

test('el kind sale del CONTENIDO, no de la extensión', function () {
    // 🔴 EL TEST QUE JUSTIFICA USAR getMimeType(). Un archivo de video con
    // nombre `.jpg` NO es una imagen. Si el kind saliera de la extensión,
    // entraría como Image y después no se podría mostrar — y la validación de
    // tamaño que el consumer aplicó "a las imágenes" habría sido la equivocada.
    $owner = UploadOwner::create([]);

    $media = $owner->attachUploadedFile(
        UploadedFile::fake()->create('disfrazado.jpg', 64, 'video/mp4')
    );

    expect($media->kind)->toBe(MkMediaKind::Video);
});

test('respeta las posiciones y las autoincrementa por colección', function () {
    $owner = UploadOwner::create([]);

    $a = $owner->attachUploadedFile(UploadedFile::fake()->image('a.jpg'), 'gallery');
    $b = $owner->attachUploadedFile(UploadedFile::fake()->image('b.jpg'), 'gallery');
    $otra = $owner->attachUploadedFile(UploadedFile::fake()->image('c.jpg'), 'cover');

    expect($a->position)->toBe(1)
        ->and($b->position)->toBe(2)
        // Otra colección arranca su propia numeración.
        ->and($otra->position)->toBe(1);
});
