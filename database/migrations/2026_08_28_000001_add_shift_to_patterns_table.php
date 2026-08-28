<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A machine assignment now belongs to a shift (1 = 07:00-16:00, 2 =
     * 20:00-06:00), so the same machine+part can be scheduled separately in
     * each shift, each pointing at that shift's pattern_group_items row.
     */
    public function up(): void
    {
        Schema::table('patterns', function (Blueprint $table) {
            $table->unsignedTinyInteger('shift')->default(1)->after('part_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('patterns', function (Blueprint $table) {
            $table->dropColumn('shift');
        });
    }
};
