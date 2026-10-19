<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Mk\Director\Enums\MkMediaKind;
use Mk\Director\Enums\MkUploadStatus;
use Mk\Director\Models\MkMedia;
use Mk\Director\Models\MkUpload;
use Mk\Director\Storage\DirectUpload\MkDirectUpload;
use Mk\Director\Storage\DirectUpload\MkUploadException;
use Mk\Director\Tests\Concerns\FakeS3;
use Mk\Director\Tests\Concerns\UsesDatabase;
use Mk\Director\Tests\TestCase;
use Mk\Director\Traits\HasMkMedia;

uses(TestCase::class, UsesDatabase::class);

/**
 * `MkDirectUpload` y `HasMkMedia::attachUpload()`: la subida directa.
 *
 * El disk es un `s3` REAL sobre {@see FakeS3}: los links firmados son los que
 * recibiría el navegador y cada comando al storage queda registrado.
 *
 * 🔴 La pertenencia se afirma en la BASE y en los comandos al storage, no sólo
 * en la excepción: un 404 que igual cerró la subida ajena es justo el bug.
 */
class DirectUploadAdmin extends EloquentModel
{
    protected $table = 'direct_upload_admins';

    public $timestamps = false;

    protected $guarded = [];
}

class DirectUploadPost extends EloquentModel
{
    use HasMkMedia;

    protected $table = 'direct_upload_posts';

    public $timestamps = false;

    protected $guarded = [];
}

beforeEach(function () {
    $this->setUpDatabase();
    $this->runPackageMigration('2026_07_19_000001_create_mk_media_table.php');
    $this->runPackageMigration('2026_10_21_000001_create_mk_uploads_table.php');

    foreach (['direct_upload_admins', 'direct_upload_posts'] as $table) {
        Schema::create($table, fn (Blueprint $t) => $t->id());
    }

    config()->set('mk_director.storage', [
        'public_disk' => 'r2_public',
        'upload_ttl' => 21600,
        'part_size' => 1000,
        'uploads_table' => 'mk_uploads',
        'profiles' => [
            'image' => [
                'role' => 'public',
                'mimes' => ['image/jpeg' => ['jpg', 'jpeg'], 'image/png' => ['png']],
                'max_bytes' => 5000,
                'max_megapixels' => 25,
                'prefix' => 'media/images',
            ],
        ],
    ]);

    $this->s3 = FakeS3::disk('r2_public');
    $this->uploads = app(MkDirectUpload::class);
    $this->ana = DirectUploadAdmin::create([]);
    $this->beto = DirectUploadAdmin::create([]);
});

afterEach(fn () => $this->tearDownDatabase());

/** Una subida de `$owner` ya Completed, lista para adjuntar. */
function completedUpload(object $test, EloquentModel $owner): MkUpload
{
    $id = $test->uploads->start($owner, 'image', 'foto.jpg', 1500, 'image/jpeg')['upload']->id;

    return $test->uploads->complete($owner, $id, ['"e1"', '"e2"']);
}

function uploadError(Closure $call): MkUploadException
{
    try {
        $call();
    } catch (MkUploadException $e) {
        return $e;
    }

    throw new RuntimeException('Se esperaba una MkUploadException y no se lanzó.');
}

// ─── start ────────────────────────────────────────────────────────────────────

test('start signs one url per part', function () {
    $started = $this->uploads->start($this->ana, 'image', 'foto.jpg', 2001, 'image/jpeg');

    expect($started['part_size'])->toBe(1000)
        ->and($started['part_urls'])->toHaveCount(3);

    foreach ($started['part_urls'] as $i => $url) {
        expect($url)->toStartWith('http://s3.test/bucket-test/'.$started['upload']->key)
            ->toContain('partNumber='.($i + 1))
            ->toContain('uploadId=mp-upload-1')
            ->toContain('X-Amz-Expires=21600')
            ->toContain('X-Amz-Signature=');
    }

    // Firmar es local: al storage sólo le llegó el arranque del multipart.
    expect(array_map(fn ($c) => $c->getName(), $this->s3->commands))->toBe(['CreateMultipartUpload'])
        ->and($this->s3->named('CreateMultipartUpload')[0]['ContentType'])->toBe('image/jpeg');

    $row = MkUpload::query()->sole();
    expect($row->status)->toBe(MkUploadStatus::Started)
        ->and($row->owner_type)->toBe($this->ana->getMorphClass())
        ->and($row->owner_id)->toBe((string) $this->ana->getKey())
        ->and($row->bytes)->toBe(2001)
        ->and($row->expires_at->diffInSeconds(now()->addSeconds(21600), true))->toBeLessThan(5.0);
});

