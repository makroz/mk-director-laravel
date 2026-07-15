<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migración del paquete mk-director-laravel — tabla genérica `verification_codes`.
 *
 * Spec: 2026-07-15-profile-edit-password-otp, ADR-1 (design.md).
 *
 * Store agnóstico de scope y de `purpose` (hoy: `password_change`; futuro:
 * `login_2fa`, `email_verify`). Guarda SOLO el hash del código (bcrypt vía
 * `Hash::make`); el valor plaintext viaja únicamente en el payload del
 * `AuthEvent` emitido por el caller, nunca se persiste ni se loggea.
 *
 * Clave de lookup: `(auth_scope, purpose, identifier)` — sin UNIQUE
 * constraint: `EmailOtpService::issue()` usa `updateOrInsert` para
 * reemplazar cualquier código vivo de la misma tupla (una request nueva
 * invalida la anterior — propiedad de seguridad, no bug).
 *
 * R3 (design.md § Architectural Risks): esta migración se shipea en el
 * paquete (`MkServiceProvider::loadMigrationsFrom`), no per-scope. Apps
 * existentes deben correr `php artisan migrate` al actualizar el paquete
 * (documentado en CHANGELOG). Guard `Schema::hasTable` para que una
 * doble-carga (e.g. tests que corren la migración más de una vez) sea
 * un no-op en vez de un error de tabla duplicada.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('verification_codes')) {
            return;
        }

        Schema::create('verification_codes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('auth_scope');
            $table->string('purpose');
            $table->string('identifier');
            $table->string('code_hash');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedTinyInteger('max_attempts');
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['auth_scope', 'purpose', 'identifier'], 'verification_codes_scope_purpose_identifier_idx');
            $table->index('expires_at', 'verification_codes_expires_at_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('verification_codes');
    }
};
