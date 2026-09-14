<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a Lot Making part carry its own loading_time/dandori — Lot Making
 * Planning reads them from here when assigning a part that isn't registered
 * in Kelompok Pattern (see LotMakingPlanningController::assign), so staff
 * never has to retype them at assignment time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lot_makings', function (Blueprint $table) {
            $table->unsignedInteger('loading_time')->nullable()->after('slot');
            $table->unsignedInteger('dandori')->nullable()->after('loading_time');
        });
    }

    public function down(): void
    {
        Schema::table('lot_makings', function (Blueprint $table) {
            $table->dropColumn(['loading_time', 'dandori']);
        });
    }
};
