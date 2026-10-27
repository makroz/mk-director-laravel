<?php

declare(strict_types=1);

namespace Mk\Director\Help;

use FilesystemIterator;
use Mk\Director\Help\Models\MkHelpArticle;
use Mk\Director\Storage\MkStorage;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * La carpeta de los manuales (`mk_director.help.content_path`) → `mk_help_articles`
 * y sus imágenes → el rol PÚBLICO del storage.
 *
 * 🔴 TODO O NADA: si un solo archivo falla, no se escribe nada. Un manual a
 * medio indexar se ve completo y miente. Falla con un archivo inválido, un
 * `related` o un enlace a un artículo que no existe
 * ({@see MkHelpParser::articleLinks()}), una pantalla que no está en
 * `screens.yaml` o una imagen que no se pudo subir. Con todo válido, una transacción crea o
 * actualiza por `slug` lo que cambió y borra los slugs que ya no están en disco.
 *
 * Las imágenes se suben ANTES de la transacción y por contenido
 * (`<image_prefix>/<sha1>.<ext>`): una que ya está no se vuelve a subir, y una
 * subida huérfana de una corrida que falló después no pisa nada.
 *
 * `screens.yaml` (en la raíz de `content_path`) son las pantallas que conocen
 * los fronts, `clave: descripción`. Sin ese archivo ningún artículo puede
 * listar `screens`.
 */
final class MkHelpSync
{
    public function __construct(
        private readonly MkHelpParser $parser,
        private readonly MkStorage $storage,
    ) {}

