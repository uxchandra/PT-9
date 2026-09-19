<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lot_makings', function (Blueprint $table) {
            // Lead Time per Kanban (minutes) — same attribute as
            // kesei_parts.lt_per_kbn. Not wired into any pacing logic yet.
            $table->unsignedInteger('lt_per_kbn')->nullable()->after('material_level');
        });
    }

    public function down(): void
    {
        Schema::table('lot_makings', function (Blueprint $table) {
            $table->dropColumn('lt_per_kbn');
        });
    }
};
