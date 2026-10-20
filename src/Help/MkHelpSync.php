<?php

declare(strict_types=1);

namespace Mk\Director\Help;

use FilesystemIterator;
use Mk\Director\Help\Models\MkHelpArticle;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * La carpeta de los manuales (`mk_director.help.content_path`) → `mk_help_articles`.
 *
 * 🔴 TODO O NADA: si un solo archivo falla, no se escribe nada. Un manual a
 * medio indexar se ve completo y miente. Con todo válido, una transacción
 * crea o actualiza por `slug` lo que cambió de hash y borra los slugs que ya
 * no están en disco.
 */
final class MkHelpSync
{
    public function __construct(private readonly MkHelpParser $parser) {}

    public function run(bool $dryRun = false): MkHelpSyncReport
    {
        $root = (string) config('mk_director.help.content_path');

        if (! is_dir($root)) {
            return new MkHelpSyncReport(0, 0, 0, 0, ["No existe la carpeta de los manuales: {$root}"]);
        }

        /** @var array<string, MkHelpDocument> $docs */
        $docs = [];
        $errors = [];

        foreach ($this->files($root) as $file) {
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
        }

        if ($errors !== []) {
            return new MkHelpSyncReport(0, 0, 0, 0, $errors);
        }

        $existing = MkHelpArticle::query()->pluck('content_hash', 'slug')->all();
        $changed = array_filter($docs, fn (MkHelpDocument $d) => ($existing[$d->slug] ?? null) !== $d->hash);
        $gone = array_values(array_diff(array_keys($existing), array_keys($docs)));
        $created = count(array_diff_key($changed, $existing));

        if (! $dryRun) {
            MkHelpArticle::query()->getConnection()->transaction(function () use ($changed, $gone): void {
                foreach ($changed as $doc) {
                    MkHelpArticle::query()->updateOrCreate(['slug' => $doc->slug], [
                        'audience' => $doc->audience,
                        'ability' => $doc->ability,
                        'title' => $doc->title,
                        'body' => $doc->body,
                        'screens' => $doc->screens,
                        'related' => $doc->related,
                        'parent_slug' => $doc->parentSlug,
                        'order' => $doc->order,
                        'content_hash' => $doc->hash,
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
        );
    }

    /** @return list<string> los `.md` de la carpeta, en orden estable */
    private function files(string $root): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'md') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }
}
