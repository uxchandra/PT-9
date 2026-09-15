<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shift is picked per proses step, not once for the whole lot — two steps
 * of the same lot can genuinely run on different shifts (one on shift 1,
 * the next on shift 2), so it moves from the lot-level lot_making_plannings
 * row down to each individual lot_making_planning_assignments row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lot_making_planning_assignments', function (Blueprint $table) {
            $table->unsignedTinyInteger('shift')->nullable()->after('proses');
        });
        Schema::table('lot_making_plannings', function (Blueprint $table) {
            $table->dropColumn('shift');
        });
    }

    public function down(): void
    {
        Schema::table('lot_making_plannings', function (Blueprint $table) {
            $table->unsignedTinyInteger('shift')->nullable();
        });
        Schema::table('lot_making_planning_assignments', function (Blueprint $table) {
            $table->dropColumn('shift');
        });
    }
};
