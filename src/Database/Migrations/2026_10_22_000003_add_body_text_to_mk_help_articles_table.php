<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migración del paquete — `body_text` en `mk_help_articles`: el cuerpo en
 * TEXTO PLANO (sin Markdown, sin HTML, sin las URLs de imágenes y enlaces),
 * que llena `mk:help:sync`. De ahí salen el fragmento de la búsqueda y el
 * `LIKE` de los drivers que no son `pgsql`.
 *
 * En `pgsql` rehace la columna generada `search` para que indexe `body_text`
 * en vez del Markdown: si no, buscar `help` o el dominio del storage encuentra
 * todos los artículos con imágenes. Una columna generada no se puede alterar,
 * así que se borra (con su índice GIN) y se vuelve a crear. Hasta la próxima
 * sync, `coalesce` usa el Markdown.
 *
 * 🔴 TILDES. El diccionario `spanish` NO las saca, y la gente escribe sin
 * ellas: medido en Postgres 18, `contrasena` no encontraba `contraseña`
 * (`contrasen` ≠ `contraseñ`) ni `verificacion` a `verificación` (`verif`).
 * Plegar todo a sin tilde tampoco sirve: el stemmer sólo reconoce `-ación`
 * CON tilde, así que `verificacion` (sin tilde) dejaba de encontrar
 * `verificaciones`. Por eso `search` indexa cada texto DOS veces, tal cual y
 * plegado con `translate()` (inmutable y de serie: no pide la extensión
 * `unaccent`, que además es STABLE y una columna generada no la acepta sin un
 * wrapper). La consulta va tal cual y encuentra a una de las dos.
 * Límite conocido: una búsqueda sin tilde encuentra el artículo, pero
 * `ts_headline` no marca la palabra (la lee con tilde).
 */
return new class extends Migration
{
    public function up(): void
    {
        $table = config('mk_director.help.articles_table', 'mk_help_articles');

        if (! Schema::hasTable($table) || Schema::hasColumn($table, 'body_text')) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint): void {
            $blueprint->text('body_text')->nullable()->after('body');
        });

        $this->rebuildSearch($table, "coalesce(body_text, body, '')");
    }

    public function down(): void
    {
        $table = config('mk_director.help.articles_table', 'mk_help_articles');

        if (! Schema::hasColumn($table, 'body_text')) {
            return;
        }

        // La columna generada depende de `body_text`: primero se va ella.
        $this->rebuildSearch($table, null);
        Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropColumn('body_text'));
        $this->rebuildSearch($table, "coalesce(body, '')");
    }

    /** @param  string|null  $body  la expresión del cuerpo; null sólo borra `search` */
    private function rebuildSearch(string $table, ?string $body): void
    {
        $connection = Schema::getConnection();

        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }

        $connection->statement("ALTER TABLE {$table} DROP COLUMN IF EXISTS search");

        if ($body === null) {
            return;
        }

        $title = "coalesce(title, '')";
        $fold = fn (string $text) => "translate({$text}, 'áéíóúüñÁÉÍÓÚÜÑ', 'aeiouunAEIOUUN')";

        $connection->statement(<<<SQL
            ALTER TABLE {$table} ADD COLUMN search tsvector GENERATED ALWAYS AS (
                setweight(to_tsvector('spanish', {$title}) || to_tsvector('spanish', {$fold($title)}), 'A')
                || setweight(to_tsvector('spanish', {$body}) || to_tsvector('spanish', {$fold($body)}), 'B')
            ) STORED
            SQL);
        $connection->statement("CREATE INDEX {$table}_search_idx ON {$table} USING GIN (search)");
    }
};
