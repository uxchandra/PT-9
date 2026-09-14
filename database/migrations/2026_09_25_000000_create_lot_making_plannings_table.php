<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per Lot Making part "thrown" into planning the moment a scan-based
 * lot cycle completes (see LotMakingCycleTracker::checkForCompletion) — a
 * queue of finished lots waiting to be scheduled into a machine's Andon
 * timeline. Staff assigns a Pattern Board + Machine + Shift + proses, which
 * creates a real `patterns` row (an ordinary Assignment Mesin) so the part
 * shows up on Andon filling a FREE TIME slot exactly like any other
 * assignment — no new rendering logic needed on that side.
 *
 * "Finish" deletes that `patterns` row (the slot goes back to FREE TIME) but
 * keeps this row around with `finished_at` set, as a history trail of what
 * got planned and when.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lot_making_plannings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('part_id')->constrained()->cascadeOnDelete();
            // Snapshot of the completed lot's size — stays meaningful even if
            // the Lot Making part's own lot_produksi changes later.
            $table->unsignedInteger('lot');
            $table->foreignId('lot_making_cycle_id')->nullable()->constrained()->nullOnDelete();

            // Filled in by staff when assigning this planning row to a slot.
            $table->foreignId('pattern_board_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('machine_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedTinyInteger('shift')->nullable();
            $table->unsignedInteger('proses')->nullable();

            // The `patterns` row created once assigned — nulled automatically
            // when that row is deleted (on Finish, or if removed some other
            // way), which is what actually makes the Andon block disappear.
            $table->foreignId('pattern_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['finished_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lot_making_plannings');
    }
};
