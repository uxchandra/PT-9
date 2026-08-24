<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * proses/total_kanban/dandori move from the per-machine assignment (patterns)
     * to the board+part master list (pattern_group_items), since they turned out to
     * describe the part's process within the board rather than a specific machine
     * assignment. lot/cover_stock_days are dropped as no longer needed.
     */
    public function up(): void
    {
        Schema::table('pattern_group_items', function (Blueprint $table) {
            $table->dropColumn(['lot', 'cover_stock_days']);
            $table->unsignedInteger('proses')->nullable()->after('jumlah_proses');
            $table->unsignedInteger('total_kanban')->nullable()->after('proses');
            $table->unsignedInteger('dandori')->nullable()->after('total_kanban');
        });

        foreach (DB::table('patterns')->get() as $pattern) {
            DB::table('pattern_group_items')
                ->where('pattern_board_id', $pattern->pattern_board_id)
                ->where('part_id', $pattern->part_id)
                ->update([
                    'proses' => $pattern->proses,
                    'total_kanban' => $pattern->total_kanban,
                    'dandori' => $pattern->dandori,
                ]);
        }

        Schema::table('patterns', function (Blueprint $table) {
            $table->dropColumn(['proses', 'total_kanban', 'dandori']);
        });
    }

    public function down(): void
    {
        Schema::table('patterns', function (Blueprint $table) {
            $table->unsignedInteger('proses')->nullable();
            $table->unsignedInteger('total_kanban')->nullable();
            $table->unsignedInteger('dandori')->nullable();
        });

        foreach (DB::table('patterns')->get() as $pattern) {
            $groupItem = DB::table('pattern_group_items')
                ->where('pattern_board_id', $pattern->pattern_board_id)
                ->where('part_id', $pattern->part_id)
                ->first();

            DB::table('patterns')->where('id', $pattern->id)->update([
                'proses' => $groupItem?->proses,
                'total_kanban' => $groupItem?->total_kanban,
                'dandori' => $groupItem?->dandori,
            ]);
        }

        Schema::table('pattern_group_items', function (Blueprint $table) {
            $table->dropColumn(['proses', 'total_kanban', 'dandori']);
            $table->unsignedInteger('lot')->nullable();
            $table->decimal('cover_stock_days', 5, 2)->nullable();
        });
    }
};
