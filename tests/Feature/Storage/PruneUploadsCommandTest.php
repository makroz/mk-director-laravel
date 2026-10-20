<?php

declare(strict_types=1);

use Aws\CommandInterface;
use Aws\S3\Exception\S3Exception;
use Illuminate\Container\Container;
use Illuminate\Support\Str;
use Mk\Director\Console\Commands\PruneUploadsCommand;
use Mk\Director\Enums\MkUploadStatus;
use Mk\Director\Models\MkUpload;
use Mk\Director\Tests\Concerns\FakeS3;
use Mk\Director\Tests\Concerns\UsesDatabase;
use Mk\Director\Tests\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * `mk:uploads:prune`: las subidas directas vencidas y las que nadie adjuntó.
 *
 * El disk es un `s3` REAL sobre {@see FakeS3}. Se afirma el ESTADO de cada fila
 * en la base y los comandos que llegaron al storage, no el texto de la salida.
 */
uses(TestCase::class, UsesDatabase::class);

beforeEach(function () {
    $this->setUpDatabase();
    $this->runPackageMigration('2026_10_21_000001_create_mk_uploads_table.php');

    config()->set('mk_director.storage', [
        'public_disk' => 'r2_public',
        'uploads_table' => 'mk_uploads',
        'prune_unattached_after_hours' => 24,
        'profiles' => [],
    ]);

    $this->s3 = FakeS3::disk('r2_public');
});

afterEach(fn () => $this->tearDownDatabase());

/** Una fila de `mk_uploads` en `$status`, que vence en `$expiresIn` minutos y se tocó hace `$touchedAgo`. */
function pruneUpload(string $key, MkUploadStatus $status, int $expiresIn, int $touchedAgo = 0): string
{
    $id = (string) Str::uuid();

    MkUpload::query()->insert([
        'id' => $id,
        'disk' => 'r2_public',
        'key' => $key,
        'multipart_id' => 'mp-'.$key,
        'parts' => 1,
        'profile' => 'image',
        'status' => $status->value,
        'owner_type' => 'admin',
        'owner_id' => '1',
        'expires_at' => now()->addMinutes($expiresIn),
        'created_at' => now()->subMinutes($touchedAgo),
        'updated_at' => now()->subMinutes($touchedAgo),
    ]);

    return $id;
}

/** @return array{0: int, 1: string} */
function runUploadsPrune(array $options = []): array
{
    $command = new PruneUploadsCommand;
    // El Container pelado de MkLaravelTestCase no tiene `runningUnitTests()`.
    $command->setLaravel(new class extends Container
    {
        public function runningUnitTests(): bool
        {
            return true;
        }
    });
    $output = new BufferedOutput;

    return [$command->run(new ArrayInput($options), $output), $output->fetch()];
}

function uploadStatus(string $id): MkUploadStatus
{
    return MkUpload::query()->findOrFail($id)->status;
}

/** @return list<string> las keys que recibieron ese comando del storage */
function s3Keys(FakeS3 $s3, string $command): array
{
    return array_map(fn (CommandInterface $c): string => (string) $c['Key'], $s3->named($command));
}

test('aborts expired started uploads', function () {
    $expired = pruneUpload('media/expired.jpg', MkUploadStatus::Started, expiresIn: -5);
    $live = pruneUpload('media/live.jpg', MkUploadStatus::Started, expiresIn: 60);

    [$code] = runUploadsPrune();

    $abort = $this->s3->named('AbortMultipartUpload');
    expect($code)->toBe(0)
        ->and(uploadStatus($expired))->toBe(MkUploadStatus::Aborted)
        ->and(uploadStatus($live))->toBe(MkUploadStatus::Started)
        ->and($abort)->toHaveCount(1)
        ->and($abort[0]['Key'])->toBe('media/expired.jpg')
        ->and($abort[0]['UploadId'])->toBe('mp-media/expired.jpg')
        // Una Started no tiene objeto: sólo partes.
        ->and($this->s3->named('DeleteObject'))->toBe([]);
});

