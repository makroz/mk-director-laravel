<?php

declare(strict_types=1);

namespace Mk\Director\Traits;

use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Mk\Director\Enums\MkReactionType;
use Mk\Director\Models\MkReaction;

/**
 * HasMkReactions — reacciones (like & co.) sobre cualquier modelo del consumer.
 *
 * Spec: Comunicaciones Fase 1, PR 2.
 *
 * 🔴 POSTGRES — EL PK DEL MODELO DUEÑO DEBE SER `string`, NO `uuid` NATIVO
 * -----------------------------------------------------------------------
 * `mk_reactions.reactable_id` es `string` a propósito: soporta consumers con
 * PKs uuid Y bigint. En Postgres, de tipado estricto, `withCount`/`whereHas`/
 * `has` comparan COLUMNA CON COLUMNA (`tu_tabla.id = mk_reactions.reactable_id`).
 * Si el PK del modelo dueño es `uuid` NATIVO, eso es `uuid = varchar` y pgsql se
 * niega ("operator does not exist"). Tipá el PK como `$table->string('id', 36)`
 * (`HasUuids` genera el uuid igual). MySQL/SQLite no distinguen tipos y esconden
 * el problema — lo caza `mk:security-lint`.
 *
 * USO
 * ---
 *   class Post extends Model
 *   {
 *       use HasMkReactions;
 *
 *       // OPCIONAL: contador materializado. Ver mkReactionsCountColumn().
 *       protected ?string $mkReactionsCountColumn = 'reactions_count';
 *   }
 *
 *   $post->react($user);                          // toggle de Like
 *   $post->react($user, MkReactionType::Love);    // toggle/switch a Love
 *   $post->hasReactionFrom($user);                // bool
 *   $post->reactionCounts();                      // [1 => 12, 2 => 3]
 *
 * EL TOGGLE ES IDEMPOTENTE Y TRANSACCIONAL — no es un detalle
 * -----------------------------------------------------------
 * El legacy hacía `if (! existe) insert; else delete;` suelto, sin UNIQUE y
 * sin transacción. Entre el SELECT y el INSERT hay una ventana real: dos taps
 * concurrentes del mismo usuario (doble tap, o red lenta y reintento) metían
 * DOS filas y sumaban dos veces. El contador quedaba mal para siempre y nada
 * en la aplicación podía detectarlo.
 *
 * Acá la corrección viene de tres piezas que sólo sirven JUNTAS:
 *  1. UNIQUE(reactable, author) en la base — la única defensa real contra la
 *     carrera. Ver la migración `create_mk_reactions_table`.
 *  2. La transacción con `lockForUpdate`, que serializa a los que sí ven la
 *     fila existente.
 *  3. El reintento de `react()`, para el perdedor de la carrera que NO la vio.
 * Sacá cualquiera de las tres y volvés al bug del legacy.
 */
