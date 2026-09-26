<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `mk_reports.user_type` — QUÉ modelo pidió el reporte, además de su id.
 *
 * 🔴 Con `user_id` solo, un consumer con varios scopes (admin y member, cada
 * uno con su tabla) no distingue al admin 5 del member 5: el historial y la
 * descarga se validaban por id, y el job resolvía al dueño con UN modelo fijo
 * (`export.user_model`) — un reporte pedido por el member 5 corría como el
 * admin 5.
 *
 * Nullable: las filas anteriores quedan con el comportamiento viejo (sólo id,
 * modelo de la config) hasta que `mk:reports-clean` se las lleve.
 */
return new class extends Migration
{
    public function up(): void
    {
        $table = config('mk_director.export.table', 'mk_reports');

        if (! Schema::hasTable($table) || Schema::hasColumn($table, 'user_type')) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint): void {
            $blueprint->string('user_type')->nullable()->after('user_id');
        });
    }

    public function down(): void
    {
        $table = config('mk_director.export.table', 'mk_reports');

        if (Schema::hasTable($table) && Schema::hasColumn($table, 'user_type')) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropColumn('user_type');
            });
        }
    }
};
