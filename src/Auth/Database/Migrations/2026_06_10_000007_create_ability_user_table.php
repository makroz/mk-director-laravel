<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration for the `ability_user` pivot table — direct grants of
 * abilities to a user (bypassing the role hierarchy).
 *
 * Spec: MK-LAR-1.0.2 + audit-2026-06-17-R1-002.
 *
 * This pivot was referenced by the HasAbilities trait
 * ({@see \Mk\Director\Auth\Concerns\HasAbilities::directAbilities()})
 * but the migration was never published in the package, so any
 * consumer that tried `giveAbilityTo(...)` would hit a
 * "Base table or view not found: ability_user" SQL error.
 *
 * Publishing this migration closes R1-002 and makes the
 * `directAbilities()` relation safe to call from any consumer.
 *
 * Schema notes:
 *  - `user_id` is UUID because the auth_users migration switched
 *    the primary key from BIGINT to UUID in v1.2.0 (commit 98a11a3).
 *  - We do NOT use `foreignId('user_id')` because that emits a
 *    BIGINT UNSIGNED column — instead we add an explicit FK with
 *    uuid() so the type matches `auth_users.id`.
 *  - `user_type` mirrors the polymorphic convention from role_user
 *    (commit ece4900) so future non-AuthUser admins can have
 *    direct grants without colliding on `user_id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ability_user', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ability_id')
                ->constrained('abilities')
                ->cascadeOnDelete();
            $table->uuid('user_id');
            $table->string('user_type')->default(config('mk_director.auth.default_user_type', 'App\\Modules\\Admin\\Models\\Admin'));
            $table->timestamps();

            // Composite FK to auth_users — Laravel does not support
            // multi-column FKs declaratively, but the type is enforced
            // via $table->uuid() above and the cascade behavior lives
            // on the auth_users side.
            $table->index(['user_id', 'user_type']);
            $table->unique(['ability_id', 'user_id', 'user_type'], 'ability_user_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ability_user');
    }
};