test('cleans stale completing rows', function () {
    // Murió después de unir las partes: el multipart ya no existe, el objeto sí.
    $this->s3->responses['AbortMultipartUpload'] = fn (CommandInterface $c) => new S3Exception('gone', $c, ['code' => 'NoSuchUpload']);
    $stale = pruneUpload('media/stale.jpg', MkUploadStatus::Completing, expiresIn: -120, touchedAgo: 180);
    // Vencida, pero un complete() la tomó hace 10 minutos: puede estar uniendo un video.
    $working = pruneUpload('media/working.mp4', MkUploadStatus::Completing, expiresIn: -1, touchedAgo: 10);

    [$code] = runUploadsPrune();

    expect($code)->toBe(0)
        ->and(uploadStatus($stale))->toBe(MkUploadStatus::Aborted)
        ->and(uploadStatus($working))->toBe(MkUploadStatus::Completing)
        ->and(s3Keys($this->s3, 'AbortMultipartUpload'))->toBe(['media/stale.jpg'])
        ->and(s3Keys($this->s3, 'DeleteObject'))->toBe(['media/stale.jpg']);
});

test('deletes unattached completed after 24h', function () {
    $forgotten = pruneUpload('media/forgotten.jpg', MkUploadStatus::Completed, expiresIn: -2000, touchedAgo: 25 * 60);
    $recent = pruneUpload('media/recent.jpg', MkUploadStatus::Completed, expiresIn: -1000, touchedAgo: 23 * 60);

    [$code] = runUploadsPrune();

    expect($code)->toBe(0)
        ->and(uploadStatus($forgotten))->toBe(MkUploadStatus::Aborted)
        ->and(uploadStatus($recent))->toBe(MkUploadStatus::Completed)
        ->and(s3Keys($this->s3, 'DeleteObject'))->toBe(['media/forgotten.jpg'])
        ->and($this->s3->named('AbortMultipartUpload'))->toBe([]);
});

test('--hours overrides the 24 h, and less than 1 is refused', function () {
    $twoHours = pruneUpload('media/two-hours.jpg', MkUploadStatus::Completed, expiresIn: 60, touchedAgo: 120);

    expect(runUploadsPrune(['--hours' => '0'])[0])->toBe(1)
        ->and(uploadStatus($twoHours))->toBe(MkUploadStatus::Completed);

    runUploadsPrune(['--hours' => '1']);

    expect(uploadStatus($twoHours))->toBe(MkUploadStatus::Aborted);
});

test('leaves attached ones', function () {
    $attached = pruneUpload('media/attached.jpg', MkUploadStatus::Attached, expiresIn: -9000, touchedAgo: 9000);
    $aborted = pruneUpload('media/aborted.jpg', MkUploadStatus::Aborted, expiresIn: -9000, touchedAgo: 9000);

    runUploadsPrune();

    expect(uploadStatus($attached))->toBe(MkUploadStatus::Attached)
        ->and(uploadStatus($aborted))->toBe(MkUploadStatus::Aborted)
        ->and($this->s3->commands)->toBe([]);
});

test('a storage failure leaves the row as it was, for the next run', function () {
    $this->s3->responses['AbortMultipartUpload'] = fn (CommandInterface $c) => new S3Exception('denied', $c, ['code' => 'AccessDenied']);
    $expired = pruneUpload('media/expired.jpg', MkUploadStatus::Started, expiresIn: -5);

    [$code, $out] = runUploadsPrune();

    expect($code)->toBe(1)
        ->and(uploadStatus($expired))->toBe(MkUploadStatus::Started)
        ->and($out)->toContain('no se pudieron limpiar');
});

test('dry run changes nothing', function () {
    $started = pruneUpload('media/expired.jpg', MkUploadStatus::Started, expiresIn: -5);
    $completing = pruneUpload('media/stale.jpg', MkUploadStatus::Completing, expiresIn: -120, touchedAgo: 180);
    $completed = pruneUpload('media/forgotten.jpg', MkUploadStatus::Completed, expiresIn: -2000, touchedAgo: 25 * 60);

    [$code, $out] = runUploadsPrune(['--dry-run' => true]);

    expect($code)->toBe(0)
        ->and(uploadStatus($started))->toBe(MkUploadStatus::Started)
        ->and(uploadStatus($completing))->toBe(MkUploadStatus::Completing)
        ->and(uploadStatus($completed))->toBe(MkUploadStatus::Completed)
        ->and($this->s3->commands)->toBe([])
        ->and($out)->toContain('Se limpiarían 1 subidas vencidas sin terminar')
        ->and($out)->toContain('Se limpiarían 1 subidas trabadas verificando')
        ->and($out)->toContain('Se limpiarían 1 subidas sin adjuntar en 24 h');
});
