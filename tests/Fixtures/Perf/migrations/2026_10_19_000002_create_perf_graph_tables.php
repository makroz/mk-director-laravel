<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The relation graph the volume tests fill: the module PerfGraph owns `perf_notes` and `perf_tags`.
 *
 *  - `perf_notes.team_id` → `perf_teams.region_id` → `perf_regions`: parents outside the module, two levels deep;
 *  - `perf_notes.author_type` + `author_id`: polymorphic, to an owner or an item (the morph map); its state columns
 *    (an enum with a default, a counter, a nullable step and a nullable one-to-one link) are spread by the volume;
 *    the link and its `pinned_at` are set together or not at all (a CHECK);
 *  - `perf_tags`: a format CHECK on a unique code, and exactly one of `item_id` / `owner_id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('perf_regions', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
        });
        Schema::create('perf_teams', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('region_id')->constrained('perf_regions');
            $t->string('name');
        });
        Schema::create('perf_notes', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('team_id')->constrained('perf_teams');
            $t->morphs('author');
            $t->text('body');
            // State: a default, a counter, a step not reached by every row, a one-to-one link not every row has.
            $t->unsignedTinyInteger('status')->default(1);
            $t->unsignedInteger('views')->default(0);
            $t->timestamp('read_at')->nullable();
            $t->foreignId('pinned_item_id')->nullable()->unique()->constrained('perf_items');
            $t->timestamp('pinned_at')->nullable();
            $t->softDeletes();
            $t->timestamps();
        });
        Schema::create('perf_tags', function (Blueprint $t): void {
            $t->id();
            $t->string('code', 12)->unique();
            $t->foreignId('item_id')->nullable()->constrained('perf_items');
            $t->foreignId('owner_id')->nullable()->constrained('perf_owners');
        });
        $format = DB::getDriverName() === 'pgsql' ? "code ~ '^[A-Z]{3}-[0-9]{2}$'" : "code regexp '^[A-Z]{3}-[0-9]{2}$'";
        DB::statement("alter table perf_tags add constraint perf_tags_code_format check ({$format})");
        DB::statement('alter table perf_tags add constraint perf_tags_one_owner check ((item_id is null) <> (owner_id is null))');
        DB::statement('alter table perf_notes add constraint perf_notes_pinned_together check ((pinned_item_id is null) = (pinned_at is null))');
    }
};
