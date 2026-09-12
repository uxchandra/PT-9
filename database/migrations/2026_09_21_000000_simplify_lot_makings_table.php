<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lot Making is revised down to a much smaller sheet: row/kolom (rack
 * position), part, lot_produksi and slot. avg_slot (lot_produksi / slot) and
 * slot_fix (ROUNDUP of that) are NOT stored — they're pure functions of
 * lot_produksi/slot, computed on read via LotMaking's accessors, so they can
 * never drift out of sync with the two numbers they're derived from.
 */
return new class extends Migration
{
    public function up(): void
    {
        // The index on assy_part_code has to go before the column itself —
        // SQLite (the test database) refuses to drop a column an index still
        // references.
        Schema::table('lot_makings', function (Blueprint $table) {
            $table->dropIndex(['assy_part_code']);
        });

        Schema::table('lot_makings', function (Blueprint $table) {
            $table->dropColumn([
                'assy_part_code', 'qty_kanban', 'lot', 'loading_time', 'dandori',
                'safety_stock', 'total_kanban_edar', 'next_process', 'kapasitas_rak',
            ]);
        });

        Schema::table('lot_makings', function (Blueprint $table) {
            $table->string('row')->nullable()->after('part_id');
            $table->string('kolom')->nullable()->after('row');
            $table->unsignedInteger('slot')->nullable()->after('lot_produksi');
        });
    }

    public function down(): void
    {
        Schema::table('lot_makings', function (Blueprint $table) {
            $table->dropColumn(['row', 'kolom', 'slot']);
        });

        Schema::table('lot_makings', function (Blueprint $table) {
            // Best-effort: the old required assy_part_code has no source to
            // restore, so it comes back empty rather than blocking the rollback.
            $table->string('assy_part_code')->default('')->after('part_id');
            $table->unsignedInteger('qty_kanban')->nullable();
            $table->unsignedInteger('lot')->nullable();
            $table->unsignedInteger('loading_time')->nullable();
            $table->unsignedInteger('dandori')->nullable();
            $table->unsignedInteger('safety_stock')->nullable();
            $table->unsignedInteger('total_kanban_edar')->nullable();
            $table->string('next_process')->nullable();
            $table->unsignedInteger('kapasitas_rak')->nullable();
            $table->index('assy_part_code');
        });
    }
};
