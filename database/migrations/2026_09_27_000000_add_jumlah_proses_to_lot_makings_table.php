<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The total number of process steps a Lot Making part goes through — the
 * denominator in the "2/3" label shown on its Andon block. Read live by
 * AndonScheduleBuilder alongside loading_time/dandori (see that migration)
 * whenever the part isn't registered in Kelompok Pattern.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lot_makings', function (Blueprint $table) {
            $table->unsignedInteger('jumlah_proses')->nullable()->after('dandori');
        });
    }

    public function down(): void
    {
        Schema::table('lot_makings', function (Blueprint $table) {
            $table->dropColumn('jumlah_proses');
        });
    }
};
