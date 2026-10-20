<?php

declare(strict_types=1);

namespace Mk\Director\Help\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Mk\Director\Help\Http\Requests\StoreMkHelpShareLinkRequest;
use Mk\Director\Help\MkHelpCatalog;
use Mk\Director\Help\MkHelpShareLinks;
use Mk\Director\Help\Models\MkHelpArticle;
use Mk\Director\Help\Models\MkHelpShareLink;

/**
 * Los enlaces públicos y temporales a los manuales.
 *
 * - `index`/`store`/`destroy` (`{prefix}/help/share-links`): sólo en los scopes
 *   con `share: true`, y 403 a quien no tiene `canMk(share.ability)` (con
 *   `ability` null, nadie). Cada uno ve y revoca SÓLO sus enlaces.
 * - `shared`/`sharedArticle` (`{public}/shared/{token}`): sin sesión. Un enlace
 *   vencido, revocado o inventado es 410, sin decir cuál.
 *
 * Lo compartido se muestra ENTERO, como en el panel: los permisos no esconden
 * nada. Responde con el sobre `{success, message, data}` del paquete.
 */
final class MkHelpShareLinkController
{
    public function __construct(
        private readonly MkHelpShareLinks $links,
        private readonly MkHelpCatalog $catalog,
    ) {}

    /** `GET {prefix}/help/share-links` → los enlaces del usuario, el más nuevo primero. */
    public function index(Request $request): JsonResponse
    {
        $user = $this->sharer($request);

        return $this->ok($this->links->forCreator($user)->map(fn (MkHelpShareLink $l) => $this->link($l))->all(), 'Enlaces compartidos');
    }

    /** `POST {prefix}/help/share-links` `{target, hours}` → 201, el enlace con su `url`: la ÚNICA vez que sale el token. */
    public function store(StoreMkHelpShareLinkRequest $request): JsonResponse
    {
        $user = $this->sharer($request);
        $data = $request->validated();
        $created = $this->links->create($user, (string) $data['target'], (int) $data['hours']);

        return $this->ok($this->link($created['link']) + ['url' => $created['url']], 'Enlace creado', 201);
    }

    /** `DELETE {prefix}/help/share-links/{id}` → lo revoca; 404 si no es del usuario. */
    public function destroy(Request $request, string $id): JsonResponse
    {
        if (! $this->links->revoke($this->sharer($request), $id)) {
            return $this->fail('No existe ese enlace', 404);
        }

        return $this->ok(null, 'Enlace revocado');
    }

    /**
     * `GET {public}/shared/{token}` → `{target, tree, article}`.
     * - `slug:<slug>`: `tree` null y `article` ese artículo (su audiencia es la
     *   primera parte del slug, como la carpeta de su archivo).
     * - `manual:<audience>`: `tree` el de esa audiencia y `article` su portada
     *   (el artículo con slug = la audiencia), o null si no tiene.
     */
    public function shared(string $token): JsonResponse
    {
        $link = $this->links->resolve($token);

        if ($link === null) {
            return $this->gone();
        }

        [$kind, $name] = explode(':', $link->target, 2);

        if ($kind === 'slug') {
            $article = $this->catalog->article($name, [strtok($name, '/')]);

            return $article === null
                ? $this->fail('Ese artículo ya no existe', 404)
                : $this->ok(['target' => $link->target, 'tree' => null, 'article' => $this->sharedPayload($link, $article)], $article->title);
        }

        $cover = $this->catalog->article($name, [$name]);

        return $this->ok([
            'target' => $link->target,
            'tree' => $this->catalog->tree([$name]),
            'article' => $cover === null ? null : $this->sharedPayload($link, $cover),
        ], 'Manual compartido');
    }

    /**
     * `GET {public}/shared/{token}/articles/{slug}` → el artículo, con la forma
     * de `show`, sólo si está DENTRO de lo compartido: el mismo slug, o uno de
     * la audiencia del manual. Si no, 404.
     */
    public function sharedArticle(string $token, string $slug): JsonResponse
    {
        $link = $this->links->resolve($token);

        if ($link === null) {
            return $this->gone();
        }

        [$kind, $name] = explode(':', $link->target, 2);
        $article = match ($kind) {
            'slug' => $slug === $name ? $this->catalog->article($slug, [strtok($slug, '/')]) : null,
            default => $this->catalog->article($slug, [$name]),
        };

        return $article === null
            ? $this->fail('Ese artículo no está en lo que te compartieron', 404)
            : $this->ok($this->sharedPayload($link, $article), $article->title);
    }

    /**
     * `related` recortado a lo compartido: los del manual en un `manual:`,
     * ninguno en un `slug:` (fuera de ese artículo no hay nada abierto).
     *
     * @return array<string, mixed>
     */
    private function sharedPayload(MkHelpShareLink $link, MkHelpArticle $article): array
    {
        $payload = $this->catalog->payload($article, [$article->audience]);

        return str_starts_with($link->target, 'slug:') ? ['related' => []] + $payload : $payload;
    }

    /** El usuario de la sesión, si puede compartir. 401 sin sesión, 403 sin el permiso. */
    private function sharer(Request $request): Model
    {
        $user = $request->user();

        abort_unless($user instanceof Model, 401);

        $ability = config('mk_director.help.share.ability');

        abort_unless(is_string($ability) && $ability !== '' && method_exists($user, 'canMk') && $user->canMk($ability), 403);

        return $user;
    }

    /** @return array<string, mixed> */
    private function link(MkHelpShareLink $link): array
    {
        return [
            'id' => $link->id,
            'target' => $link->target,
            'status' => $link->status(),
            'expiresAt' => $link->expires_at->toIso8601ZuluString(),
            'revokedAt' => $link->revoked_at?->toIso8601ZuluString(),
            'lastUsedAt' => $link->last_used_at?->toIso8601ZuluString(),
            'uses' => $link->uses,
            'createdAt' => $link->created_at?->toIso8601ZuluString(),
        ];
    }

    private function gone(): JsonResponse
    {
        return $this->fail('Este enlace venció o fue revocado', 410);
    }

    private function fail(string $message, int $status): JsonResponse
    {
        return new JsonResponse(['success' => false, 'message' => $message, 'data' => null], $status);
    }

    private function ok(mixed $data, string $message, int $status = 200): JsonResponse
    {
        return new JsonResponse(['success' => true, 'message' => $message, 'data' => $data], $status);
    }
}