test('start builds the key on the server, never from the filename', function () {
    $upload = $this->uploads->start($this->ana, 'image', '../../x/../passwd.JPEG', 10, 'image/jpeg')['upload'];

    // La extensión es la que el perfil le da al MIME (`jpg`), no la del cliente.
    expect($upload->key)->toBe('media/images/'.now()->format('Y/m').'/'.$upload->id.'.jpg')
        ->and($this->s3->named('CreateMultipartUpload')[0]['Key'])->toBe($upload->key);
});

test('start refuses a mime or size the profile does not take, without touching storage', function (string $filename, int $bytes, string $mime, string $code) {
    $error = uploadError(fn () => $this->uploads->start($this->ana, 'image', $filename, $bytes, $mime));

    expect($error->errorCode)->toBe($code)
        ->and($error->status)->toBe(422)
        ->and($this->s3->commands)->toBe([])
        ->and(MkUpload::query()->count())->toBe(0);
})->with([
    'un tipo que el perfil no toma' => ['setup.exe', 10, 'application/x-msdownload', MkUploadException::ERR_UPLOAD_TYPE],
    'extensión y MIME que no se corresponden' => ['foto.png', 10, 'image/jpeg', MkUploadException::ERR_UPLOAD_TYPE],
    'un byte de más' => ['foto.jpg', 5001, 'image/jpeg', MkUploadException::ERR_UPLOAD_TOO_LARGE],
]);

test('start keeps a 343-character multipart id (Cloudflare R2)', function () {
    $r2Id = str_repeat('Ab3-', 85).'xyz';
    $this->s3->responses['CreateMultipartUpload'] = ['UploadId' => $r2Id];

    $id = $this->uploads->start($this->ana, 'image', 'foto.jpg', 10, 'image/jpeg')['upload']->id;

    // sqlite no corta un varchar: la columna tiene que ser TEXT para que MySQL tampoco.
    expect(strlen($r2Id))->toBe(343)
        ->and(Schema::getColumnType('mk_uploads', 'multipart_id'))->toBe('text')
        ->and(MkUpload::query()->find($id)->multipart_id)->toBe($r2Id);
});

// ─── complete / abort ─────────────────────────────────────────────────────────

test('complete joins the parts with their etags in order and marks it completed', function () {
    $id = $this->uploads->start($this->ana, 'image', 'foto.jpg', 2500, 'image/jpeg')['upload']->id;

    $done = $this->uploads->complete($this->ana, $id, ['"e1"', '"e2"', '"e3"']);

    $command = $this->s3->named('CompleteMultipartUpload')[0];
    expect($command['UploadId'])->toBe('mp-upload-1')
        ->and($command['Key'])->toBe($done->key)
        ->and($command['MultipartUpload']['Parts'])->toBe([
            ['PartNumber' => 1, 'ETag' => '"e1"'],
            ['PartNumber' => 2, 'ETag' => '"e2"'],
            ['PartNumber' => 3, 'ETag' => '"e3"'],
        ])
        ->and(MkUpload::query()->find($id)->status)->toBe(MkUploadStatus::Completed);
});

test('complete with a missing etag is refused and joins nothing', function () {
    $id = $this->uploads->start($this->ana, 'image', 'foto.jpg', 2500, 'image/jpeg')['upload']->id;

    expect(uploadError(fn () => $this->uploads->complete($this->ana, $id, ['"e1"', '"e2"']))->errorCode)
        ->toBe(MkUploadException::ERR_UPLOAD_PARTS)
        ->and($this->s3->named('CompleteMultipartUpload'))->toBe([]);
});

