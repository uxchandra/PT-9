<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kesei_parts', function (Blueprint $table) {
            $table->time('closing_time')->nullable()->after('stock_source');
            $table->foreignId('pattern_board_id')->nullable()->after('closing_time')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('kesei_parts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pattern_board_id');
            $table->dropColumn('closing_time');
        });
    }
};
