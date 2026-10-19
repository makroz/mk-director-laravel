<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The app the perf tests measure: a module table with a parent outside the module, its admins and Sanctum's
 * tokens. Roles and abilities come from the package's own migrations (MkServiceProvider loads them).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('perf_owners', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
            $t->timestamps();
        });
        Schema::create('perf_items', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('owner_id')->constrained('perf_owners');
            $t->string('name', 80);
            $t->unsignedTinyInteger('status');
            $t->timestamps();
        });
        // uuid keys, like the package's auth users (its RBAC pivots and Sanctum's tokens point at them).
        Schema::create('perf_admins', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->string('name');
            $t->string('email')->unique();
            $t->string('password');
            $t->string('auth_scope')->default('admin');
            $t->timestamps();
        });
        Schema::create('personal_access_tokens', function (Blueprint $t): void {
            $t->id();
            $t->uuidMorphs('tokenable');
            $t->text('name');
            $t->string('token', 64)->unique();
            $t->text('abilities')->nullable();
            $t->timestamp('last_used_at')->nullable();
            $t->timestamp('expires_at')->nullable()->index();
            $t->timestamps();
        });
    }
};
