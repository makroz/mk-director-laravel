<?php

declare(strict_types=1);

namespace Mk\Director\Console\Commands;

use Illuminate\Console\Command;
use Mk\Director\Help\MkHelpContentException;
use Mk\Director\Help\MkHelpDocument;
use Mk\Director\Help\MkHelpParser;
use Mk\Director\Help\MkHelpSync;
use Symfony\Component\Process\Process;

/**
 * Qué manual quedó viejo contra el código que describe: los `sources` del front
 * matter (`api:app/Modules/Kyc/**`) contra los commits de cada repo
 * (`mk_director.help.repos`, alias → carpeta) desde la última revisión.
 *
 * La revisión de cada artículo vive en `<content_path>/.reviewed.json`
 * (`{slug: {repo: sha}}`, lo commitea la app) y la escriben `--mark` y
 * `--mark-all` con el HEAD de hoy. Sin marca, la base es el último commit que
 * tocó el `.md` en el repo de los manuales, y en los otros repos el último
 * commit anterior a esa fecha.
 *
 * 🔴 La comparación es por RANGO de SHAs (`<base>..HEAD`), nunca por fecha: una
 * rama que se mergea tarde trae commits con fecha vieja y se perderían.
 *
 * Un repo sin carpeta (p. ej. el admin en el servidor de la API) se avisa y se
 * saltea; nunca rompe.
 */
final class HelpStaleCommand extends Command
{
    protected $signature = 'mk:help:stale
        {--diff= : `git diff --stat` del código de un artículo (su slug)}
        {--mark=* : Marca el artículo como revisado contra el HEAD de cada repo}
        {--mark-all : Marca todos los artículos con `sources`}
        {--fail : Sale con 1 si hay algún artículo desactualizado}';

    protected $description = 'Lista los manuales desactualizados contra el código que describen.';

    private const LOG_LIMIT = 10;

    /** @var array<string, string> alias → carpeta, sólo los repos git que existen */
    private array $repos = [];

    /** @var array<string, string> alias → sha del HEAD */
    private array $heads = [];

    /** @var array<string, string> alias → raíz del repo git */
    private array $tops = [];

    private ?string $helpRepoTop = null;

