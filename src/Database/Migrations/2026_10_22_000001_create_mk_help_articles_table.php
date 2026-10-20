<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migración del paquete — `mk_help_articles`, el índice de los manuales.
 *
 * El contenido vive en archivos Markdown de la app; esta tabla es sólo su
 * índice, que llena `mk:help:sync`. Una fila por archivo, por `slug`.
 *
 * `ability` es INFORMATIVO: ningún permiso esconde un artículo.
 *
 * En `pgsql` suma la columna generada `search` (tsvector `spanish`, título con
 * peso A y cuerpo con peso B) y su índice GIN, para la búsqueda. En los demás
 * drivers no existe: la búsqueda cae a `LIKE`.
 *
 * Guard `Schema::hasTable` como `mk_uploads`: una doble carga es no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        $table = config('mk_director.help.articles_table', 'mk_help_articles');

        if (Schema::hasTable($table)) {
            return;
        }

        Schema::create($table, function (Blueprint $blueprint) use ($table): void {
            $blueprint->uuid('id')->primary();
            // La ruta del archivo sin `.md` (`admin/kyc/aprobar`); un `index.md`
            // toma el slug de su carpeta.
            $blueprint->string('slug')->unique();
            // admin | member | business: la carpeta de arriba.
            $blueprint->string('audience');
            $blueprint->string('ability')->nullable();
            $blueprint->string('title');
            $blueprint->text('body');
            $blueprint->json('screens');
            $blueprint->json('related');
            $blueprint->string('parent_slug')->nullable();
            $blueprint->integer('order')->default(0);
            // sha256 del archivo crudo: la sync sólo reescribe lo que cambió.
            $blueprint->string('content_hash', 64);
            $blueprint->timestamps();

            // El árbol de una audiencia.
            $blueprint->index(['audience', 'parent_slug', 'order'], $table.'_tree_idx');
        });

        $connection = Schema::getConnection();

        if ($connection->getDriverName() === 'pgsql') {
            $connection->statement(<<<SQL
                ALTER TABLE {$table} ADD COLUMN search tsvector GENERATED ALWAYS AS (
                    setweight(to_tsvector('spanish', coalesce(title, '')), 'A')
                    || setweight(to_tsvector('spanish', coalesce(body, '')), 'B')
                ) STORED
                SQL);
            $connection->statement("CREATE INDEX {$table}_search_idx ON {$table} USING GIN (search)");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists(config('mk_director.help.articles_table', 'mk_help_articles'));
    }
};
