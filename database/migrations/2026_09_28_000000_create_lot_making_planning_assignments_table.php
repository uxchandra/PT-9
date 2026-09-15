<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per proses step actually assigned on a Lot Making Planning lot —
 * a lot goes through `jumlah_proses` steps (see lot_makings.jumlah_proses),
 * each independently assignable to its own machine, so a single planning row
 * can carry more than one of these. Creates a real `patterns` row per
 * assignment (an ordinary Assignment Mesin) so the part shows up on Andon
 * filling a FREE TIME slot exactly like any other assignment.
 *
 * Cancelling one deletes its `patterns` row and this row outright — no
 * history kept, since it was never actually run. Finishing the parent
 * planning (Close) instead deletes every linked `patterns` row but keeps
 * these rows (pattern_id nulled) as a record of what ran where.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lot_making_planning_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lot_making_planning_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('proses');
            $table->foreignId('machine_id')->constrained();

            // The `patterns` row created for this assignment — nulled once
            // deleted (on Cancel/Finish), which is what makes the Andon
            // block disappear.
            $table->foreignId('pattern_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamp('created_at')->nullable();

            $table->unique(['lot_making_planning_id', 'proses'], 'lmp_assignments_planning_proses_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lot_making_planning_assignments');
    }
};
