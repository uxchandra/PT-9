<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A lot is no longer assigned to one machine total — it now goes through
 * `jumlah_proses` steps, each its own machine (see
 * lot_making_planning_assignments). These fields moved there; `shift` stays
 * here since it's picked once per lot, shared across every one of its proses
 * steps.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Each column dropped in its own Schema::table() call — SQLite (used
        // in tests) rebuilds the whole table per call, and bundling several
        // foreign-key + plain column drops into one call has been observed
        // to corrupt that rebuild (an empty temp table). One at a time is
        // slower but reliable on every driver.
        Schema::table('lot_making_plannings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pattern_board_id');
        });
        Schema::table('lot_making_plannings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('machine_id');
        });
        Schema::table('lot_making_plannings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pattern_id');
        });
        Schema::table('lot_making_plannings', function (Blueprint $table) {
            $table->dropColumn('proses');
        });
        Schema::table('lot_making_plannings', function (Blueprint $table) {
            $table->dropColumn('loading_time');
        });
    }

    public function down(): void
    {
        Schema::table('lot_making_plannings', function (Blueprint $table) {
            $table->foreignId('pattern_board_id')->nullable()->after('lot_making_cycle_id')->constrained()->nullOnDelete();
            $table->foreignId('machine_id')->nullable()->after('pattern_board_id')->constrained()->nullOnDelete();
            $table->unsignedInteger('proses')->nullable()->after('shift');
            $table->foreignId('pattern_id')->nullable()->after('proses')->constrained()->nullOnDelete();
            $table->unsignedInteger('loading_time')->nullable()->after('pattern_id');
        });
    }
};
