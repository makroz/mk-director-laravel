<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migración del paquete mk-director-laravel — tabla polimórfica `mk_media`.
 *
 * Spec: Comunicaciones Fase 1, PR 1.
 *
 * POR QUÉ EXISTE
 * --------------
 * `FileStoragePlugin` resuelve UN archivo por columna: su línea clave es
 * `$data[$columnName] = $storedPath;` — una asignación escalar. El propio
 * docblock del plugin declara el caso multi-archivo como YAGNI diferido
 * (FEEDBACK8 F8-B02). Esta tabla ES ese backlog.
 *
 * Consecuencias del modelo 1-columna-1-path que esta tabla arregla:
 *  - no había forma de tener una galería (N archivos por dueño),
 *  - no había metadata (mime, size, ancho/alto, duración),
 *  - el `disk` era global del plugin, no por archivo,
 *  - no había orden explícito.
 *
 * DECISIÓN: `mediable_id` es string(), no `morphs()` ni `uuidMorphs()`
 * ---------------------------------------------------------------------
 * `morphs()` clava la FK a bigint y `uuidMorphs()` a uuid. El paquete tiene
 * consumers de los dos tipos (RETO usa modelos uuid; Condaty usa bigint en
 * varias tablas), y una tabla del paquete no puede elegir por ellos.
 * `string` soporta ambos, al costo de un índice más gordo y de perder la FK
 * declarativa — que de todos modos NO existe en una relación polimórfica.
 *
 * Es la primera vez que el paquete enfrenta esta decisión: `role_user` la
 * esquiva porque ahí `user_id` es uuid fijo por contrato de Auth.
 *
 * El nombre de tabla es configurable (`config('mk_director.media.table')`),
 * mismo patrón que `mk_progressive_codes`, para que un consumer con una
 * tabla `mk_media` preexistente pueda renombrarla sin forkear el paquete.
 *
 * Guard `Schema::hasTable` para que una doble-carga (tests que corren la
 * migración más de una vez) sea no-op en vez de error de tabla duplicada.
 */
return new class extends Migration
{
    public function up(): void
    {
        $table = config('mk_director.media.table', 'mk_media');

        if (Schema::hasTable($table)) {
            return;
        }

        Schema::create($table, function (Blueprint $blueprint) use ($table): void {
            $blueprint->id();

            // Dueño polimórfico. Ver la nota de arriba sobre por qué string.
            $blueprint->string('mediable_type');
            $blueprint->string('mediable_id');

            // Permite varias galerías sobre el MISMO dueño sin agregar tablas
            // (e.g. 'gallery' y 'cover' de un post). Sin esto, el día que
            // alguien necesite dos colecciones el único camino es otra tabla.
            $blueprint->string('collection')->default('default');

            // Ver Mk\Director\Enums\MkMediaKind. Int-backed desde 1 (regla de
            // la agencia; ver el docblock de ScopeStatus para la historia).
            $blueprint->unsignedTinyInteger('kind');

            // Archivo propio (Image/Video). Null para Embed, que no guarda nada
            // en un disk nuestro.
            $blueprint->string('disk')->nullable();
            $blueprint->string('path')->nullable();

            // Embeds (YouTube / TikTok / Instagram). `source_url` es lo que
            // pegó el usuario; `provider` + `provider_id` es lo que se extrajo.
            // Separados a propósito: el legacy sobrecargaba UNA columna `url`
            // con tres significados distintos según el `type`.
            $blueprint->string('provider')->nullable();
            $blueprint->string('provider_id')->nullable();
            $blueprint->text('source_url')->nullable();
            $blueprint->text('thumbnail_url')->nullable();

            // Metadata que el modelo 1-columna-1-path no podía guardar.
            $blueprint->string('mime_type')->nullable();
            $blueprint->unsignedBigInteger('size')->nullable();      // bytes
            $blueprint->unsignedInteger('width')->nullable();        // px
            $blueprint->unsignedInteger('height')->nullable();       // px
            $blueprint->unsignedInteger('duration')->nullable();     // segundos (video)

            $blueprint->unsignedInteger('position')->default(0);
            $blueprint->json('meta')->nullable();

            $blueprint->timestamps();

            // El índice del fetch de galería: dueño + colección, en orden.
            // Nombre explícito porque el auto-generado se pasa de 64 chars.
            $blueprint->index(
                ['mediable_type', 'mediable_id', 'collection', 'position'],
                $table.'_mediable_collection_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('mk_director.media.table', 'mk_media'));
    }
};
