<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kesei_parts', function (Blueprint $table) {
            // The raw material (RM) this part is built from — not one of the
            // existing Part records, just a free-form part_no + level pair
            // shown together as one "Material" column. Its own stock is
            // looked up by part_no from StockSnapshot (see
            // StockSnapshot::monitoredPartNos()) for the Andon Kesei Closing
            // Time panel's Ready/Empty status.
            $table->string('material_part_no')->nullable()->after('lt_per_kbn');
            $table->string('material_level')->nullable()->after('material_part_no');
        });

        Schema::table('lot_makings', function (Blueprint $table) {
            $table->string('material_part_no')->nullable()->after('level');
            $table->string('material_level')->nullable()->after('material_part_no');
        });
    }

    public function down(): void
    {
        Schema::table('kesei_parts', function (Blueprint $table) {
            $table->dropColumn(['material_part_no', 'material_level']);
        });

        Schema::table('lot_makings', function (Blueprint $table) {
            $table->dropColumn(['material_part_no', 'material_level']);
        });
    }
};
