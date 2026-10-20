<?php

declare(strict_types=1);

use Aws\CommandInterface;
use Aws\S3\Exception\S3Exception;
use GuzzleHttp\Psr7\Response;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Storage;
use Mk\Director\Console\Commands\HelpSyncCommand;
use Mk\Director\Help\MkHelpParser;
use Mk\Director\Help\MkHelpSync;
use Mk\Director\Help\Models\MkHelpArticle;
use Mk\Director\Storage\MkStorage;
use Mk\Director\Tests\Concerns\FakeS3;
use Mk\Director\Tests\Concerns\UsesDatabase;
use Mk\Director\Tests\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * `mk:help:sync`: la carpeta de los manuales → `mk_help_articles`.
 *
 * Se afirma lo que quedó en la BASE y lo que llegó al storage (un disk `s3`
 * REAL sobre {@see FakeS3}), no el texto de la salida. Cada test trabaja sobre
 * una copia de los fixtures, porque edita y borra archivos.
 */
uses(TestCase::class, UsesDatabase::class);

beforeEach(function () {
    $this->setUpDatabase();
    $this->runPackageMigration('2026_10_22_000001_create_mk_help_articles_table.php');
    $this->runPackageMigration('2026_10_22_000002_add_images_to_mk_help_articles_table.php');

    $this->helpRoot = sys_get_temp_dir().'/mk-help-sync-'.bin2hex(random_bytes(4));
    $source = __DIR__.'/../../Fixtures/help';
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS)) as $file) {
        $target = $this->helpRoot.substr($file->getPathname(), strlen($source));
        is_dir(dirname($target)) || mkdir(dirname($target), 0777, true);
        copy($file->getPathname(), $target);
    }

    config()->set('mk_director.help', [
        'content_path' => $this->helpRoot, 'articles_table' => 'mk_help_articles', 'image_prefix' => 'help',
    ]);
    config()->set('mk_director.storage', ['public_disk' => 'r2_public']);

    // Un bucket que recuerda lo que se le subió: `HeadObject` da 404 para lo demás.
    $this->s3 = FakeS3::disk('r2_public');
    $this->s3->responses['HeadObject'] = fn (CommandInterface $c) => in_array($c['Key'], helpPutKeys($this->s3), true)
        ? ['ContentLength' => 1]
        : new S3Exception('not found', $c, ['code' => 'NotFound', 'response' => new Response(404)]);
});

afterEach(fn () => $this->tearDownDatabase());

/** @return array{0: int, 1: string} */
function runHelpSync(array $options = []): array
{
    $command = new HelpSyncCommand;
    // El Container pelado de MkLaravelTestCase no tiene `runningUnitTests()`.
    $container = new class extends Container
    {
        public function runningUnitTests(): bool
        {
            return true;
        }
    };
    $command->setLaravel($container);
    $output = new BufferedOutput;

    return [$command->run(new ArrayInput($options), $output), $output->fetch()];
}

/** @return list<string> las claves que llegaron al bucket */
function helpPutKeys(FakeS3 $s3): array
{
    return array_map(fn (CommandInterface $c) => (string) $c['Key'], $s3->named('PutObject'));
}

/** Una imagen PNG de 4×3 en `$relative` dentro de la copia del manual; devuelve su sha1. */
function helpImage(string $root, string $relative): string
{
    $image = imagecreatetruecolor(4, 3);
    is_dir(dirname($root.'/'.$relative)) || mkdir(dirname($root.'/'.$relative), 0777, true);
    imagepng($image, $root.'/'.$relative);

    return sha1_file($root.'/'.$relative);
}

/** Agrega `$line` al final del cuerpo de `$relative`. */
function helpAppend(string $root, string $relative, string $line): void
{
    file_put_contents($root.'/'.$relative, "\n{$line}\n", FILE_APPEND);
}

/** Reemplaza `$from` por `$to` en `$relative`. */
function helpEdit(string $root, string $relative, string $from, string $to): void
{
    $content = (string) file_get_contents($root.'/'.$relative);
    expect($content)->toContain($from);
    file_put_contents($root.'/'.$relative, str_replace($from, $to, $content));
}

