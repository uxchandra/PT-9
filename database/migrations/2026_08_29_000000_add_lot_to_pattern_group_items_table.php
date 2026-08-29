<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('pattern_group_items', function (Blueprint $table) {
            $table->unsignedInteger('lot')->default(0)->after('urutan');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pattern_group_items', function (Blueprint $table) {
            $table->dropColumn('lot');
        });
    }
};
