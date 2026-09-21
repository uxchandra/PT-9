<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kesei_parts', function (Blueprint $table) {
            // The largest number of orders this part can carry in one
            // production cycle — a capacity/quantity figure, separate from
            // the per-cycle times in `cycles`.
            $table->unsignedInteger('order_per_cycle')->nullable()->after('cycles');
        });

        Schema::table('lot_makings', function (Blueprint $table) {
            $table->unsignedInteger('order_per_cycle')->nullable()->after('cycles');
        });
    }

    public function down(): void
    {
        Schema::table('kesei_parts', function (Blueprint $table) {
            $table->dropColumn('order_per_cycle');
        });

        Schema::table('lot_makings', function (Blueprint $table) {
            $table->dropColumn('order_per_cycle');
        });
    }
};
