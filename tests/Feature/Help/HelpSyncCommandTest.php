<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Mk\Director\Console\Commands\HelpSyncCommand;
use Mk\Director\Help\Models\MkHelpArticle;
use Mk\Director\Tests\Concerns\UsesDatabase;
use Mk\Director\Tests\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * `mk:help:sync`: la carpeta de los manuales → `mk_help_articles`.
 *
 * Se afirma lo que quedó en la BASE, no el texto de la salida. Cada test
 * trabaja sobre una copia de los fixtures, porque edita y borra archivos.
 */
uses(TestCase::class, UsesDatabase::class);

beforeEach(function () {
    $this->setUpDatabase();
    $this->runPackageMigration('2026_10_22_000001_create_mk_help_articles_table.php');

    $this->helpRoot = sys_get_temp_dir().'/mk-help-sync-'.bin2hex(random_bytes(4));
    $source = __DIR__.'/../../Fixtures/help';
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS)) as $file) {
        $target = $this->helpRoot.substr($file->getPathname(), strlen($source));
        is_dir(dirname($target)) || mkdir(dirname($target), 0777, true);
        copy($file->getPathname(), $target);
    }

    config()->set('mk_director.help', ['content_path' => $this->helpRoot, 'articles_table' => 'mk_help_articles']);
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
    unlink($this->helpRoot.'/business/glosario.md');

    [$code] = runHelpSync();

    expect($code)->toBe(0)
        ->and(helpHashes())->not->toHaveKey('business/glosario')
        ->and(helpHashes())->toHaveCount(7);
});

test('an invalid file fails and writes nothing', function () {
    runHelpSync();
    $before = helpHashes();
    file_put_contents($this->helpRoot.'/member/retirar.md', "---\ntitle: Otro\naudience: member\n---\nCambio válido.\n");
    unlink($this->helpRoot.'/business/glosario.md');
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
    unlink($this->helpRoot.'/business/glosario.md');
    runHelpSync(['--dry-run' => true]);

    expect(helpHashes())->toHaveKey('business/glosario');
});
