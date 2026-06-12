<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migración publicable del paquete mk/director-laravel.
 *
 * Crea la tabla pivote `ability_role` — relación N:N entre
 * `abilities` y `roles`.
 *
 * Spec: MK-LAR-1.0.2.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ability_role', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ability_id')
                ->constrained('abilities')
                ->cascadeOnDelete();
            $table->foreignId('role_id')
                ->constrained('roles')
                ->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['ability_id', 'role_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ability_role');
    }
};
