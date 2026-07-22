<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migración del paquete mk-director-laravel — tabla `mk_comment_reports`.
 *
 * Spec: Comunicaciones Fase 2, PR 1 (moderación de comentarios).
 *
 * 🔴 EL UNIQUE ES EL PUNTO, IGUAL QUE EN `mk_reactions`
 * ----------------------------------------------------
 * Un reporte es una señal: una persona marca un comentario. Sin UNIQUE, tocar
 * "reportar" dos veces (doble tap, red lenta y reintento) mete dos filas y la
 * cola de admin muestra el mismo comentario "reportado por 2" cuando fue uno
 * solo. Un `if (! existe) insert` en PHP no cierra la ventana entre el SELECT y
 * el INSERT; la única defensa real es que la base rechace el duplicado. El
 * código de arriba ({@see \Mk\Director\Models\MkComment::reportBy()}) sabe que
 * puede perder la carrera y la maneja como "ya reportado" — no la previene.
 *
 * FK REAL A `mk_comments`, NO POLIMÓRFICA
 * ---------------------------------------
 * A diferencia del reporter (que es Admin o Member — polimórfico, string), la
 * columna `comment_id` apunta a UNA tabla conocida: se declara con
 * `constrained()` y `cascadeOnDelete` de verdad. Al borrar FÍSICAMENTE un
 * comentario, sus reportes se van con él — no tiene sentido auditar denuncias
 * de una fila que ya no existe.
 *
 * `reporter_id` / `resolved_by_id` son `string()` por lo mismo que
 * `commentable_id` en `mk_comments`: el paquete tiene consumers con PK uuid
 * (RETO) y bigint (Condaty), y clavar uno rompe el otro.
 */
return new class extends Migration
{
    public function up(): void
    {
        $table = config('mk_director.comment_reports.table', 'mk_comment_reports');
        $commentsTable = config('mk_director.comments.table', 'mk_comments');

        if (Schema::hasTable($table)) {
            return;
        }

        Schema::create($table, function (Blueprint $blueprint) use ($table, $commentsTable): void {
            $blueprint->id();

            // El comentario denunciado. FK real: al borrarlo, se van los reportes.
            $blueprint->foreignId('comment_id')
                ->constrained($commentsTable)
                ->cascadeOnDelete();

            // Quién reportó. Polimórfico: Admin o Member en RETO.
            $blueprint->string('reporter_type');
            $blueprint->string('reporter_id');

            // Ver Mk\Director\Enums\MkCommentReportReason. Int-backed desde 1.
            $blueprint->unsignedTinyInteger('reason');
            // Detalle libre opcional — obligatorio de facto sólo cuando la razón
            // es "Otro", pero eso lo hace cumplir el request, no la base.
            $blueprint->text('note')->nullable();

            // Ciclo de vida: Pending → Dismissed | Actioned.
            // Ver Mk\Director\Enums\MkCommentReportStatus.
            $blueprint->unsignedTinyInteger('status')->default(1);

            // Quién y cuándo lo resolvió. Null mientras está pendiente.
            $blueprint->string('resolved_by_type')->nullable();
            $blueprint->string('resolved_by_id')->nullable();
            $blueprint->timestamp('resolved_at')->nullable();

            $blueprint->timestamps();

            // 🔴 LA GARANTÍA. Un reporte por persona por comentario. Sin esto
            // todo lo de arriba es decoración. Nombre explícito porque el
            // auto-generado se pasa de 64 chars.
            $blueprint->unique(
                ['comment_id', 'reporter_type', 'reporter_id'],
                $table.'_one_per_reporter_uq'
            );

            // La cola de admin lee los pendientes primero. Este índice la
            // resuelve sin escanear toda la tabla.
            $blueprint->index(['status', 'comment_id'], $table.'_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('mk_director.comment_reports.table', 'mk_comment_reports'));
    }
};
