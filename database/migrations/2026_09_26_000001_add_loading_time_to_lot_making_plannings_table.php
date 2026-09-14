<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recorded alongside pattern_board_id/machine_id/shift/proses when a part
 * not registered in Kelompok Pattern gets assigned — see the patterns table
 * migration for why that's a normal, expected case for Lot Making.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lot_making_plannings', function (Blueprint $table) {
            $table->unsignedInteger('loading_time')->nullable()->after('proses');
        });
    }

    public function down(): void
    {
        Schema::table('lot_making_plannings', function (Blueprint $table) {
            $table->dropColumn('loading_time');
        });
    }
};
