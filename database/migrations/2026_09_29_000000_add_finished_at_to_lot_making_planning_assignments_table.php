<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Closing now happens per proses step, not per lot — a part "splits" into
 * its jumlah_proses steps and each one finishes independently, so the
 * finished marker moves here from lot_making_plannings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lot_making_planning_assignments', function (Blueprint $table) {
            $table->timestamp('finished_at')->nullable()->after('pattern_id');
        });
    }

    public function down(): void
    {
        Schema::table('lot_making_planning_assignments', function (Blueprint $table) {
            $table->dropColumn('finished_at');
        });
    }
};
