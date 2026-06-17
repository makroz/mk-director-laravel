<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migración publicable del paquete mk/director-laravel.
 *
 * Crea la tabla `auth_users` — base abstracta para todos los
 * usuarios autenticables de mk-director (Admin, Member, etc.).
 *
 * Spec: MK-LAR-1.0.2 / MK-LAR-1.0.6 (Capa 1: auth_scope indexado).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auth_users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->string('auth_scope')->default('user')->index();
            $table->rememberToken();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auth_users');
    }
};