    public function handle(MkHelpParser $parser): int
    {
        $root = rtrim((string) config('mk_director.help.content_path'), '/');

        if (! is_dir($root)) {
            $this->error("No existe la carpeta de los manuales: {$root}");

            return self::FAILURE;
        }

        $this->loadRepos();
        $this->helpRepoTop = $this->git($root, ['rev-parse', '--show-toplevel']);

        /** @var array<string, array{doc: MkHelpDocument, file: string}> $docs */
        $docs = [];
        $withoutSources = 0;

        foreach (MkHelpSync::files($root) as $file) {
            try {
                $doc = $parser->parse($root, $file);
            } catch (MkHelpContentException $e) {
                $this->warn("Se saltea: {$e->getMessage()}");

                continue;
            }

            if ($doc->sources === []) {
                $withoutSources++;
            } else {
                $docs[$doc->slug] = ['doc' => $doc, 'file' => $file];
            }
        }

        $stampFile = $root.'/.reviewed.json';
        $stamps = is_file($stampFile) ? json_decode((string) file_get_contents($stampFile), true) : [];

        if (! is_array($stamps)) {
            $this->error("{$stampFile} no es un JSON válido");

            return self::FAILURE;
        }

        $marks = $this->option('mark-all') ? array_keys($docs) : (array) $this->option('mark');

        if ($marks !== []) {
            return $this->mark($marks, $docs, $stamps, $stampFile);
        }

        if (is_string($slug = $this->option('diff'))) {
            if (! isset($docs[$slug])) {
                $this->error("`{$slug}` no es un artículo con `sources`");

                return self::FAILURE;
            }

            foreach ($this->sourcesByRepo($docs[$slug]['doc']) as $alias => $paths) {
                $base = $this->base($slug, $alias, $docs[$slug]['file'], $stamps);
                $this->info("{$alias}: ".($base === null ? 'todo el historial' : 'desde '.substr($base, 0, 7)));
                $out = $this->git($this->repos[$alias], [
                    'diff', '--stat', ($base ?? $this->emptyTree($alias)).'..HEAD', '--', ...$paths,
                ]);
                $this->line($out === null ? '  (git falló)' : ($out === '' ? '  sin cambios' : $out));
            }

            return self::SUCCESS;
        }

        $stale = 0;
        foreach ($docs as $slug => ['doc' => $doc, 'file' => $file]) {
            $lines = [];
            foreach ($this->sourcesByRepo($doc) as $alias => $paths) {
                $base = $this->base($slug, $alias, $file, $stamps);
                $log = $this->git($this->repos[$alias], [
                    'log', '--oneline', $base === null ? 'HEAD' : "{$base}..HEAD", '--', ...$paths,
                ]);

                if ($log === null) {
                    $this->warn("`{$slug}`: git falló en el repo `{$alias}` (¿la marca apunta a un commit que ya no existe?)");

                    continue;
                }

                if ($log === '') {
                    continue;
                }

                $commits = explode("\n", $log);
                $lines[] = "    {$alias}: ".count($commits).' commit(s) desde '.($base === null ? 'el inicio' : substr($base, 0, 7));
                foreach (array_slice($commits, 0, self::LOG_LIMIT) as $commit) {
                    $lines[] = "      {$commit}";
                }
                if (count($commits) > self::LOG_LIMIT) {
                    $lines[] = '      … y '.(count($commits) - self::LOG_LIMIT).' más';
                }
            }

            if ($lines !== []) {
                $stale++;
                $this->line("  <comment>{$slug}</comment>");
                foreach ($lines as $line) {
                    $this->line($line);
                }
            }
        }

        $this->line('');
        $this->line("Desactualizados: {$stale} · Al día: ".(count($docs) - $stale)." · Sin `sources`: {$withoutSources}");

        if ($stale > 0) {
            $this->line('Revísalos y márcalos con `php artisan mk:help:stale --mark=<slug>`.');
        }

        return $stale > 0 && $this->option('fail') ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  list<string>  $slugs
     * @param  array<string, array{doc: MkHelpDocument, file: string}>  $docs
     * @param  array<string, mixed>  $stamps
     */
    private function mark(array $slugs, array $docs, array $stamps, string $stampFile): int
    {
        foreach ($slugs as $slug) {
            if (! isset($docs[$slug])) {
                $this->error("`{$slug}` no es un artículo con `sources`");

                return self::FAILURE;
            }
        }

        foreach ($slugs as $slug) {
            $stamp = is_array($stamps[$slug] ?? null) ? $stamps[$slug] : [];
            foreach (array_keys($this->sourcesByRepo($docs[$slug]['doc'])) as $alias) {
                $stamp[$alias] = $this->heads[$alias];
            }
            ksort($stamp);
            $stamps[$slug] = $stamp;
        }

        ksort($stamps);
        file_put_contents($stampFile, json_encode($stamps, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
        $this->info('Marcados como revisados: '.count($slugs).'. Haz commit de '.basename($stampFile).'.');

        return self::SUCCESS;
    }

    /** Los repos de la config que existen y son git; los demás se avisan y se saltean. */
    private function loadRepos(): void
    {
        foreach ((array) config('mk_director.help.repos', []) as $alias => $path) {
            $head = is_string($path) && is_dir($path) ? $this->git($path, ['rev-parse', 'HEAD']) : null;

            if ($head === null) {
                $this->warn("El repo `{$alias}` no está disponible (".(is_string($path) ? $path : 'sin ruta').'): se saltea.');

                continue;
            }

            $this->repos[(string) $alias] = $path;
            $this->heads[(string) $alias] = $head;
            $this->tops[(string) $alias] = (string) $this->git($path, ['rev-parse', '--show-toplevel']);
        }
    }

    /**
     * Las rutas de `sources` por repo, como pathspecs `:(glob)`, sólo de los
     * repos disponibles.
     *
     * @return array<string, list<string>>
     */
    private function sourcesByRepo(MkHelpDocument $doc): array
    {
        $byRepo = [];
        foreach ($doc->sources as $source) {
            [$alias, $path] = explode(':', $source, 2);
            if (isset($this->repos[$alias])) {
                $byRepo[$alias][] = ':(glob)'.ltrim($path, '/');
            }
        }

        return $byRepo;
    }

    /**
     * El commit contra el que se compara: la marca; si no hay, el último commit
     * del `.md` (en el repo de los manuales) o el último anterior a su fecha (en
     * los otros). Un `.md` sin commits es nuevo: la base es el HEAD. Null = el
     * repo no tiene commits anteriores: todo el historial.
     *
     * @param  array<string, mixed>  $stamps
     */
    private function base(string $slug, string $alias, string $file, array $stamps): ?string
    {
        $stamped = $stamps[$slug][$alias] ?? null;

        if (is_string($stamped) && $stamped !== '') {
            return $stamped;
        }

        $dir = dirname($file);
        $last = $this->helpRepoTop === null ? null : $this->git($dir, ['log', '-1', '--format=%H %cI', '--', basename($file)]);

        if ($last === null || $last === '') {
            return $this->heads[$alias];
        }

        [$sha, $date] = explode(' ', $last, 2);

        if ($this->tops[$alias] === $this->helpRepoTop) {
            return $sha;
        }

        return $this->git($this->repos[$alias], ['rev-list', '-1', "--before={$date}", 'HEAD']) ?: null;
    }

    private function emptyTree(string $alias): string
    {
        return (string) $this->git($this->repos[$alias], ['hash-object', '-t', 'tree', '/dev/null']);
    }

    /**
     * @param  list<string>  $args
     * @return string|null la salida sin espacios al borde, o null si git falló
     */
    private function git(string $cwd, array $args): ?string
    {
        $process = new Process(['git', ...$args], $cwd);
        $process->run();

        return $process->isSuccessful() ? trim($process->getOutput()) : null;
    }
}
