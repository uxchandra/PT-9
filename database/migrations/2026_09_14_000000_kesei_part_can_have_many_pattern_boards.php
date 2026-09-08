<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kesei_part_pattern_board', function (Blueprint $table) {
            $table->foreignId('kesei_part_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pattern_board_id')->constrained()->cascadeOnDelete();
            $table->primary(['kesei_part_id', 'pattern_board_id']);
        });

        // Carry over the existing single pattern_board_id values.
        DB::table('kesei_parts')
            ->whereNotNull('pattern_board_id')
            ->select('id', 'pattern_board_id')
            ->orderBy('id')
            ->chunk(200, function ($rows) {
                DB::table('kesei_part_pattern_board')->insert(
                    $rows->map(fn ($row) => [
                        'kesei_part_id' => $row->id,
                        'pattern_board_id' => $row->pattern_board_id,
                    ])->all()
                );
            });

        Schema::table('kesei_parts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pattern_board_id');
        });
    }

    public function down(): void
    {
        Schema::table('kesei_parts', function (Blueprint $table) {
            $table->foreignId('pattern_board_id')->nullable()->after('closing_time')
                ->constrained()->nullOnDelete();
        });

        DB::table('kesei_part_pattern_board')
            ->orderBy('kesei_part_id')
            ->get()
            ->groupBy('kesei_part_id')
            ->each(function ($rows, $keseiPartId) {
                DB::table('kesei_parts')->where('id', $keseiPartId)
                    ->update(['pattern_board_id' => $rows->first()->pattern_board_id]);
            });

        Schema::dropIfExists('kesei_part_pattern_board');
    }
};
