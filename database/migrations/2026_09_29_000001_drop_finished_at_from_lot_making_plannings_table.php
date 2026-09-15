<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The lot itself no longer has a single finished state — each of its proses
 * steps closes independently (see lot_making_planning_assignments.finished_at).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lot_making_plannings', function (Blueprint $table) {
            $table->dropIndex(['finished_at']);
        });
        Schema::table('lot_making_plannings', function (Blueprint $table) {
            $table->dropColumn('finished_at');
        });
    }

    public function down(): void
    {
        Schema::table('lot_making_plannings', function (Blueprint $table) {
            $table->timestamp('finished_at')->nullable();
        });
        Schema::table('lot_making_plannings', function (Blueprint $table) {
            $table->index(['finished_at']);
        });
    }
};
