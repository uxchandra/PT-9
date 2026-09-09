<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kesei_parts', function (Blueprint $table) {
            // Manually-entered level for this Kesei row (independent of Part List).
            $table->string('level')->nullable()->after('stock_source');
        });
    }

    public function down(): void
    {
        Schema::table('kesei_parts', function (Blueprint $table) {
            $table->dropColumn('level');
        });
    }
};
