<?php

declare(strict_types=1);

namespace Mk\Director\Help;

use Illuminate\Database\Eloquent\Builder;
use Mk\Director\Help\Models\MkHelpArticle;

/**
 * El lado de lectura de los manuales. Filtra SÓLO por audiencia: los permisos
 * nunca esconden un artículo (su `ability` es una nota informativa).
 */
final class MkHelpCatalog
{
    /**
     * Las marcas del acierto mientras viaja por SQL: dos caracteres de control,
     * que `body_text` nunca trae ({@see MkHelpSync::plainText()}). Se escapa todo
     * el fragmento y recién después se cambian por `<mark>`: así la única
     * etiqueta que llega al front es la nuestra.
     */
    private const START = "\x02";

    private const STOP = "\x03";

    private const HEADLINE = 'StartSel='.self::START.',StopSel='.self::STOP.',MaxWords=25,MinWords=10,ShortWord=2,MaxFragments=1';

    /** El largo del fragmento del `LIKE`, y cuánto de él va antes del acierto. */
    private const WINDOW = 160;

    private const LEAD = 60;

    /**
     * El árbol de las audiencias dadas, ordenado por `order` entre hermanos.
     *
     * @param  list<string>  $audiences
     * @return list<array{slug: string, title: string, children: list<array<string, mixed>>}>
     */
    public function tree(array $audiences): array
    {
        $rows = MkHelpArticle::query()
            ->whereIn('audience', $audiences)
            ->orderBy('order')
            ->orderBy('slug')
            ->get(['slug', 'title', 'parent_slug']);

        $children = [];
        foreach ($rows as $row) {
            $children[$row->parent_slug ?? ''][] = $row;
        }

        $known = $rows->pluck('slug')->flip();
        $roots = $rows->filter(fn (MkHelpArticle $r) => $r->parent_slug === null || ! $known->has($r->parent_slug));

        return $this->nodes($roots->all(), $children);
    }

    /** @param  list<string>  $audiences */
    public function article(string $slug, array $audiences): ?MkHelpArticle
    {
        return MkHelpArticle::query()
            ->whereIn('audience', $audiences)
            ->where('slug', $slug)
            ->first();
    }

    /**
     * Los `related` del artículo que el scope ve, con su título y en el orden
     * del árbol (no en el del front matter). Uno de otra audiencia no sale.
     *
     * @param  list<string>  $audiences
     * @return list<array{slug: string, title: string}>
     */
    public function related(MkHelpArticle $article, array $audiences): array
    {
        $wanted = array_flip($article->related ?? []);

        if ($wanted === []) {
            return [];
        }

        $out = [];
        $walk = function (array $nodes) use (&$walk, &$out, $wanted): void {
            foreach ($nodes as $node) {
                if (isset($wanted[$node['slug']])) {
                    $out[] = ['slug' => $node['slug'], 'title' => $node['title']];
                }
                $walk($node['children']);
            }
        };
        $walk($this->tree($audiences));

        return $out;
    }

    /**
     * Busca en los manuales de las audiencias dadas, ordenado por relevancia.
     * `snippet` es HTML con TODO escapado salvo el `<mark>…</mark>` del acierto.
     *
     * - pgsql: la columna generada `search` (título con peso A, texto con peso B,
     *   con y sin tildes) contra `websearch_to_tsquery('spanish', …)`, por
     *   `ts_rank` y después por título; el fragmento es `ts_headline` del texto.
     * - Los demás drivers: `LIKE` sin distinguir mayúsculas en el título y el
     *   texto, por título; el fragmento es una ventana alrededor del primer
     *   acierto. No está afinado: existe para que el paquete se pruebe en sqlite.
     *
     * @param  list<string>  $audiences
     * @return list<array{slug: string, title: string, snippet: string}>
     */
    public function search(string $query, array $audiences, int $limit = 20): array
    {
        $base = MkHelpArticle::query()->whereIn('audience', $audiences)->limit($limit);

        if ($base->getConnection()->getDriverName() === 'pgsql') {
            return $this->tsQuery($base, $query)
                ->get()
                ->map(fn (MkHelpArticle $r) => ['slug' => $r->slug, 'title' => $r->title, 'snippet' => $this->marked((string) $r->getAttribute('snippet'))])
                ->all();
        }

        // Como `LikeSearchStrategy`: los comodines van literales, y `whereLike`
        // no distingue mayúsculas en ningún motor.
        $like = '%'.addcslashes($query, '\\%_').'%';

        return $base
            ->where(fn (Builder $q) => $q->whereLike('title', $like)->orWhereLike('body_text', $like))
            ->orderBy('title')
            ->get(['slug', 'title', 'body_text'])
            ->map(fn (MkHelpArticle $r) => ['slug' => $r->slug, 'title' => $r->title, 'snippet' => $this->window((string) $r->body_text, $query)])
            ->all();
    }

    /** @param  Builder<MkHelpArticle>  $base */
    private function tsQuery(Builder $base, string $query): Builder
    {
        $tsquery = "websearch_to_tsquery('spanish', ?)";

        return $base
            ->selectRaw("slug, title, ts_headline('spanish', coalesce(body_text, body), {$tsquery}, ?) as snippet", [$query, self::HEADLINE])
            ->whereRaw("search @@ {$tsquery}", [$query])
            ->orderByRaw("ts_rank(search, {$tsquery}) desc", [$query])
            ->orderBy('title');
    }

    /** Todo escapado; después, las marcas de control → `<mark>`. */
    private function marked(string $snippet): string
    {
        return str_replace(
            [self::START, self::STOP],
            ['<mark>', '</mark>'],
            htmlspecialchars($snippet, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        );
    }

    /** ~160 caracteres alrededor del primer acierto, con él entre marcas. */
    private function window(string $text, string $query): string
    {
        $pos = mb_stripos($text, $query);
        $total = mb_strlen($text);

        if ($pos === false) {
            // El acierto estaba en el título: el comienzo del texto.
            return $this->marked(mb_substr($text, 0, self::WINDOW).($total > self::WINDOW ? '…' : ''));
        }

        $length = mb_strlen($query);
        $start = max(0, $pos - self::LEAD);
        // Que no arranque a mitad de una palabra.
        $space = $start > 0 ? mb_strpos($text, ' ', $start) : false;
        $start = $space !== false && $space < $pos ? $space + 1 : $start;
        $end = min($total, $start + max(self::WINDOW, $pos - $start + $length));

        return $this->marked(
            ($start > 0 ? '…' : '')
            .mb_substr($text, $start, $pos - $start)
            .self::START.mb_substr($text, $pos, $length).self::STOP
            .mb_substr($text, $pos + $length, $end - $pos - $length)
            .($end < $total ? '…' : '')
        );
    }

    /**
     * @param  array<int, MkHelpArticle>  $rows
     * @param  array<string, list<MkHelpArticle>>  $children
     * @return list<array{slug: string, title: string, children: list<array<string, mixed>>}>
     */
    private function nodes(array $rows, array $children): array
    {
        return array_values(array_map(fn (MkHelpArticle $r) => [
            'slug' => $r->slug,
            'title' => $r->title,
            'children' => $this->nodes($children[$r->slug] ?? [], $children),
        ], $rows));
    }
}
