<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Turns the free-text `name` on each pattern row into a proper
     * pattern_boards relation, and moves loading_time/jumlah_proses into
     * pattern_group_items (keyed by board + part) so they're defined once
     * per board instead of duplicated on every machine assignment row.
     * Existing rows are preserved, not dropped.
     */
    public function up(): void
    {
        Schema::table('patterns', function (Blueprint $table) {
            $table->foreignId('pattern_board_id')->nullable()->after('id')
                ->constrained('pattern_boards')->nullOnDelete();
        });

        $boardIdsByName = [];

        foreach (DB::table('patterns')->distinct()->pluck('name') as $name) {
            $boardIdsByName[$name] = DB::table('pattern_boards')->insertGetId([
                'name' => $name,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        foreach ($boardIdsByName as $name => $boardId) {
            DB::table('patterns')->where('name', $name)->update(['pattern_board_id' => $boardId]);

            $rows = DB::table('patterns')->where('pattern_board_id', $boardId)->get();
            $urutan = 1;

            foreach ($rows->unique('part_id') as $row) {
                DB::table('pattern_group_items')->insertOrIgnore([
                    'pattern_board_id' => $boardId,
                    'part_id' => $row->part_id,
                    'urutan' => $urutan++,
                    'loading_time' => $row->loading_time,
                    'jumlah_proses' => $row->jumlah_proses,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        Schema::table('patterns', function (Blueprint $table) {
            $table->dropColumn(['name', 'loading_time', 'jumlah_proses']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('patterns', function (Blueprint $table) {
            $table->string('name')->nullable()->after('id');
            $table->unsignedInteger('loading_time')->nullable();
            $table->unsignedInteger('jumlah_proses')->nullable();
        });

        foreach (DB::table('patterns')->get() as $pattern) {
            $board = DB::table('pattern_boards')->find($pattern->pattern_board_id);
            $groupItem = DB::table('pattern_group_items')
                ->where('pattern_board_id', $pattern->pattern_board_id)
                ->where('part_id', $pattern->part_id)
                ->first();

            DB::table('patterns')->where('id', $pattern->id)->update([
                'name' => $board?->name,
                'loading_time' => $groupItem?->loading_time,
                'jumlah_proses' => $groupItem?->jumlah_proses,
            ]);
        }

        Schema::table('patterns', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pattern_board_id');
        });
    }
};