    public function run(bool $dryRun = false): MkHelpSyncReport
    {
        $root = (string) config('mk_director.help.content_path');

        if (! is_dir($root)) {
            return new MkHelpSyncReport(errors: ["No existe la carpeta de los manuales: {$root}"]);
        }

        /** @var array<string, MkHelpDocument> $docs */
        $docs = [];
        $errors = [];

        foreach (self::files($root) as $file) {
            try {
                $doc = $this->parser->parse($root, $file);
            } catch (MkHelpContentException $e) {
                $errors[] = $e->getMessage();

                continue;
            }

            if (isset($docs[$doc->slug])) {
                $errors[] = "Dos archivos dan el mismo slug `{$doc->slug}` (un `x.md` y un `x/index.md`)";

                continue;
            }

            $docs[$doc->slug] = $doc;
        }

        foreach ($docs as $doc) {
            if ($doc->parentSlug !== null && ! isset($docs[$doc->parentSlug])) {
                $errors[] = "`{$doc->slug}`: a su carpeta le falta el `index.md` (`{$doc->parentSlug}/index.md`)";
            }

            foreach ($doc->related as $related) {
                if (! isset($docs[$related])) {
                    $errors[] = "`{$doc->slug}`: el relacionado `{$related}` no existe";
                }
            }

            foreach (MkHelpParser::articleLinks($doc->body) as $href => $link) {
                if (! isset($docs[$link])) {
                    $errors[] = "`{$doc->slug}`: el enlace `{$href}` no lleva a ningún artículo (se escribe con el slug, p. ej. `admin/retiros/pagar`)";
                }
            }
        }

        $screens = $this->screens($root, $errors);
        $unknownScreens = [];

        foreach ($docs as $doc) {
            foreach ($doc->screens as $screen) {
                if ($screens === null) {
                    $errors[] = "`{$doc->slug}`: lista `screens` pero no existe `screens.yaml` en la carpeta de los manuales";

                    break;
                }

                if (! array_key_exists($screen, $screens)) {
                    $unknownScreens[] = "{$doc->slug}: {$screen}";
                }
            }
        }

        if ($errors !== [] || $unknownScreens !== []) {
            return new MkHelpSyncReport(errors: $errors, unknownScreens: $unknownScreens);
        }

        $listed = array_merge([], ...array_values(array_map(fn (MkHelpDocument $d) => $d->screens, $docs)));
        $uncovered = array_values(array_diff(array_keys($screens ?? []), $listed));

        $disk = $this->storage->diskName('public');
        $prefix = trim((string) config('mk_director.help.image_prefix', 'help'), '/');
        /** @var array<string, array{key: string, file: string, url: string, width: int, height: int}> $files */
        $files = [];
        /** @var array<string, array{body: string, text: string, images: list<array{url: string, width: int, height: int}>, hash: string}> $rendered */
        $rendered = [];

        foreach ($docs as $slug => $doc) {
            $rendered[$slug] = $this->render($doc, $disk, $prefix, $files);
        }

        $existing = MkHelpArticle::query()->pluck('content_hash', 'slug')->all();
        $changed = array_filter($rendered, fn (array $r, string $slug) => ($existing[$slug] ?? null) !== $r['hash'], ARRAY_FILTER_USE_BOTH);
        $gone = array_values(array_diff(array_keys($existing), array_keys($docs)));
        $created = count(array_diff_key($changed, $existing));

        // Sólo las imágenes de lo que cambió: lo que no cambió ya las subió.
        $needed = [];
        foreach (array_keys($changed) as $slug) {
            foreach ($docs[$slug]->imagePaths as $file) {
                $needed[$files[$file]['key']] = $files[$file]['file'];
            }
        }

        $uploaded = 0;
        try {
            foreach ($needed as $key => $file) {
                if ($this->storage->disk('public')->exists($key)) {
                    continue;
                }

                if (! $dryRun) {
                    $this->storage->disk('public')->put($key, (string) file_get_contents($file));
                }
                $uploaded++;
            }
        } catch (Throwable $e) {
            return new MkHelpSyncReport(errors: ["No se pudo subir una imagen al storage `{$disk}`: {$e->getMessage()}"]);
        }

        if (! $dryRun) {
            MkHelpArticle::query()->getConnection()->transaction(function () use ($changed, $docs, $gone): void {
                foreach ($changed as $slug => $r) {
                    $doc = $docs[$slug];
                    MkHelpArticle::query()->updateOrCreate(['slug' => $slug], [
                        'audience' => $doc->audience,
                        'ability' => $doc->ability,
                        'title' => $doc->title,
                        'body' => $r['body'],
                        'body_text' => $r['text'],
                        'images' => $r['images'],
                        'screens' => $doc->screens,
                        'related' => $doc->related,
                        'parent_slug' => $doc->parentSlug,
                        'order' => $doc->order,
                        'content_hash' => $r['hash'],
                    ]);
                }

                if ($gone !== []) {
                    MkHelpArticle::query()->whereIn('slug', $gone)->delete();
                }
            });
        }

        return new MkHelpSyncReport(
            created: $created,
            updated: count($changed) - $created,
            deleted: count($gone),
            unchanged: count($docs) - count($changed),
            imagesUploaded: $uploaded,
            uncoveredScreens: $uncovered,
        );
    }

    /**
     * El cuerpo con cada imagen apuntando a su URL pública, sus medidas, su
     * texto plano y el hash de lo que se guarda: cubre el archivo, las URLs y
     * el texto, así que cambiar una imagen (otro sha1), el disk público o
     * {@see self::plainText()} reescribe el artículo.
     *
     * @param  array<string, array{key: string, file: string, url: string, width: int, height: int}>  $files  cache por archivo
     * @return array{body: string, text: string, images: list<array{url: string, width: int, height: int}>, hash: string}
     */
    private function render(MkHelpDocument $doc, string $disk, string $prefix, array &$files): array
    {
        $byRef = [];
        foreach ($doc->imagePaths as $ref => $file) {
            if (! isset($files[$file])) {
                $key = ($prefix === '' ? '' : $prefix.'/').sha1_file($file).'.'.strtolower(pathinfo($file, PATHINFO_EXTENSION));
                [$width, $height] = getimagesize($file) ?: [0, 0];
                $files[$file] = [
                    'key' => $key,
                    'file' => $file,
                    'url' => (string) $this->storage->url($disk, $key),
                    'width' => $width,
                    'height' => $height,
                ];
            }
            $byRef[$ref] = $files[$file];
        }

        // Por posición, no con `str_replace`: el alt puede repetir la ruta.
        $body = $byRef === [] ? $doc->body : (string) preg_replace_callback(
            MkHelpParser::IMAGE_PATTERN,
            fn (array $m) => substr_replace($m[0][0], $byRef[$m[1][0]]['url'], $m[1][1] - $m[0][1], strlen($m[1][0])),
            $doc->body,
            flags: PREG_OFFSET_CAPTURE,
        );

        $images = array_values(array_map(
            fn (array $f) => ['url' => $f['url'], 'width' => $f['width'], 'height' => $f['height']],
            $byRef,
        ));

        $text = self::plainText($body);

        return ['body' => $body, 'text' => $text, 'images' => $images, 'hash' => hash('sha256', $doc->hash."\n".$body."\n".$text)];
    }

