<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kesei_parts', function (Blueprint $table) {
            // How the closing_time relates to the 07:00 production run:
            //  - 'pre_run'    : a planning cutoff in the 24h BEFORE the run
            //                   (the accumulated demand becomes that run's plan)
            //  - 'end_of_day' : the close of the run's own production day
            $table->string('closing_mode')->default('pre_run')->after('closing_time');
        });
    }

    public function down(): void
    {
        Schema::table('kesei_parts', function (Blueprint $table) {
            $table->dropColumn('closing_mode');
        });
    }
};
