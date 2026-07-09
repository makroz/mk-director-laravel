<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migración publicable del paquete mk/director-laravel.
 *
 * Crea la tabla `roles` — agrupa abilities y se asigna a AuthUser.
 *
 * Spec: MK-LAR-1.0.2.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            // F6-03 (FEEDBACK6, 🟠 multi-scope): la uniqueness es COMPUESTA (name, guard),
            // NO global sobre `name`. Cada scope siembra los mismos nombres de rol
            // (`super-admin/admin/editor/viewer`) pero con su propio `guard`. Con un
            // unique global sobre `name`, el 2º scope o bien colisionaba (insert) o
            // pisaba el `guard` de las filas compartidas (updateOrCreate por `name`)
            // → el picker de roles del 1er scope salía vacío (setExtraData filtra por
            // guard). La clave (name, guard) permite `admin/super-admin/...` por scope.
            $table->string('name');
            $table->string('guard')->default('web');
            $table->unsignedTinyInteger('is_fixed')->default(0);
            $table->timestamps();

            $table->unique(['name', 'guard']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
