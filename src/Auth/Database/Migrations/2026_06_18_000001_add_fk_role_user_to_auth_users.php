<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R3-014 hardening (1.2.2): `role_user.user_id` was declared as a `uuid`
 * column with NO foreign key. Deleting an `auth_users` row would leave
 * orphan rows in `role_user`, silently breaking role-based authorization
 * (no error, but the orphaned user_id references no one).
 *
 * This migration adds the missing FK to `auth_users.id` (uuid) with
 * `cascadeOnDelete()` so the inverse is also handled: deleting an
 * `auth_users` row now drops the `role_user` rows referencing it.
 *
 * NOT modifying the original migration: pre-1.2 installs already have
 * the `role_user` table without this FK, and the `down()` here is
 * intentionally wrapped in `try/catch` so re-running on a fresh install
 * (where the FK does not yet exist) does not crash.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Skip silently if `role_user` does not exist (consumer app may
        // not have run the package's auth migrations yet, or may have a
        // custom migration set).
        if (! Schema::hasTable('role_user')) {
            return;
        }

        // Skip if the FK already exists (idempotent re-run).
        $existing = collect(Schema::getForeignKeys('role_user'))
            ->firstWhere('columns', '=', ['user_id'])
            ?? null;

        if ($existing !== null) {
            return;
        }

        Schema::table('role_user', function (Blueprint $table) {
            $table->foreign('user_id')
                ->references('id')
                ->on('auth_users')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        // Drop the FK if it exists. Wrapped in try/catch so re-running
        // `down()` on a fresh install (where the FK was never created)
        // does not blow up — the user's intent is to roll back, not to
        // debug FK state.
        try {
            Schema::table('role_user', function (Blueprint $table) {
                $table->dropForeign(['user_id']);
            });
        } catch (\Throwable) {
            // FK did not exist (fresh install, pre-1.2 schema, etc.) —
            // nothing to drop. Swallow.
        }
    }
};
