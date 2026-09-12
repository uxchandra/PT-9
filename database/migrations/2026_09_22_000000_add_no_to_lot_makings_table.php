<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lot_makings', function (Blueprint $table) {
            // Manually-set position number — drives the default listing order,
            // independent of row/kolom (rack layout can be rearranged later
            // without touching the physical row/kolom values).
            $table->unsignedInteger('no')->nullable()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('lot_makings', function (Blueprint $table) {
            $table->dropColumn('no');
        });
    }
};