/** @return array<string, string> slug => content_hash */
function helpHashes(): array
{
    return MkHelpArticle::query()->orderBy('slug')->pluck('content_hash', 'slug')->all();
}

/** @return array<string, string> slug => updated_at */
function helpStamps(): array
{
    return MkHelpArticle::query()->pluck('updated_at', 'slug')->map(fn ($d) => (string) $d)->all();
}

test('first run creates every article', function () {
    [$code, $out] = runHelpSync();

    $entrar = MkHelpArticle::query()->where('slug', 'admin/primeros-pasos/entrar')->sole();

    expect($code)->toBe(0)
        ->and(array_keys(helpHashes()))->toBe([
            'admin', 'admin/primeros-pasos', 'admin/primeros-pasos/aprobar', 'admin/primeros-pasos/entrar',
            'business', 'business/glosario', 'member', 'member/retirar',
        ])
        ->and($entrar->audience)->toBe('admin')
        ->and($entrar->parent_slug)->toBe('admin/primeros-pasos')
        ->and($entrar->screens)->toBe(['admin.login'])
        ->and($entrar->related)->toBe(['business/glosario'])
        ->and($entrar->order)->toBe(10)
        ->and($out)->toContain('8');
});

test('second run leaves everything unchanged', function () {
    runHelpSync();
    MkHelpArticle::query()->update(['updated_at' => '2020-01-01 00:00:00']);

    [$code, $out] = runHelpSync();

    expect($code)->toBe(0)
        ->and(array_unique(array_values(helpStamps())))->toBe(['2020-01-01 00:00:00'])
        ->and($out)->toMatch('/\|\s+0\s+\|\s+0\s+\|\s+0\s+\|\s+8\s+\|/');
});

test('an edited file updates only that article', function () {
    runHelpSync();
    MkHelpArticle::query()->update(['updated_at' => '2020-01-01 00:00:00']);
    file_put_contents($this->helpRoot.'/member/retirar.md', "---\ntitle: Pedir un retiro\naudience: member\n---\nNuevo texto.\n");

    [$code] = runHelpSync();
    $stamps = helpStamps();
    $retirar = MkHelpArticle::query()->where('slug', 'member/retirar')->sole();

    expect($code)->toBe(0)
        ->and($retirar->body)->toBe("Nuevo texto.\n")
        ->and($retirar->order)->toBe(0)
        ->and($stamps['member/retirar'])->not->toBe('2020-01-01 00:00:00')
        ->and(array_unique(array_values(array_diff_key($stamps, ['member/retirar' => 1]))))->toBe(['2020-01-01 00:00:00']);
});

test('a deleted file deletes its article', function () {
    runHelpSync();
    unlink($this->helpRoot.'/admin/primeros-pasos/aprobar.md');

    [$code] = runHelpSync();

    expect($code)->toBe(0)
        ->and(helpHashes())->not->toHaveKey('admin/primeros-pasos/aprobar')
        ->and(helpHashes())->toHaveCount(7);
});

test('an invalid file fails and writes nothing', function () {
    runHelpSync();
    $before = helpHashes();
    file_put_contents($this->helpRoot.'/member/retirar.md', "---\ntitle: Otro\naudience: member\n---\nCambio válido.\n");
    unlink($this->helpRoot.'/admin/primeros-pasos/aprobar.md');
    file_put_contents($this->helpRoot.'/member/roto.md', "---\naudience: admin\n---\nsin título y en otra carpeta\n");

    [$code, $out] = runHelpSync();

    expect($code)->toBe(1)
        ->and($out)->toContain('member/roto.md')
        // Ni el cambio válido ni el borrado entraron: todo o nada.
        ->and(helpHashes())->toBe($before);
});

