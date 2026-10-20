<?php

declare(strict_types=1);

namespace Mk\Director\Help\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Mk\Director\Auth\Models\Ability;
use Mk\Director\Help\MkHelpCatalog;
use Mk\Director\Help\Models\MkHelpArticle;

/**
 * Los manuales del usuario autenticado. Rutas opt-in:
 * `mk_director.help.routes` (un grupo por scope). Cada scope ve SÓLO sus
 * `audiences`; los permisos no filtran nada.
 *
 * Responde con el sobre `{success, message, data}` del paquete.
 */
final class MkHelpController
{
    public function __construct(private readonly MkHelpCatalog $catalog) {}

    /** `GET {prefix}/help/tree` → `[{slug, title, children}]`. */
    public function tree(Request $request): JsonResponse
    {
        return $this->ok($this->catalog->tree($this->audiences($request)), 'Manual');
    }

    /**
     * `GET {prefix}/help/articles/{slug}` (el slug lleva `/`) → el artículo, o 404.
     * `related` sale como `[{slug, title}]` en el orden del árbol, sólo los
     * que el scope ve; `images` son las únicas que el front pinta.
     * `abilityLabel` es la `description` de esa ability en la tabla de
     * abilities, para que la nota diga qué permiso es con palabras; null si
     * la ability no existe o no tiene descripción (el front muestra la clave).
     */
    public function show(Request $request, string $slug): JsonResponse
    {
        $audiences = $this->audiences($request);
        $article = $this->catalog->article($slug, $audiences);

        if ($article === null) {
            return new JsonResponse(['success' => false, 'message' => 'No existe ese artículo del manual', 'data' => null], 404);
        }

        return $this->ok($this->payload($article, $audiences), $article->title);
    }

    /**
     * `GET {prefix}/help/screens/{screen}` → el artículo de esa pantalla (una
     * clave de `screens.yaml`, p. ej. `admin.kyc.index`), con la misma forma
     * que `show`, o 404 si ningún artículo del scope la lista. Es lo que pide el
     * "?" de cada pantalla: un 404 es «esta pantalla no tiene ayuda».
     */
    public function screen(Request $request, string $screen): JsonResponse
    {
        $audiences = $this->audiences($request);
        $article = $this->catalog->forScreen($screen, $audiences);

        if ($article === null) {
            return new JsonResponse(['success' => false, 'message' => 'Esta pantalla no tiene un artículo del manual', 'data' => null], 404);
        }

        return $this->ok($this->payload($article, $audiences), $article->title);
    }

    /**
     * @param  list<string>  $audiences
     * @return array<string, mixed>
     */
    private function payload(MkHelpArticle $article, array $audiences): array
    {
        return [
            'slug' => $article->slug,
            'title' => $article->title,
            'audience' => $article->audience,
            'ability' => $article->ability,
            'abilityLabel' => $article->ability === null
                ? null
                : (Ability::query()->where('name', $article->ability)->value('description') ?: null),
            'body' => $article->body,
            'images' => $article->images ?? [],
            'related' => $this->catalog->related($article, $audiences),
            'updatedAt' => $article->updated_at?->toIso8601ZuluString(),
        ];
    }

    /**
     * `GET {prefix}/help/search?q=` → `[{slug, title, snippet}]`, por relevancia.
     * `q` va recortado y con 2 a 100 caracteres; si no, 422. `snippet` es HTML
     * con todo escapado salvo el `<mark>` del acierto.
     */
    public function search(Request $request): JsonResponse
    {
        $audiences = $this->audiences($request);
        $query = $request->query('q');
        $query = is_string($query) ? trim($query) : '';
        $length = mb_strlen($query);

        if ($length < 2 || $length > 100) {
            $message = 'La búsqueda tiene que tener entre 2 y 100 caracteres';

            return new JsonResponse([
                'success' => false,
                'message' => $message,
                'data' => null,
                '__extraData' => ['code' => 'ERR_VALIDATION', 'errors' => ['q' => [$message]]],
            ], 422);
        }

        return $this->ok($this->catalog->search($query, $audiences), 'Búsqueda del manual');
    }

    /**
     * Las audiencias del scope de la ruta. Falla cerrado: sin usuario es 401
     * (un scope configurado sin `mk.auth`), y un scope sin `audiences` no ve nada.
     *
     * @return list<string>
     */
    private function audiences(Request $request): array
    {
        abort_unless($request->user() instanceof Model, 401);

        $scope = (string) $request->route()?->getAction('mk_help_scope');

        return array_values((array) config("mk_director.help.routes.scopes.{$scope}.audiences", []));
    }

    private function ok(mixed $data, string $message): JsonResponse
    {
        return new JsonResponse(['success' => true, 'message' => $message, 'data' => $data]);
    }
}
