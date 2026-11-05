<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El token de Apple que hace falta para REVOCAR el acceso al borrar una
 * cuenta (DEVELOPER_GUIDE § 3.22). Apple lo exige, y sólo da ese token al
 * canjear el `authorization_code` del login: el ID token no sirve.
 *
 * - `refresh_token`: CIFRADO con la APP_KEY (`Crypt::encryptString`). Es una
 *   credencial del usuario ante Apple.
 * - `client_id`: el `aud` del ID token. La revocación se firma para el MISMO
 *   client_id que emitió el token (el Services ID de la web o el bundle id de
 *   una app), así que se guarda con él.
 *
 * Nulas: Google no tiene, y un login de Apple sin código o sin la clave
 * configurada tampoco.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('mk_social_identities', 'refresh_token')) {
            return;
        }

        Schema::table('mk_social_identities', function (Blueprint $table) {
            $table->string('client_id')->nullable();
            $table->text('refresh_token')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('mk_social_identities', function (Blueprint $table) {
            $table->dropColumn(['client_id', 'refresh_token']);
        });
    }
};