    /**
     * Markdown → texto plano en una línea, para el fragmento de la búsqueda y
     * el `LIKE`: sin marcas, sin HTML y sin URLs (de una imagen queda el alt;
     * de un enlace, su texto). No es un parser: alcanza para que se lea.
     *
     * Saca también los caracteres de control: {@see MkHelpCatalog} usa dos de
     * ellos para marcar el resultado, así que el texto no puede traerlos.
     */
    public static function plainText(string $markdown): string
    {
        $text = (string) preg_replace([
            '/\\\\([\\\\`*_{}\[\]()#+\-.!|>])/',      // escapes: `\*` → `*`
            '/<!--.*?-->/s',                          // comentarios HTML
            '/^\s*(```|~~~).*$/m',                   // vallas de código (el código queda)
            '/^\s{0,3}\[[^\]]+\]:\s+\S+.*$/m',          // definiciones de enlaces
            '/!\[([^\]]*)\]\([^)]*\)/',                // imagen → su alt
            '/\[([^\]]*)\](\([^)]*\)|\[[^\]]*\])/',      // enlace → su texto
            '/<(https?:\/\/[^>\s]+)>/',                // autoenlace → la URL
            '/<\/?[a-z][^>]*>/i',                     // etiquetas HTML
            '/^\s{0,3}#{1,6}\s+/m',                   // títulos
            '/^\s{0,3}>+\s?/m',                       // citas
            '/^\s*([-*_]\s*){3,}$/m',                  // separadores
            '/^\s*([-*+]|\d+[.)])\s+/m',              // listas
            '/\*\*|__|~~|`|\*|(?<!\w)_|_(?!\w)/',       // énfasis y código
            '/\|/',                                   // tablas
        ], [
            '$1', '', '', '', '$1', '$1', '$1', ' ', '', '', '', '', '', ' ',
        ], $markdown);

        // Los de control, después de las entidades: `&#2;` también es uno.
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string) preg_replace('/[\x00-\x1F\x7F]/u', ' ', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * `screens.yaml`: clave → descripción, o null si no existe.
     *
     * @param  list<string>  $errors
     * @return array<string, string>|null
     */
    private function screens(string $root, array &$errors): ?array
    {
        $file = rtrim($root, '/').'/screens.yaml';

        if (! is_file($file)) {
            return null;
        }

        try {
            $screens = Yaml::parseFile($file);
        } catch (ParseException $e) {
            $errors[] = "screens.yaml inválido: {$e->getMessage()}";

            return [];
        }

        $screens ??= [];

        if (! is_array($screens) || array_is_list($screens) && $screens !== []
            || array_filter($screens, fn ($v, $k) => ! is_string($k) || ! is_string($v), ARRAY_FILTER_USE_BOTH) !== []) {
            $errors[] = 'screens.yaml tiene que ser una lista de `clave: descripción`';

            return [];
        }

        return $screens;
    }

    /**
     * Los `.md` de la carpeta, en orden estable. El `README.md` de la RAÍZ no es
     * un artículo: son las instrucciones para quien escribe los manuales.
     *
     * @return list<string>
     */
    public static function files(string $root): array
    {
        $files = [];
        $readme = rtrim(str_replace('\\', '/', $root), '/').'/README.md';
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'md' && str_replace('\\', '/', $file->getPathname()) !== $readme) {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }
}