test('a folder without index fails and writes nothing', function () {
    mkdir($this->helpRoot.'/admin/huerfana');
    file_put_contents($this->helpRoot.'/admin/huerfana/x.md', "---\ntitle: X\naudience: admin\n---\nx\n");

    [$code, $out] = runHelpSync();

    expect($code)->toBe(1)
        ->and($out)->toContain('admin/huerfana/index.md')
        ->and(MkHelpArticle::query()->count())->toBe(0);
});

test('two files with the same slug fail', function () {
    file_put_contents($this->helpRoot.'/admin/primeros-pasos.md', "---\ntitle: Duplicado\naudience: admin\n---\nx\n");

    [$code, $out] = runHelpSync();

    expect($code)->toBe(1)
        ->and($out)->toContain('admin/primeros-pasos')
        ->and(MkHelpArticle::query()->count())->toBe(0);
});

test('dry run writes nothing', function () {
    [$code, $out] = runHelpSync(['--dry-run' => true]);

    expect($code)->toBe(0)
        ->and($out)->toContain('--dry-run')
        ->and(MkHelpArticle::query()->count())->toBe(0);

    runHelpSync();
    unlink($this->helpRoot.'/admin/primeros-pasos/aprobar.md');
    runHelpSync(['--dry-run' => true]);

    expect(helpHashes())->toHaveKey('admin/primeros-pasos/aprobar');
});

test('first run uploads each image once, by content, to the public role', function () {
    $sha = helpImage($this->helpRoot, 'admin/primeros-pasos/img/captura.png');
    // La misma imagen en dos artículos (y repetida en uno): se sube UNA vez.
    helpAppend($this->helpRoot, 'admin/primeros-pasos/entrar.md', '![La entrada](img/captura.png) y otra vez ![](img/captura.png)');
    helpAppend($this->helpRoot, 'admin/primeros-pasos/aprobar.md', '![La entrada](img/captura.png)');

    [$code, $out] = runHelpSync();

    expect($code)->toBe(0)
        ->and(helpPutKeys($this->s3))->toBe(["help/{$sha}.png"])
        ->and($out)->toMatch('/\\|\\s+8\\s+\\|\\s+0\\s+\\|\\s+0\\s+\\|\\s+0\\s+\\|\\s+1\\s+\\|/');
});

test('second run uploads nothing', function () {
    helpImage($this->helpRoot, 'admin/primeros-pasos/img/captura.png');
    helpAppend($this->helpRoot, 'admin/primeros-pasos/entrar.md', '![La entrada](img/captura.png)');
    runHelpSync();
    $commands = count($this->s3->commands);

    [$code, $out] = runHelpSync();

    expect($code)->toBe(0)
        ->and(count($this->s3->commands))->toBe($commands)
        ->and(helpPutKeys($this->s3))->toHaveCount(1)
        ->and($out)->toMatch('/\\|\\s+0\\s+\\|\\s+0\\s+\\|\\s+0\\s+\\|\\s+8\\s+\\|\\s+0\\s+\\|/');
});

test('an image already in the bucket is not uploaded again', function () {
    $sha = helpImage($this->helpRoot, 'admin/primeros-pasos/img/captura.png');
    helpAppend($this->helpRoot, 'admin/primeros-pasos/entrar.md', '![La entrada](img/captura.png)');
    $this->s3->responses['HeadObject'] = ['ContentLength' => 1];

    [$code] = runHelpSync();

    expect($code)->toBe(0)
        ->and(helpPutKeys($this->s3))->toBe([])
        ->and(array_map(fn (CommandInterface $c) => $c['Key'], $this->s3->named('HeadObject')))->toBe(["help/{$sha}.png"]);
});

test('a missing image fails and writes nothing', function () {
    helpImage($this->helpRoot, 'admin/primeros-pasos/img/captura.png');
    helpAppend($this->helpRoot, 'admin/primeros-pasos/aprobar.md', '![Bien](img/captura.png)');
    helpAppend($this->helpRoot, 'admin/primeros-pasos/entrar.md', '![Falta](img/no-existe.png)');

    [$code, $out] = runHelpSync();

    expect($code)->toBe(1)
        ->and($out)->toContain('img/no-existe.png')
        ->and(MkHelpArticle::query()->count())->toBe(0)
        ->and($this->s3->commands)->toBe([]);
});

