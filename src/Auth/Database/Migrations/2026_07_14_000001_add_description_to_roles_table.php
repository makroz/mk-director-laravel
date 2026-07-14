<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migración publicable del paquete mk/director-laravel.
 *
 * Agrega la columna `description` (nullable) a `roles`.
 *
 * Motivo: los roles necesitan describir QUÉ hacen (UI de gestión de roles). La
 * tabla original (`2026_06_10_000002_create_roles_table`) NO la tenía —
 * `RoleResource` ya serializaba `description` (leía `null` inofensivo) y el
 * mass-assignment la aceptaba, pero el INSERT/UPDATE explotaba con
 * `SQLSTATE[42703] column "description" does not exist`. Esta migración cierra
 * ese gap sin re-correr la migración original (aditiva + idempotente vía
 * `hasColumn`, para instalaciones nuevas Y existentes).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('roles', 'description')) {
            Schema::table('roles', function (Blueprint $table) {
                $table->string('description')->nullable()->after('guard');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('roles', 'description')) {
            Schema::table('roles', function (Blueprint $table) {
                $table->dropColumn('description');
            });
        }
    }
};
