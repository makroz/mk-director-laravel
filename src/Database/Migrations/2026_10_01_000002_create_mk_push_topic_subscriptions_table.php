<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migración del paquete mk-director-laravel — tabla `mk_push_topic_subscriptions`.
 *
 * Quién está suscripto a qué tema («novedades», «proyecto X»).
 *
 * Los temas viven en NUESTRA base y no en el servicio: así se comportan igual
 * con FCM y con OneSignal, y cambiar de servicio no pierde suscripciones. La
 * suscripción es de la PERSONA, no del teléfono: un teléfono nuevo de alguien
 * ya suscripto recibe el tema sin volver a suscribirse.
 *
 * Guard `Schema::hasTable` para que una doble carga sea no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('mk_push_topic_subscriptions')) {
            return;
        }

        Schema::create('mk_push_topic_subscriptions', function (Blueprint $table): void {
            // string() por lo mismo que en `mk_push_devices`.
            $table->string('owner_type');
            $table->string('owner_id');
            $table->string('topic');
            $table->timestamps();

            // Suscribirse dos veces al mismo tema no duplica los envíos.
            $table->unique(['owner_type', 'owner_id', 'topic'], 'mk_push_topic_subscriptions_owner_topic_unique');
            $table->index('topic', 'mk_push_topic_subscriptions_topic_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mk_push_topic_subscriptions');
    }
};
