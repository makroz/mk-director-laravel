<?php

declare(strict_types=1);

namespace Mk\Director\Help;

use Mk\Director\Help\Models\MkHelpArticle;

/**
 * El lado de lectura de los manuales. Filtra SÓLO por audiencia: los permisos
 * nunca esconden un artículo (su `ability` es una nota informativa).
 */
final class MkHelpCatalog
{
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
