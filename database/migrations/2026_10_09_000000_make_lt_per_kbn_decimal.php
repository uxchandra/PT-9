<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * lt_per_kbn (Lead Time per Kanban, minutes) needs fractional values in
 * practice — e.g. 20.8 minutes — so it moves from a whole-number column to
 * decimal(8,2). Raw SQL (not Blueprint::change()) since this project doesn't
 * have doctrine/dbal installed, which Laravel's schema builder needs for
 * column-type changes. MySQL-only syntax ("MODIFY") — sqlite (the test
 * suite's driver) has no equivalent and doesn't enforce column types
 * anyway, so it's a no-op there.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE kesei_parts MODIFY lt_per_kbn DECIMAL(8,2) UNSIGNED NULL');
        DB::statement('ALTER TABLE lot_makings MODIFY lt_per_kbn DECIMAL(8,2) UNSIGNED NULL');
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE kesei_parts MODIFY lt_per_kbn INT UNSIGNED NULL');
        DB::statement('ALTER TABLE lot_makings MODIFY lt_per_kbn INT UNSIGNED NULL');
    }
};
