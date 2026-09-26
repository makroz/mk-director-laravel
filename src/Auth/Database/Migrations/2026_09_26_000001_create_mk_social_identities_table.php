<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vínculo entre una cuenta de Google/Apple y un usuario de un scope
 * (DEVELOPER_GUIDE § 3.21).
 *
 * La clave es `(auth_scope, provider, subject)`: el `sub` del ID token es la
 * identidad estable del proveedor; el email no (cambia, y Apple entrega
 * relays). Por scope, porque la misma cuenta de Google puede ser un comensal y
 * también un administrador, y son usuarios distintos en tablas distintas.
 *
 * `user_id` es string para servir igual a scopes con UUID y con autoincremental.
 *
 * Prefijo `mk_`: `social_identities` a secas es un nombre que un consumer con
 * Socialite ya puede tener, y el guard `hasTable` de abajo convertiría ese
 * choque en una tabla ajena con otro esquema, sin error.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('mk_social_identities')) {
            return;
        }

        Schema::create('mk_social_identities', function (Blueprint $table) {
            $table->id();
            $table->string('auth_scope', 64);
            $table->string('provider', 32);
            $table->string('subject');
            $table->string('user_id', 64);
            $table->string('email')->nullable();
            $table->timestamps();

            $table->unique(['auth_scope', 'provider', 'subject'], 'mk_social_identities_subject_unique');
            $table->index(['auth_scope', 'user_id'], 'mk_social_identities_user_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mk_social_identities');
    }
};
