<?php

declare(strict_types=1);

namespace Mk\Director\Traits;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use InvalidArgumentException;
use Mk\Director\Models\MkComment;

/**
 * HasMkComments — comentarios sobre cualquier modelo del consumer.
 *
 * Spec: Comunicaciones Fase 1, PR 3.
 *
 * 🔴 POSTGRES — EL PK DEL MODELO DUEÑO DEBE SER `string`, NO `uuid` NATIVO
 * -----------------------------------------------------------------------
 * `mk_comments.commentable_id` es `string` a propósito: soporta consumers con
 * PKs uuid Y bigint. En Postgres, de tipado estricto, `withCount`/`whereHas`/
 * `has` comparan COLUMNA CON COLUMNA (`tu_tabla.id = mk_comments.commentable_id`).
 * Si el PK del modelo dueño es `uuid` NATIVO, eso es `uuid = varchar` y pgsql se
 * niega ("operator does not exist"). Tipá el PK como `$table->string('id', 36)`
 * (`HasUuids` genera el uuid igual). MySQL/SQLite no distinguen tipos y esconden
 * el problema — lo caza `mk:security-lint`.
 *
 * USO
 * ---
 *   class Post extends Model
 *   {
 *       use HasMkComments;
 *   }
 *
 *   $post->addComment($user, 'Buenísimo');           // comentario raíz
 *   $post->addComment($user, 'Gracias', $comentario); // respuesta
 *   $post->paginatedComments();                       // hilo, paginado
 *   $post->commentsCount();
 *
 * DOS REGLAS QUE EL TRAIT HACE CUMPLIR, Y LA BASE NO PUEDE
 * --------------------------------------------------------
 *  1. Anidamiento de UN nivel: se puede responder un comentario, no una
 *     respuesta. Expresar "profundidad máxima 1" en el esquema pediría un
 *     trigger.
 *  2. El padre tiene que pertenecer a ESTE MISMO contenido. La FK `parent_id`
 *     garantiza que el padre existe, no que sea del post correcto: sin este
 *     chequeo se puede colgar una respuesta del post B abajo de un comentario
 *     del post A, y queda un comentario que no aparece en ningún hilo.
 *
 * EL HILO NO SE DEVUELVE ENTERO NUNCA
 * -----------------------------------
 * `paginatedComments()` es la puerta de entrada, no `comments()->get()`. Un
 * post con 5.000 comentarios no entra en una respuesta, y el legacy no tenía
 * paginación acá.
 */
trait HasMkComments
{
    /**
     * Todos los comentarios del modelo (raíces y respuestas), más viejos
     * primero — un hilo se lee en el orden en que se escribió, al revés que
     * el feed.
     *
     * `id` como desempate para que el orden no dependa del motor.
     */
    public function comments(): MorphMany
    {
        return $this->morphMany(MkComment::class, 'commentable')
            ->orderBy('created_at')
            ->orderBy('id');
    }

    /**
     * Sólo los comentarios raíz. Es lo que se pagina: las respuestas viajan
     * anidadas adentro de su padre, no como items sueltos de la lista.
     */
    public function rootComments(): MorphMany
    {
        return $this->comments()->whereNull('parent_id');
    }

    /**
     * El hilo paginado: comentarios raíz con sus respuestas ya cargadas.
     *
     * El eager load de `replies` es lo que evita el N+1 — sin él, una página
     * de 15 comentarios dispara 16 queries. El legacy tenía exactamente ese
     * problema.
     */
    public function paginatedComments(?int $perPage = null): LengthAwarePaginator
    {
        $perPage ??= (int) config('mk_director.comments.per_page', 15);

        return $this->rootComments()->with('replies')->paginate($perPage);
    }

    /**
     * Agrega un comentario, o una respuesta si se pasa `$parent`.
     *
     * @throws InvalidArgumentException si `$parent` ya es una respuesta, o si
     *                                  pertenece a otro contenido.
     */
    public function addComment(EloquentModel $author, string $body, ?MkComment $parent = null): MkComment
    {
        if ($parent !== null) {
            $this->assertValidCommentParent($parent);
        }

        /** @var MkComment $comment */
        $comment = $this->comments()->create([
            'author_type' => $author->getMorphClass(),
            'author_id' => (string) $author->getKey(),
            'parent_id' => $parent?->getKey(),
            'body' => $body,
        ]);

        return $comment;
    }

    /**
     * Cantidad de comentarios vivos (raíces + respuestas).
     *
     * Los soft-deleted NO cuentan: el global scope de `SoftDeletes` en
     * {@see MkComment} los filtra solo.
     */
    public function commentsCount(): int
    {
        return $this->comments()->count();
    }

    /**
     * Las dos reglas de integridad del hilo. Ver el docblock del trait.
     */
    protected function assertValidCommentParent(MkComment $parent): void
    {
        if (! $parent->isRoot()) {
            throw new InvalidArgumentException(
                'No se puede responder a una respuesta: los comentarios admiten un solo nivel '
                .'de anidamiento. Respondé al comentario raíz (id '.$parent->parent_id.').'
            );
        }

        $mismoContenido = $parent->commentable_type === $this->getMorphClass()
            && (string) $parent->commentable_id === (string) $this->getKey();

        if (! $mismoContenido) {
            throw new InvalidArgumentException(
                'El comentario padre pertenece a otro contenido: la respuesta quedaría '
                .'colgada de un hilo en el que no aparece.'
            );
        }
    }

    /**
     * Al borrar el dueño, borra sus comentarios.
     *
     * Una relación polimórfica NO puede tener FK, así que sin esto quedan
     * huérfanos para siempre. Mismo criterio que
     * {@see HasMkMedia::bootHasMkMedia()} y {@see HasMkReactions}, incluido el
     * respeto por SoftDeletes: restaurar un post tiene que devolverte su hilo.
     *
     * 🔴 EL BORRADO ACÁ ES `forceDelete()`, NO `delete()`.
     *
     * Este método sólo corre cuando el dueño se va FÍSICAMENTE. Un comentario
     * soft-deleted que apunta a un dueño que ya no existe es, por definición,
     * una fila huérfana: no se puede restaurar a nada y nadie la limpia nunca
     * — justo lo que este hook existe para evitar.
     *
     * El `withTrashed()` es parte del mismo argumento: los comentarios que YA
     * estaban soft-deleted también quedarían colgando.
     *
     * Bug encontrado por el piloto RETO (FEEDBACK12) contra Postgres. La
     * versión anterior hacía `delete()` y la suite del paquete la daba por
     * buena, porque asserteaba `MkComment::count()` — que excluye los
     * soft-deleted y por lo tanto no podía ver la fila que quedaba. El test
     * que lo destapó cuenta con `withTrashed()`.
     */
    protected static function bootHasMkComments(): void
    {
        static::deleting(function ($model): void {
            $usesSoftDeletes = method_exists($model, 'isForceDeleting');

            if ($usesSoftDeletes && ! $model->isForceDeleting()) {
                return;
            }

            $model->comments()->withTrashed()->get()->each->forceDelete();
        });
    }
}
