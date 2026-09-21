<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per part that's on the "Heijunka Box" schedule — a fixed,
 * pre-planned daily release timetable (the classic TPS heijunka box), as
 * opposed to the existing Kesei Heijunka board's LT/KBN pacing. Every part
 * on this schedule shares the same underlying 32-slot clock (see
 * HeijunkaBoxBoard::SLOT_TIMES) — `slots` is just which of those 32
 * time-of-day slots belong to THIS part, expanded out (a slot marked "2" in
 * the source sheet appears twice in the list, meaning 2 kanban release at
 * that exact time), imported once from a fixed source spreadsheet (see the
 * following migration) rather than computed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('heijunka_box_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('part_id')->unique()->constrained()->cascadeOnDelete();
            // The heijunka-box "pitch" group this part belongs to (e.g.
            // "1-2-X") — purely informational/grouping, nothing in the
            // board's own logic branches on it; the actual schedule is
            // entirely captured by `slots`.
            $table->string('cycle_issue');
            // Every "H:i" time-of-day this part releases at, in chronological
            // order across the full day (07:10 → ... → 04:55 next day) — see
            // the class doc above for why a time can repeat.
            $table->json('slots');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('heijunka_box_schedules');
    }
};
