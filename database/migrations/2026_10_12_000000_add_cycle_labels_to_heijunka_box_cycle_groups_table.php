<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Room for the sheet's own "Cyc-1 / Cyc-2 / ..." sub-cycle markers, read
 * straight off each group's label row (same row as its cycle_issue text) —
 * see the following data-import migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('heijunka_box_cycle_groups', function (Blueprint $table) {
            // "H:i" time => "Cyc-N" label, only for the slot columns the
            // sheet marks one at — most slots have none.
            $table->json('cycle_labels')->nullable()->after('random_numbers');
        });
    }

    public function down(): void
    {
        Schema::table('heijunka_box_cycle_groups', function (Blueprint $table) {
            $table->dropColumn('cycle_labels');
        });
    }
};
