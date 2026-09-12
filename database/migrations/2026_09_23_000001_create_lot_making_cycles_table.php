<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per completed Lot Making cycle — the moment a part's scanned count
 * (since the previous completion) reaches its lot_produksi, all its slot
 * columns are full. Feeds the Andon Lot Making roller panel. Keyed by
 * part_no (not a lot_makings FK) so the log survives that record being
 * edited or deleted, same reasoning as kesei_scans.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lot_making_cycles', function (Blueprint $table) {
            $table->id();
            $table->string('part_no');
            // Snapshot of lot_produksi at completion time, for the roller
            // panel's "Lot {n}" label even if the part's config changes later.
            $table->unsignedInteger('lot_produksi');
            $table->timestamp('completed_at');
            $table->timestamp('created_at')->nullable();

            $table->index(['part_no', 'completed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lot_making_cycles');
    }
};
