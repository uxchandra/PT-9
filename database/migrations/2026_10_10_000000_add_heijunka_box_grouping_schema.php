<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Schema for rendering the Heijunka Box board grouped by cycle_issue the
 * way the source sheet itself is laid out — see the following data-import
 * migration for what fills these in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('heijunka_box_schedules', function (Blueprint $table) {
            // The sheet's own row order within a cycle_issue group — parts
            // are displayed in this order, not alphabetically.
            $table->unsignedInteger('sort_order')->nullable()->after('cycle_issue');
        });

        Schema::create('heijunka_box_cycle_groups', function (Blueprint $table) {
            $table->id();
            $table->string('cycle_issue')->unique();
            $table->unsignedInteger('sort_order');
            // "H:i" time => the sheet's fixed random number for that slot in
            // this group, e.g. {"07:10": 1, "07:40": 9, ...} — a slot this
            // group has no number for (blank on the sheet) is simply absent.
            $table->json('random_numbers');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('heijunka_box_cycle_groups');

        Schema::table('heijunka_box_schedules', function (Blueprint $table) {
            $table->dropColumn('sort_order');
        });
    }
};
