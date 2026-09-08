<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_snapshots', function (Blueprint $table) {
            // Every Andon/Planning query filters by part_no AND a captured_at
            // range; the two single-column indexes force a scan + filter on a
            // multi-million-row table.
            $table->index(['part_no', 'captured_at'], 'stock_snapshots_part_no_captured_at_idx');
        });
    }

    public function down(): void
    {
        Schema::table('stock_snapshots', function (Blueprint $table) {
            $table->dropIndex('stock_snapshots_part_no_captured_at_idx');
        });
    }
};
