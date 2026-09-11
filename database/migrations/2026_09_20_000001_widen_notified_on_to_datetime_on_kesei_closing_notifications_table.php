<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A Kesei part can now carry more than one closing time, each notifying
 * independently. `notified_on` used to be a bare date, so a second closing on
 * the same calendar day would collide with the first on the (kesei_part_id,
 * notified_on) unique index and be silently treated as "already sent". It now
 * carries the exact closing instant.
 *
 * doctrine/dbal is not installed, so this widens the column by drop + re-add
 * (portable across MySQL and the SQLite test database) instead of ->change().
 */
return new class extends Migration
{
    public function up(): void
    {
        $existing = DB::table('kesei_closing_notifications')->select('id', 'notified_on')->get();

        // MySQL was using the (kesei_part_id, notified_on) unique index to
        // satisfy the kesei_part_id foreign key (no other index covers it) —
        // it refuses to drop that index otherwise. Give the FK somewhere else
        // to point first.
        Schema::table('kesei_closing_notifications', function (Blueprint $table) {
            $table->index('kesei_part_id');
        });

        Schema::table('kesei_closing_notifications', function (Blueprint $table) {
            $table->dropUnique(['kesei_part_id', 'notified_on']);
        });

        Schema::table('kesei_closing_notifications', function (Blueprint $table) {
            $table->dropColumn('notified_on');
        });

        Schema::table('kesei_closing_notifications', function (Blueprint $table) {
            $table->dateTime('notified_on')->nullable()->after('kesei_part_id');
        });

        // Old values had no time-of-day — backfill as midnight so they keep
        // sorting/filtering correctly relative to the new datetime rows.
        foreach ($existing as $row) {
            DB::table('kesei_closing_notifications')->where('id', $row->id)
                ->update(['notified_on' => $row->notified_on.' 00:00:00']);
        }

        Schema::table('kesei_closing_notifications', function (Blueprint $table) {
            $table->unique(['kesei_part_id', 'notified_on']);
        });
    }

    public function down(): void
    {
        $existing = DB::table('kesei_closing_notifications')->select('id', 'notified_on')->get();

        Schema::table('kesei_closing_notifications', function (Blueprint $table) {
            $table->dropUnique(['kesei_part_id', 'notified_on']);
        });

        Schema::table('kesei_closing_notifications', function (Blueprint $table) {
            $table->dropColumn('notified_on');
        });

        Schema::table('kesei_closing_notifications', function (Blueprint $table) {
            $table->date('notified_on')->nullable()->after('kesei_part_id');
        });

        // Best-effort: a second same-day closing collapses onto the first.
        foreach ($existing as $row) {
            DB::table('kesei_closing_notifications')->where('id', $row->id)
                ->update(['notified_on' => substr((string) $row->notified_on, 0, 10)]);
        }

        Schema::table('kesei_closing_notifications', function (Blueprint $table) {
            $table->unique(['kesei_part_id', 'notified_on']);
        });
    }
};