test('the body points at the public url and the article stores the image size', function () {
    $sha = helpImage($this->helpRoot, 'admin/primeros-pasos/img/captura.png');
    helpAppend($this->helpRoot, 'admin/primeros-pasos/entrar.md', '![img/captura.png](img/captura.png "La entrada")');

    runHelpSync();
    $entrar = MkHelpArticle::query()->where('slug', 'admin/primeros-pasos/entrar')->sole();
    $url = Storage::disk('r2_public')->url("help/{$sha}.png");

    expect($url)->toStartWith('http')
        // El alt repite la ruta a propósito: sólo se reescribe la ruta.
        ->and($entrar->body)->toContain("![img/captura.png]({$url} \"La entrada\")")
        ->and($entrar->images)->toBe([['url' => $url, 'width' => 4, 'height' => 3]]);
});

test('changing an image rewrites only the articles that show it', function () {
    helpImage($this->helpRoot, 'admin/primeros-pasos/img/captura.png');
    helpAppend($this->helpRoot, 'admin/primeros-pasos/entrar.md', '![La entrada](img/captura.png)');
    runHelpSync();
    MkHelpArticle::query()->update(['updated_at' => '2020-01-01 00:00:00']);

    $image = imagecreatetruecolor(8, 6);
    imagepng($image, $this->helpRoot.'/admin/primeros-pasos/img/captura.png');
    runHelpSync();
    $stamps = helpStamps();

    expect(MkHelpArticle::query()->where('slug', 'admin/primeros-pasos/entrar')->sole()->images[0]['width'])->toBe(8)
        ->and(array_keys(array_filter($stamps, fn ($s) => $s !== '2020-01-01 00:00:00')))->toBe(['admin/primeros-pasos/entrar'])
        ->and(helpPutKeys($this->s3))->toHaveCount(2);
});

test('an unknown related fails and writes nothing', function () {
    helpEdit($this->helpRoot, 'admin/primeros-pasos/entrar.md', 'related: [business/glosario]', 'related: [business/no-existe]');

    [$code, $out] = runHelpSync();

    expect($code)->toBe(1)
        ->and($out)->toContain('business/no-existe')
        ->and(MkHelpArticle::query()->count())->toBe(0);
});

test('an unknown screen fails and writes nothing', function () {
    runHelpSync();
    $before = helpHashes();
    helpEdit($this->helpRoot, 'admin/primeros-pasos/entrar.md', 'screens: [admin.login]', 'screens: [admin.login, admin.no-existe]');
    unlink($this->helpRoot.'/admin/primeros-pasos/aprobar.md');

    [$code, $out] = runHelpSync();
    $report = (new MkHelpSync(new MkHelpParser, new MkStorage))->run();

    expect($code)->toBe(1)
        ->and($out)->toContain('admin/primeros-pasos/entrar: admin.no-existe')
        ->and($report->unknownScreens)->toBe(['admin/primeros-pasos/entrar: admin.no-existe'])
        ->and($report->failed())->toBeTrue()
        // Ni el borrado ni el cambio válido entraron.
        ->and(helpHashes())->toBe($before);
});

test('screens without a screens.yaml fail', function () {
    unlink($this->helpRoot.'/screens.yaml');

    [$code, $out] = runHelpSync();

    expect($code)->toBe(1)
        ->and($out)->toContain('screens.yaml')
        ->and(MkHelpArticle::query()->count())->toBe(0);
});

test('reports the screens no article lists', function () {
    [$code, $out] = runHelpSync();
    $report = (new MkHelpSync(new MkHelpParser, new MkStorage))->run(dryRun: true);

    expect($code)->toBe(0)
        ->and($report->uncoveredScreens)->toBe(['admin.kyc.show'])
        ->and($out)->toContain('Pantallas sin artículo (1)')
        ->and($out)->toContain('admin.kyc.show');
});