test('complete of another owner\'s upload is 404 and touches nothing', function () {
    $id = $this->uploads->start($this->ana, 'image', 'foto.jpg', 10, 'image/jpeg')['upload']->id;

    $error = uploadError(fn () => $this->uploads->complete($this->beto, $id, ['"e1"']));

    expect($error->status)->toBe(404)
        ->and($this->s3->named('CompleteMultipartUpload'))->toBe([])
        ->and(MkUpload::query()->find($id)->status)->toBe(MkUploadStatus::Started);
});

test('abort drops the parts, and only the owner can', function () {
    $upload = $this->uploads->start($this->ana, 'image', 'foto.jpg', 10, 'image/jpeg')['upload'];

    expect(uploadError(fn () => $this->uploads->abort($this->beto, $upload->id))->status)->toBe(404)
        ->and($this->s3->named('AbortMultipartUpload'))->toBe([]);

    $this->uploads->abort($this->ana, $upload->id);

    $command = $this->s3->named('AbortMultipartUpload')[0];
    expect($command['UploadId'])->toBe('mp-upload-1')
        ->and($command['Key'])->toBe($upload->key)
        ->and(MkUpload::query()->find($upload->id)->status)->toBe(MkUploadStatus::Aborted);
});

// ─── attachUpload ─────────────────────────────────────────────────────────────

test('attachUpload creates the media row and marks the upload attached', function () {
    $upload = completedUpload($this, $this->ana);
    $post = DirectUploadPost::create([]);
    $post->attachMedia(['kind' => MkMediaKind::Image, 'disk' => 'r2_public', 'path' => 'old.jpg', 'collection' => 'gallery']);

    $media = $post->attachUpload($this->ana, $upload->id, 'gallery');

    expect($media->kind)->toBe(MkMediaKind::Image)
        ->and($media->disk)->toBe('r2_public')
        ->and($media->path)->toBe($upload->key)
        ->and($media->mime_type)->toBe('image/jpeg')
        ->and($media->size)->toBe(1500)
        ->and($media->collection)->toBe('gallery')
        ->and($media->position)->toBe(2) // al final de su colección
        ->and($media->mediable_id)->toEqual($post->getKey())
        ->and(MkUpload::query()->find($upload->id)->status)->toBe(MkUploadStatus::Attached);
});

test('attachUpload refuses an upload used twice', function () {
    $upload = completedUpload($this, $this->ana);
    DirectUploadPost::create([])->attachUpload($this->ana, $upload->id);

    $other = DirectUploadPost::create([]);

    expect(uploadError(fn () => $other->attachUpload($this->ana, $upload->id))->status)->toBe(404)
        ->and(MkMedia::query()->count())->toBe(1);
});

test('attachUpload refuses another owner\'s upload', function () {
    $upload = completedUpload($this, $this->ana);
    $post = DirectUploadPost::create([]);

    expect(uploadError(fn () => $post->attachUpload($this->beto, $upload->id))->status)->toBe(404)
        ->and(MkMedia::query()->count())->toBe(0)
        ->and(MkUpload::query()->find($upload->id)->status)->toBe(MkUploadStatus::Completed);
});

test('attachUpload refuses a started upload', function () {
    $id = $this->uploads->start($this->ana, 'image', 'foto.jpg', 10, 'image/jpeg')['upload']->id;
    $post = DirectUploadPost::create([]);

    expect(uploadError(fn () => $post->attachUpload($this->ana, $id))->status)->toBe(404)
        ->and(MkMedia::query()->count())->toBe(0);
});

test('a profile that does not exist throws', function () {
    expect(fn () => $this->uploads->start($this->ana, 'nope', 'a.jpg', 1, 'image/jpeg'))
        ->toThrow(InvalidArgumentException::class, 'nope');
});
