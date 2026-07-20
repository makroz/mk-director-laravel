<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Mk\Director\Traits\HasMkComments;

/**
 * Migración del paquete mk-director-laravel — tabla polimórfica `mk_comments`.
 *
 * Spec: Comunicaciones Fase 1, PR 3.
 *
 * 🔴 `softDeletes()` DE VERDAD
 * ----------------------------
 * El módulo legacy que reemplazamos tenía una columna `deleted_at` en su tabla
 * de comentarios y NO usaba el trait `SoftDeletes` (problema 23). El resultado
 * es el peor de los dos mundos: la columna daba la impresión de que el borrado
 * era reversible, y el `delete()` borraba la fila FÍSICAMENTE. Nadie lo notaba
 * hasta que alguien pedía restaurar un comentario y no había nada que
 * restaurar. Acá la columna existe Y el modelo usa el trait.
 *
 * ANIDAMIENTO DE UN SOLO NIVEL
 * ----------------------------
 * `parent_id` permite responder un comentario, pero NO responder una respuesta
 * — la restricción se aplica en {@see HasMkComments}. La
 * base no puede expresar "profundidad máxima 1" sin un trigger.
 *
 * Es deliberado, no una limitación: con anidamiento libre, ni la query ni la
 * UI tienen cota. Renderizar un hilo arbitrariamente profundo pide recursión y
 * la respuesta de la API deja de tener tamaño acotado. Facebook, que es el
 * modelo que estamos copiando, también es de un solo nivel.
 *
 * ÉSTA SÍ LLEVA FK REAL
 * ---------------------
 * A diferencia de las columnas polimórficas (`commentable_id` / `author_id`,
 * que son string y no pueden tener constraint), `parent_id` apunta a ESTA
 * MISMA tabla y su tipo es conocido: se declara con `constrained()` y
 * `cascadeOnDelete` de verdad. El legacy escribía `foreignUuid` SIN
 * `constrained()`, que crea la columna y ninguna garantía (problema 6).
 */
return new class extends Migration
{
    public function up(): void
    {
        $table = config('mk_director.comments.table', 'mk_comments');

        if (Schema::hasTable($table)) {
            return;
        }

        Schema::create($table, function (Blueprint $blueprint) use ($table): void {
            $blueprint->id();

            // Contenido comentado (un post, y lo que venga después).
            $blueprint->string('commentable_type');
            $blueprint->string('commentable_id');

            // Quién comentó. Polimórfico: Admin o Member en RETO.
            $blueprint->string('author_type');
            $blueprint->string('author_id');

            // Respuesta a otro comentario. Null = comentario raíz.
            // El cascade cubre el borrado FÍSICO; el soft delete lo propaga el
            // trait a mano, porque un soft delete no dispara FKs.
            $blueprint->foreignId('parent_id')
                ->nullable()
                ->constrained($table)
                ->cascadeOnDelete();

            $blueprint->text('body');

            $blueprint->timestamps();
            $blueprint->softDeletes();

            // El índice del hilo: los comentarios raíz de un contenido, en
            // orden cronológico. Nombre explícito porque el auto-generado se
            // pasa de 64 chars.
            $blueprint->index(
                ['commentable_type', 'commentable_id', 'parent_id', 'created_at'],
                $table.'_thread_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('mk_director.comments.table', 'mk_comments'));
    }
};
