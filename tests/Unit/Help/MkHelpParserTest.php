<?php

declare(strict_types=1);

use Mk\Director\Help\MkHelpContentException;
use Mk\Director\Help\MkHelpParser;

/**
 * El parser de un archivo del manual: front matter, slug desde la ruta y las
 * validaciones que hacen fallar la sync entera.
 */
const HELP_FIXTURES = __DIR__.'/../../Fixtures/help';

/** Escribe `$content` en `$relative` dentro de una carpeta temporal y devuelve [root, file]. */
function helpTempFile(string $relative, string $content): array
{
    $root = sys_get_temp_dir().'/mk-help-parser-'.bin2hex(random_bytes(4));
    $file = $root.'/'.$relative;
    mkdir(dirname($file), 0777, true);
    file_put_contents($file, $content);

    return [$root, $file];
}

test('parses front matter and derives the slug from the path', function () {
    $doc = (new MkHelpParser)->parse(HELP_FIXTURES, HELP_FIXTURES.'/admin/primeros-pasos/entrar.md');

    expect($doc->slug)->toBe('admin/primeros-pasos/entrar')
        ->and($doc->audience)->toBe('admin')
        ->and($doc->title)->toBe('Entrar al panel')
        ->and($doc->order)->toBe(10)
        ->and($doc->ability)->toBeNull()
        ->and($doc->screens)->toBe(['admin.login'])
        ->and($doc->related)->toBe(['business/glosario'])
        ->and($doc->parentSlug)->toBe('admin/primeros-pasos')
        ->and($doc->body)->toBe("Escribí tu correo y tu contraseña.\n");
});

test('an index takes its folder slug and the folder above as parent', function () {
    $parser = new MkHelpParser;

    $section = $parser->parse(HELP_FIXTURES, HELP_FIXTURES.'/admin/primeros-pasos/index.md');
    $top = $parser->parse(HELP_FIXTURES, HELP_FIXTURES.'/admin/index.md');

    expect($section->slug)->toBe('admin/primeros-pasos')
        ->and($section->parentSlug)->toBe('admin')
        ->and($top->slug)->toBe('admin')
        ->and($top->parentSlug)->toBeNull()
        ->and($top->order)->toBe(0);
});

test('rejects an audience that does not match its folder', function () {
    [$root, $file] = helpTempFile('member/retirar.md', "---\ntitle: Retirar\naudience: admin\n---\nx\n");

    (new MkHelpParser)->parse($root, $file);
})->throws(MkHelpContentException::class, 'no coincide con su carpeta');

test('rejects an unknown audience folder', function () {
    [$root, $file] = helpTempFile('staff/x.md', "---\ntitle: X\naudience: staff\n---\nx\n");

    (new MkHelpParser)->parse($root, $file);
})->throws(MkHelpContentException::class, '`audience` tiene que ser una de');

test('rejects a missing title', function () {
    [$root, $file] = helpTempFile('admin/x.md', "---\naudience: admin\n---\nx\n");

    (new MkHelpParser)->parse($root, $file);
})->throws(MkHelpContentException::class, 'falta `title`');

test('rejects a file without front matter', function () {
    [$root, $file] = helpTempFile('admin/x.md', "# Sin encabezado\n");

    (new MkHelpParser)->parse($root, $file);
})->throws(MkHelpContentException::class, 'falta el front matter');

test('same content gives the same hash, other content another', function () {
    [$rootA, $a] = helpTempFile('admin/x.md', "---\ntitle: X\naudience: admin\n---\nuno\n");
    [$rootB, $b] = helpTempFile('admin/x.md', "---\ntitle: X\naudience: admin\n---\nuno\n");
    [$rootC, $c] = helpTempFile('admin/x.md', "---\ntitle: X\naudience: admin\n---\ndos\n");
    $parser = new MkHelpParser;

    expect($parser->parse($rootA, $a)->hash)->toBe($parser->parse($rootB, $b)->hash)
        ->and($parser->parse($rootC, $c)->hash)->not->toBe($parser->parse($rootA, $a)->hash);
});

test('collects images relative to the folder of the file', function () {
    [$root, $file] = helpTempFile('admin/kyc/aprobar.md', "---\ntitle: A\naudience: admin\n---\n![Menú](img/menu.png) ![Otra vez](img/menu.png)\n");
    mkdir($root.'/admin/kyc/img');
    imagepng(imagecreatetruecolor(2, 2), $root.'/admin/kyc/img/menu.png');

    $doc = (new MkHelpParser)->parse($root, $file);

    expect($doc->imagePaths)->toBe(['img/menu.png' => realpath($root.'/admin/kyc/img/menu.png')]);
});

test('rejects an image that does not exist', function () {
    [$root, $file] = helpTempFile('admin/x.md', "---\ntitle: X\naudience: admin\n---\n![Falta](img/falta.png)\n");

    (new MkHelpParser)->parse($root, $file);
})->throws(MkHelpContentException::class, 'no existe la imagen `img/falta.png`');

test('rejects an external image', function () {
    [$root, $file] = helpTempFile('admin/x.md', "---\ntitle: X\naudience: admin\n---\n![Afuera](https://example.com/a.png)\n");

    (new MkHelpParser)->parse($root, $file);
})->throws(MkHelpContentException::class, 'tiene que ser un archivo del manual');

test('rejects an image outside the manuals folder', function () {
    [$root, $file] = helpTempFile('admin/x.md', "---\ntitle: X\naudience: admin\n---\n![Afuera](../../afuera.png)\n");
    imagepng(imagecreatetruecolor(2, 2), dirname($root).'/afuera.png');

    try {
        (new MkHelpParser)->parse($root, $file);
    } finally {
        unlink(dirname($root).'/afuera.png');
    }
})->throws(MkHelpContentException::class, 'fuera de la carpeta de los manuales');

test('rejects a file that is not a png, jpg or webp image', function () {
    [$root, $file] = helpTempFile('admin/x.md', "---\ntitle: X\naudience: admin\n---\n![Texto](notas.png)\n");
    file_put_contents($root.'/admin/notas.png', 'no soy una imagen');

    (new MkHelpParser)->parse($root, $file);
})->throws(MkHelpContentException::class, 'no es una imagen');
