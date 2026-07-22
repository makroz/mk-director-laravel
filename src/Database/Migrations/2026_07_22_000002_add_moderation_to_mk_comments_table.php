<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migración del paquete mk-director-laravel — moderación sobre `mk_comments`.
 *
 * Spec: Comunicaciones Fase 2, PR 1 (moderación de comentarios).
 *
 * 🔴 `hidden_at` NO ES `deleted_at` — SON DOS ESTADOS DISTINTOS
 * ------------------------------------------------------------
 * Un comentario OCULTO por moderación sigue EXISTIENDO: su autor lo ve como una
 * lápida con el motivo, y el admin lo puede volver a mostrar. Un comentario
 * BORRADO (`deleted_at`, SoftDeletes) se fue, y arrastra sus respuestas. Meter
 * las dos cosas en la misma columna haría imposible distinguir "escondido y
 * reversible, visible para el autor" de "borrado y en cascada". Por eso una
 * columna aparte, y por eso el filtro de visibilidad del muro es
 * `whereNull('hidden_at')`, no toca los SoftDeletes.
 *
 * MIGRACIÓN ADITIVA E IDEMPOTENTE
 * -------------------------------
 * Se agrega sobre la tabla que ya existe (`create_mk_comments_table` corrió
 * antes). Los guards `hasTable`/`hasColumn` la hacen segura de re-correr y no
 * asumen que el consumer parta de cero — puede tener el muro en producción con
 * comentarios ya cargados.
 */
return new class extends Migration
{
    public function up(): void
    {
        $table = config('mk_director.comments.table', 'mk_comments');

        if (! Schema::hasTable($table) || Schema::hasColumn($table, 'hidden_at')) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint): void {
            // Cuándo se ocultó por moderación. Null = visible.
            $blueprint->timestamp('hidden_at')->nullable()->after('body');

            // El motivo que ve el autor en la lápida. Ver
            // Mk\Director\Enums\MkCommentReportReason. Null mientras esté visible.
            $blueprint->unsignedTinyInteger('moderation_reason')->nullable()->after('hidden_at');
            // Detalle libre opcional que el admin le deja al autor.
            $blueprint->text('moderation_note')->nullable()->after('moderation_reason');

            // Qué admin lo ocultó. Polimórfico igual que el autor.
            $blueprint->string('moderated_by_type')->nullable()->after('moderation_note');
            $blueprint->string('moderated_by_id')->nullable()->after('moderated_by_type');
        });
    }

    public function down(): void
    {
        $table = config('mk_director.comments.table', 'mk_comments');

        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'hidden_at')) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint): void {
            $blueprint->dropColumn([
                'hidden_at',
                'moderation_reason',
                'moderation_note',
                'moderated_by_type',
                'moderated_by_id',
            ]);
        });
    }
};
