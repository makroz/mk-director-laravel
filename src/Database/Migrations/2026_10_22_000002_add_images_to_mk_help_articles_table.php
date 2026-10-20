<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migración del paquete — `images` en `mk_help_articles`: las imágenes del
 * artículo ya subidas al rol público, `[{url, width, height}]`. El front sólo
 * pinta las que están en esta lista.
 *
 * Nullable: la llena `mk:help:sync`, que reescribe cada fila una vez (el hash
 * que guarda ahora cubre también las URLs de las imágenes).
 */
return new class extends Migration
{
    public function up(): void
    {
        $table = config('mk_director.help.articles_table', 'mk_help_articles');

        if (! Schema::hasTable($table) || Schema::hasColumn($table, 'images')) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint): void {
            $blueprint->json('images')->nullable()->after('body');
        });
    }

    public function down(): void
    {
        $table = config('mk_director.help.articles_table', 'mk_help_articles');

        if (Schema::hasColumn($table, 'images')) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropColumn('images'));
        }
    }
};
