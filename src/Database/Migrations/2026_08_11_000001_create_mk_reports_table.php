<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migración del paquete mk-director-laravel — tabla `mk_reports`.
 *
 * Origen: `condaty-api/database/migrations/..._create_reports_table.php`,
 * el motor de export que se porta al paquete (plan
 * `2026-08-11-mk-director-motor-de-reportes.md`).
 *
 * QUÉ GUARDA
 * ----------
 * Un pedido de export y su ciclo de vida. El usuario aprieta "Exportar", esto
 * nace en `pending`, el job lo pasa a `processing` y termina en `completed` o
 * `failed`. El front hace polling contra el `uuid`.
 *
 * DECISIÓN: `user_id` y `tenant_id` son `string`, no `foreignUuid`/`foreignId`
 * ---------------------------------------------------------------------------
 * Es la MISMA decisión que ya tomó `mk_media` para `mediable_id`, y por la
 * misma razón: el paquete tiene consumers de los dos tipos —RETO usa modelos
 * uuid, Condaty usa bigint en varias tablas— y una tabla del paquete no puede
 * elegir por ellos. El origen en Condaty usaba `foreignUuid` porque ahí
 * `users.id` y `clients.id` son uuid por contrato propio; acá eso sería
 * imponerle el tipo de PK a todo consumer futuro.
 *
 * Costo aceptado: se pierde la FK declarativa. A cambio, la limpieza de
 * huérfanos la hace `mk:reports-clean` por `expires_at`, que además tiene que
 * existir igual para borrar los ARCHIVOS —cosa que un `onDelete('cascade')`
 * nunca hizo—.
 *
 * `tenant_id` es nullable a propósito: `mk_director.tenant.enabled` es opt-in
 * (ADR-003), y en una app single-tenant la columna simplemente queda vacía.
 *
 * El nombre de tabla es configurable (`config('mk_director.export.table')`),
 * mismo patrón que `mk_media` y `mk_progressive_codes`.
 *
 * Guard `Schema::hasTable` para que una doble carga (tests que corren la
 * migración más de una vez) sea no-op en vez de error de tabla duplicada.
 */
return new class extends Migration
{
    public function up(): void
    {
        $table = config('mk_director.export.table', 'mk_reports');

        if (Schema::hasTable($table)) {
            return;
        }

        Schema::create($table, function (Blueprint $blueprint) use ($table): void {
            $blueprint->id();

            // Identificador PÚBLICO. Es lo que viaja al front y lo que aceptan
            // `status` y `download`, para no exponer el autoincremental —que
            // filtra cuántos reportes genera el sistema y permite tantear ids
            // ajenos—.
            $blueprint->uuid('uuid')->unique();

            // Ids del consumer. Ver la nota de arriba sobre por qué string.
            $blueprint->string('user_id');
            $blueprint->string('tenant_id')->nullable();

            // Clave del módulo en el registry (e.g. 'payments', 'balance').
            // Tiene que coincidir EXACTA con el `module()` del ExportConfig:
            // si se separan, el motor no encuentra el config y el export se va
            // por el fallback del consumer sin error visible.
            $blueprint->string('type', 64);

            // 'pdf' | 'xlsx' | 'csv' — normalizados por AsyncExportManager.
            $blueprint->string('format', 16)->default('pdf');

            // Los filtros del pedido. Es lo ÚNICO que se guarda: de acá el job
            // reconstruye la MISMA lista que veía el usuario.
            //
            // 🔴 Acá NO va la data. Serializar la lista entera fue lo que
            // reventó con accesos en Condaty (198.004 filas, ~422 MB de JSON):
            // el request se quedaba sin memoria antes de responder.
            $blueprint->json('params')->nullable();

            // pending | processing | completed | failed
            $blueprint->string('status', 16)->default('pending');

            $blueprint->string('file_path')->nullable();
            $blueprint->text('error_message')->nullable();

            // Progreso para la barra del front.
            $blueprint->unsignedTinyInteger('progress')->default(0);
            $blueprint->unsignedInteger('total_chunks')->nullable();
            $blueprint->unsignedInteger('current_chunk')->nullable();

            // Cuándo se lo lleva `mk:reports-clean`, con su archivo.
            $blueprint->timestamp('expires_at')->nullable();

            $blueprint->timestamps();

            // El índice del historial: "mis descargas", filtrando por estado.
            $blueprint->index(['user_id', 'status'], $table.'_user_status_idx');

            // El del limpiador.
            $blueprint->index(['expires_at'], $table.'_expires_at_idx');

            // El del historial por módulo, que el front filtra.
            $blueprint->index(['tenant_id', 'type'], $table.'_tenant_type_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('mk_director.export.table', 'mk_reports'));
    }
};
