<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migración del paquete mk-director-laravel — tabla `mk_push_devices`.
 *
 * Un teléfono registrado para recibir push: a quién pertenece, con qué servicio
 * y su dirección en ese servicio (el token de FCM o el subscription id de
 * OneSignal).
 *
 * 🔴 EL UNIQUE `(provider, address)` ES LA REGLA, NO UN DETALLE
 * -------------------------------------------------------------
 * Un teléfono tiene UN solo dueño. Si en el mismo celular cierra sesión Ana y
 * entra Beto, el registro de Beto tiene que MOVER la fila, no sumar otra: con
 * dos filas, los avisos de Ana le seguirían llegando a Beto. El registro es un
 * `upsert` sobre este índice, así que la base es la que garantiza el dueño
 * único, también con dos registros simultáneos.
 *
 * `owner_id` es string() por la misma razón que `mediable_id` en `mk_media`:
 * hay consumers con PKs uuid y bigint, y `morphs()`/`uuidMorphs()` clavan uno.
 *
 * Guard `Schema::hasTable` para que una doble carga sea no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('mk_push_devices')) {
            return;
        }

        Schema::create('mk_push_devices', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->string('owner_type');
            $table->string('owner_id');

            // 'fcm' | 'onesignal' — el servicio que emitió la dirección.
            $table->string('provider', 16);
            $table->string('address', 4096);

            // 'ios' | 'android'
            $table->string('platform', 16);

            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'address'], 'mk_push_devices_provider_address_unique');

            // El del envío: "todos los teléfonos de esta persona".
            $table->index(['owner_type', 'owner_id'], 'mk_push_devices_owner_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mk_push_devices');
    }
};