trait HasMkReactions
{
    /**
     * Todas las reacciones del modelo, más nuevas primero.
     *
     * `id` como desempate: dos reacciones creadas en el mismo segundo salen en
     * un orden que depende del motor, y el test que las compare se vuelve
     * flaky. Mismo criterio que {@see HasMkMedia::media()}.
     */
    public function reactions(): MorphMany
    {
        return $this->morphMany(MkReaction::class, 'reactable')
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    /**
     * Sólo las reacciones de un tipo.
     */
    public function reactionsOfType(MkReactionType $type): MorphMany
    {
        return $this->reactions()->where('type', $type->value);
    }

    /**
     * Deja, cambia o saca la reacción de `$author` sobre este modelo.
     *
     * Es un TOGGLE con semántica de Facebook:
     *  - sin reacción previa        → la crea            (`added`)
     *  - misma reacción que la que ya tenía → la borra   (`removed`)
     *  - reacción distinta          → la reemplaza       (`switched`)
     *
     * Es IDEMPOTENTE en el sentido que importa: llamarlo dos veces con lo
     * mismo deja el sistema en un estado consistente (puesto → sacado), nunca
     * en uno corrupto (dos filas, contador en 2).
     *
     * @return array{action: 'added'|'removed'|'switched', reaction: MkReaction|null}
     *                                                                                `reaction` es null cuando la acción fue `removed`.
     */
    public function react(EloquentModel $author, ?MkReactionType $type = null): array
    {
        $type ??= MkReactionType::default();

        try {
            return $this->runMkReactionToggle($author, $type);
        } catch (UniqueConstraintViolationException) {
            // Perdimos la carrera: otro request insertó la reacción de este
            // mismo autor entre nuestro SELECT y nuestro INSERT, y la base
            // rechazó el duplicado — que es EXACTAMENTE lo que queremos que
            // pase.
            //
            // Se reintenta afuera de la transacción, no adentro: en Postgres
            // un statement fallido aborta la transacción entera y cualquier
            // query posterior muere con "current transaction is aborted". Un
            // catch adentro parecería funcionar en sqlite/MySQL y rompería en
            // producción.
            //
            // No hay riesgo de loop: en el segundo intento la fila YA existe,
            // así que el toggle toma la rama de update/delete y no vuelve a
            // insertar.
            return $this->runMkReactionToggle($author, $type);
        }
    }

    /**
     * ¿`$author` ya reaccionó a este modelo (con cualquier tipo)?
     */
    public function hasReactionFrom(EloquentModel $author): bool
    {
        return $this->mkReactionQueryFor($author)->exists();
    }

    /**
     * La reacción de `$author`, o null si no reaccionó.
     */
    public function reactionFrom(EloquentModel $author): ?MkReaction
    {
        /** @var MkReaction|null $reaction */
        $reaction = $this->mkReactionQueryFor($author)->first();

        return $reaction;
    }

    /**
     * Conteo agrupado por tipo: `[MkReactionType::Like->value => 12, ...]`.
     *
     * Sólo aparecen los tipos con al menos una reacción — un mapa con los seis
     * tipos en cero obligaría a la UI a filtrar, y el caso normal de un post
     * es tener un solo tipo.
     *
     * @return array<int, int>
     */
    public function reactionCounts(): array
    {
        return $this->reactions()
            ->toBase()
            // 🔴 `reorder()` NO es cosmético. `reactions()` viene con
            // `ORDER BY created_at DESC, id DESC`, y Postgres RECHAZA ordenar
            // por una columna que no está en el GROUP BY ni agregada. sqlite y
            // MySQL lo toleran, así que sin esto el test pasa en verde local y
            // el endpoint tira 500 en producción.
            ->reorder()
            ->select('type', DB::raw('count(*) as aggregate'))
            ->groupBy('type')
            ->pluck('aggregate', 'type')
            ->map(static fn ($count): int => (int) $count)
            ->all();
    }

    /**
     * Columna donde materializar el total de reacciones, o null para no
     * materializar nada.
     *
     * OPT-IN A PROPÓSITO. El paquete no puede asumir que el modelo del
     * consumer tenga la columna, y sin contador el sistema es correcto igual:
     * `withCount('reactions')` resuelve el feed en una sola query y el número
     * sale de contar filas, que con el UNIQUE puesto es autoritativo por
     * construcción. El contador es una optimización, no la fuente de verdad —
     * confundir las dos cosas es lo que produjo el bug del legacy.
     */
    protected function mkReactionsCountColumn(): ?string
    {
        return property_exists($this, 'mkReactionsCountColumn')
            ? $this->mkReactionsCountColumn
            : null;
    }

    /**
     * El cuerpo del toggle. Separado de `react()` para poder reintentarlo
     * entero — ver el catch de allá.
     *
     * @return array{action: 'added'|'removed'|'switched', reaction: MkReaction|null}
     */
    protected function runMkReactionToggle(EloquentModel $author, MkReactionType $type): array
    {
        return DB::transaction(function () use ($author, $type): array {
            /** @var MkReaction|null $existing */
            $existing = $this->mkReactionQueryFor($author)->lockForUpdate()->first();

            if ($existing === null) {
                /** @var MkReaction $reaction */
                $reaction = $this->reactions()->create([
                    'author_type' => $author->getMorphClass(),
                    'author_id' => (string) $author->getKey(),
                    'type' => $type,
                ]);

                $this->syncMkReactionsCount();

                return ['action' => 'added', 'reaction' => $reaction];
            }

            if ($existing->type === $type) {
                $existing->delete();
                $this->syncMkReactionsCount();

                return ['action' => 'removed', 'reaction' => null];
            }

            $existing->type = $type;
            $existing->save();

            // El total NO cambia al cambiar de tipo, así que no se recalcula:
            // una query menos en el caso más común después del alta.
            return ['action' => 'switched', 'reaction' => $existing];
        });
    }

    /**
     * Query de la reacción de un autor puntual sobre este modelo.
     *
     * `(string)` sobre la key porque las columnas polimórficas son string para
     * soportar consumers con PK uuid y bigint a la vez (ver la migración). Sin
     * el cast, un `where('author_id', 7)` contra una columna string funciona en
     * MySQL por coerción implícita y NO matchea en Postgres.
     */
    protected function mkReactionQueryFor(EloquentModel $author): MorphMany
    {
        return $this->reactions()
            ->where('author_type', $author->getMorphClass())
            ->where('author_id', (string) $author->getKey());
    }

    /**
     * Recalcula el contador materializado, si el modelo declaró uno.
     *
     * RECALCULA, no incrementa. `increment()` es más barato pero es
     * precisamente la operación que se desincroniza: si una escritura se
     * pierde, el número queda mal para siempre y nada lo detecta. Contar las
     * filas dentro de la MISMA transacción que las modificó da un número
     * autoritativo, y el costo es una query sobre un índice.
     *
     * El update va por el query builder base a propósito: el `update()` de
     * Eloquent escribe `updated_at`, y mover el `updated_at` de un post porque
     * alguien le dio like reordenaría cualquier feed ordenado por esa columna.
     */
    protected function syncMkReactionsCount(): void
    {
        $column = $this->mkReactionsCountColumn();

        if ($column === null) {
            return;
        }

        $count = $this->reactions()->toBase()->count();

        $this->newQuery()->toBase()->where($this->getKeyName(), $this->getKey())->update([
            $column => $count,
        ]);

        $this->setAttribute($column, $count);
    }

    /**
     * Al borrar el dueño, borra sus reacciones.
     *
     * Una relación polimórfica NO puede tener FK, así que no hay
     * `cascadeOnDelete` que las limpie: sin esto quedan huérfanas para siempre.
     * Mismo criterio que {@see HasMkMedia::bootHasMkMedia()}, incluido el
     * respeto por SoftDeletes: restaurar un post tiene que devolverte sus
     * likes.
     */
    protected static function bootHasMkReactions(): void
    {
        static::deleting(function ($model): void {
            $usesSoftDeletes = method_exists($model, 'isForceDeleting');

            if ($usesSoftDeletes && ! $model->isForceDeleting()) {
                return;
            }

            $model->reactions()->delete();
        });
    }
}
