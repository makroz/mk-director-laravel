<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migración del paquete — `mk_uploads`, las subidas directas al storage.
 *
 * Una fila por subida: nace Started con el multipart abierto, pasa a Completed
 * al unir las partes y a Attached cuando un `attachUpload()` la vuelve media.
 * Ver `Mk\Director\Storage\DirectUpload\MkDirectUpload`.
 *
 * `multipart_id` es TEXT y no string(255): el UploadId de Cloudflare R2 mide
 * 343 caracteres. Un varchar(255) lo corta en MySQL estricto (o lo trunca en
 * silencio en uno laxo) y el complete falla con un id que ya no existe.
 *
 * `owner_id` es string por la misma razón que `mk_media.mediable_id`: hay
 * consumers con PKs uuid y bigint.
 *
 * Guard `Schema::hasTable` como `mk_media`: una doble carga es no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        $table = config('mk_director.storage.uploads_table', 'mk_uploads');

        if (Schema::hasTable($table)) {
            return;
        }

        Schema::create($table, function (Blueprint $blueprint) use ($table): void {
            $blueprint->uuid('id')->primary();
            $blueprint->string('disk');
            $blueprint->string('key');
            $blueprint->text('multipart_id');
            $blueprint->string('profile');
            $blueprint->string('mime')->nullable();
            $blueprint->unsignedBigInteger('bytes')->nullable();
            $blueprint->unsignedInteger('width')->nullable();
            $blueprint->unsignedInteger('height')->nullable();
            // Mk\Director\Enums\MkUploadStatus.
            $blueprint->unsignedTinyInteger('status');
            $blueprint->string('owner_type');
            $blueprint->string('owner_id');
            $blueprint->timestamp('expires_at');
            $blueprint->timestamps();

            // La limpieza de vencidas, y las subidas de un dueño.
            $blueprint->index(['status', 'expires_at'], $table.'_status_expires_idx');
            $blueprint->index(['owner_type', 'owner_id'], $table.'_owner_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('mk_director.storage.uploads_table', 'mk_uploads'));
    }
};
