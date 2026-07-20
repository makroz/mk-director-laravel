<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Mk\Director\Enums\MkReactionType;

/**
 * Migración del paquete mk-director-laravel — tabla polimórfica `mk_reactions`.
 *
 * Spec: Comunicaciones Fase 1, PR 2.
 *
 * 🔴 EL UNIQUE ES EL PUNTO DE ESTA MIGRACIÓN
 * ------------------------------------------
 * El peor bug del módulo legacy que estamos reemplazando (problema 5) es que
 * su tabla de likes NO tenía UNIQUE y el toggle NO estaba en transacción. Dos
 * taps concurrentes del mismo usuario insertaban dos filas y sumaban dos veces
 * al contador: a partir de ahí el número mostrado quedaba mal PARA SIEMPRE, sin
 * forma de que la aplicación se diera cuenta.
 *
 * Un chequeo `if (! existe) insert` en PHP no arregla eso: entre el SELECT y el
 * INSERT hay una ventana en la que el otro request ya insertó. La única defensa
 * real es que la BASE rechace el duplicado, y eso es lo que hace este índice.
 * El código de arriba (`HasMkReactions::react()`) sabe que puede perder la
 * carrera y la maneja; no la previene.
 *
 * EL UNIQUE NO INCLUYE `type`, A PROPÓSITO
 * ----------------------------------------
 * Un autor tiene a lo sumo UNA reacción por contenido: cambiar de `Like` a
 * `Love` reemplaza. Con `type` adentro del UNIQUE, la misma persona podría
 * dejar las seis reacciones simultáneas y el contador diría 6 por un solo
 * usuario. Ver {@see MkReactionType}.
 *
 * `reactable_id` / `author_id` son string() por la misma razón que
 * `mediable_id` en `mk_media`: el paquete tiene consumers con PKs uuid (RETO)
 * y bigint (Condaty), y `morphs()`/`uuidMorphs()` clavan uno de los dos.
 *
 * El autor es polimórfico porque en RETO puede reaccionar un `Admin` o un
 * `Member` — dos tablas distintas, dos guards distintos. El legacy asumía un
 * único tipo de usuario y por eso no podía representar esto.
 */
return new class extends Migration
{
    public function up(): void
    {
        $table = config('mk_director.reactions.table', 'mk_reactions');

        if (Schema::hasTable($table)) {
            return;
        }

        Schema::create($table, function (Blueprint $blueprint) use ($table): void {
            $blueprint->id();

            // Contenido reaccionado (post, comentario, lo que sea).
            $blueprint->string('reactable_type');
            $blueprint->string('reactable_id');

            // Quién reaccionó. Polimórfico: Admin o Member, o lo que el
            // consumer tenga.
            $blueprint->string('author_type');
            $blueprint->string('author_id');

            // Ver Mk\Director\Enums\MkReactionType. Int-backed desde 1.
            $blueprint->unsignedTinyInteger('type');

            $blueprint->timestamps();

            // 🔴 LA GARANTÍA. Sin esto todo lo de arriba es decoración.
            // Nombre explícito porque el auto-generado se pasa de 64 chars.
            $blueprint->unique(
                ['reactable_type', 'reactable_id', 'author_type', 'author_id'],
                $table.'_one_per_author_uq'
            );

            // El índice del conteo por tipo ("12 me gusta, 3 me encanta").
            // El UNIQUE de arriba ya cubre el prefijo (reactable_type,
            // reactable_id), pero no sirve para agrupar por `type` sin leer
            // las filas: este índice sí.
            $blueprint->index(
                ['reactable_type', 'reactable_id', 'type'],
                $table.'_reactable_type_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('mk_director.reactions.table', 'mk_reactions'));
    }
};
