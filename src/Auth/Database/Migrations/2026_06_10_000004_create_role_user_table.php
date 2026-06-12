<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_user', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('role_id')
                ->constrained('roles')
                ->cascadeOnDelete();
            $table->unsignedBigInteger('user_id');
            $table->string('user_type')->default('App\\Modules\\Admin\\Models\\Admin');
            $table->timestamps();

            $table->unique(['role_id', 'user_id', 'user_type'], 'role_user_unique');
            $table->index(['user_id', 'user_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_user');
    }
};
