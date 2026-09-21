<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * lt_per_kbn (Lead Time per Kanban, minutes) needs fractional values in
 * practice — e.g. 20.8 minutes — so it moves from a whole-number column to
 * decimal(8,2). Raw SQL (not Blueprint::change()) since this project doesn't
 * have doctrine/dbal installed, which Laravel's schema builder needs for
 * column-type changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE kesei_parts MODIFY lt_per_kbn DECIMAL(8,2) UNSIGNED NULL');
        DB::statement('ALTER TABLE lot_makings MODIFY lt_per_kbn DECIMAL(8,2) UNSIGNED NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE kesei_parts MODIFY lt_per_kbn INT UNSIGNED NULL');
        DB::statement('ALTER TABLE lot_makings MODIFY lt_per_kbn INT UNSIGNED NULL');
    }
};
