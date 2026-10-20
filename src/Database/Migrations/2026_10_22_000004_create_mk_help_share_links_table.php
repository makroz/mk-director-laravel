<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migración del paquete — `mk_help_share_links`, los enlaces públicos y
 * temporales a un manual o a un artículo. Ver `Mk\Director\Help\MkHelpShareLinks`.
 *
 * 🔴 El token NO se guarda: sólo su sha256 (`token_hash`). El token crudo vive
 * en la URL que se le devuelve UNA vez a quien lo crea; una copia de la base
 * no abre ningún enlace.
 *
 * `target`: `slug:<slug>` (un artículo) o `manual:<audience>` (un manual entero).
 *
 * `created_by_*` es polimórfico y string, como `mk_uploads.owner_*`: hay
 * consumers con varios modelos de usuario y con PKs uuid y bigint.
 *
 * Guard `Schema::hasTable` como `mk_uploads`: una doble carga es no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        $table = config('mk_director.help.share_links_table', 'mk_help_share_links');

        if (Schema::hasTable($table)) {
            return;
        }

        Schema::create($table, function (Blueprint $blueprint) use ($table): void {
            $blueprint->uuid('id')->primary();
            $blueprint->string('token_hash', 64)->unique();
            $blueprint->string('target');
            $blueprint->string('created_by_type');
            $blueprint->string('created_by_id');
            $blueprint->timestamp('expires_at');
            $blueprint->timestamp('revoked_at')->nullable();
            $blueprint->timestamp('last_used_at')->nullable();
            $blueprint->unsignedInteger('uses')->default(0);
            $blueprint->timestamps();

            // Los enlaces de quien los creó (el diálogo «Compartir»).
            $blueprint->index(['created_by_type', 'created_by_id'], $table.'_creator_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('mk_director.help.share_links_table', 'mk_help_share_links'));
    }
};
