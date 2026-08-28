<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Shift 1 (07:00-16:00) and Shift 2 (20:00-06:00) can now have their own
     * loading_time/jumlah_proses/total_kanban/dandori for the same part on the
     * same board, so the andon timeline can schedule both shifts separately
     * instead of leaving shift 2 always empty.
     */
    public function up(): void
    {
        Schema::table('pattern_group_items', function (Blueprint $table) {
            $table->unsignedTinyInteger('shift')->default(1)->after('part_id');
        });

        // Add the new unique index before dropping the old one — MySQL won't drop
        // an index still backing the pattern_board_id/part_id foreign keys unless
        // another index covering them already exists.
        Schema::table('pattern_group_items', function (Blueprint $table) {
            $table->unique(['pattern_board_id', 'part_id', 'shift']);
        });

        Schema::table('pattern_group_items', function (Blueprint $table) {
            $table->dropUnique(['pattern_board_id', 'part_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pattern_group_items', function (Blueprint $table) {
            $table->unique(['pattern_board_id', 'part_id']);
        });

        Schema::table('pattern_group_items', function (Blueprint $table) {
            $table->dropUnique(['pattern_board_id', 'part_id', 'shift']);
            $table->dropColumn('shift');
        });
    }
};
