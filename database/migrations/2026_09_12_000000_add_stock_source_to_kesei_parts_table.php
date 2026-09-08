<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kesei_parts', function (Blueprint $table) {
            // Comma-separated part_no list whose Stock Part All stock is summed
            // for this row's Timeline Stok. Empty = use this row's own part_no.
            $table->string('stock_source')->nullable()->after('part_id');
        });
    }

    public function down(): void
    {
        Schema::table('kesei_parts', function (Blueprint $table) {
            $table->dropColumn('stock_source');
        });
    }
};
