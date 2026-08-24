<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * proses (e.g. 1/3) turned out to vary per machine assignment, not per
     * board+part: the same part can be process 1/3 on one machine and 2/3 on
     * another. It moves back to patterns; total_kanban/dandori stay on
     * pattern_group_items.
     */
    public function up(): void
    {
        Schema::table('patterns', function (Blueprint $table) {
            $table->unsignedInteger('proses')->nullable()->after('part_id');
        });

        foreach (DB::table('patterns')->get() as $pattern) {
            $groupItem = DB::table('pattern_group_items')
                ->where('pattern_board_id', $pattern->pattern_board_id)
                ->where('part_id', $pattern->part_id)
                ->first();

            if ($groupItem) {
                DB::table('patterns')->where('id', $pattern->id)->update([
                    'proses' => $groupItem->proses,
                ]);
            }
        }

        Schema::table('pattern_group_items', function (Blueprint $table) {
            $table->dropColumn('proses');
        });
    }

    public function down(): void
    {
        Schema::table('pattern_group_items', function (Blueprint $table) {
            $table->unsignedInteger('proses')->nullable()->after('jumlah_proses');
        });

        foreach (DB::table('patterns')->get() as $pattern) {
            DB::table('pattern_group_items')
                ->where('pattern_board_id', $pattern->pattern_board_id)
                ->where('part_id', $pattern->part_id)
                ->update(['proses' => $pattern->proses]);
        }

        Schema::table('patterns', function (Blueprint $table) {
            $table->dropColumn('proses');
        });
    }
};
