<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lot Making now has two independent Andon boards feeding this same table —
 * "Lot Making 1" (ticks from real operator scans) and "Lot Making 2" (ticks
 * straight from the SOS kanban-pull/stock-decrease feed, no scan required).
 * Without a way to tell their completions apart, the two boards' cycle
 * counters and roller panels would bleed into each other. Existing rows all
 * came from the scan-driven board, so they backfill as 'scan'.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lot_making_cycles', function (Blueprint $table) {
            $table->string('source')->default('scan')->after('lot_produksi');
        });

        DB::table('lot_making_cycles')->update(['source' => 'scan']);

        Schema::table('lot_making_cycles', function (Blueprint $table) {
            $table->index(['part_no', 'source', 'completed_at']);
        });
    }

    public function down(): void
    {
        Schema::table('lot_making_cycles', function (Blueprint $table) {
            $table->dropIndex(['part_no', 'source', 'completed_at']);
            $table->dropColumn('source');
        });
    }
};
