<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kesei_part_closings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kesei_part_id')->constrained()->cascadeOnDelete();
            $table->time('closing_time');
            // 'pre_run': a planning cutoff in the 24h BEFORE the run it's for.
            // 'end_of_day': the close of the run's own production day.
            $table->string('closing_mode')->default('pre_run');
            $table->timestamps();

            // A part can carry more than one closing time (e.g. 05:00 and
            // 15:00), each folding/notifying independently.
            $table->unique(['kesei_part_id', 'closing_time']);
        });

        // Carry over the existing single closing_time/closing_mode per part —
        // same approach as the pattern_board_id -> many-to-many migration.
        DB::table('kesei_parts')
            ->whereNotNull('closing_time')
            ->select('id', 'closing_time', 'closing_mode')
            ->orderBy('id')
            ->chunk(200, function ($rows) {
                DB::table('kesei_part_closings')->insert(
                    $rows->map(fn ($row) => [
                        'kesei_part_id' => $row->id,
                        'closing_time' => $row->closing_time,
                        'closing_mode' => $row->closing_mode,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ])->all()
                );
            });

        Schema::table('kesei_parts', function (Blueprint $table) {
            $table->dropColumn(['closing_time', 'closing_mode']);
        });
    }

    public function down(): void
    {
        Schema::table('kesei_parts', function (Blueprint $table) {
            $table->time('closing_time')->nullable()->after('stock_source');
            $table->string('closing_mode')->default('pre_run')->after('closing_time');
        });

        // Best-effort: a part that ends up with several closings only keeps
        // its earliest one on rollback.
        DB::table('kesei_part_closings')
            ->orderBy('kesei_part_id')
            ->orderBy('closing_time')
            ->get()
            ->groupBy('kesei_part_id')
            ->each(function ($rows, $keseiPartId) {
                $first = $rows->first();
                DB::table('kesei_parts')->where('id', $keseiPartId)->update([
                    'closing_time' => $first->closing_time,
                    'closing_mode' => $first->closing_mode,
                ]);
            });

        Schema::dropIfExists('kesei_part_closings');
    }
};
