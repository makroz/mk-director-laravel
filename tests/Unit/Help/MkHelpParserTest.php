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
