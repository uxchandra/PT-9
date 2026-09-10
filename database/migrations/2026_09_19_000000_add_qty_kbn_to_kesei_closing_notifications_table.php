<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kesei_closing_notifications', function (Blueprint $table) {
            // The accumulated kanban reported at that closing (also in the WA).
            $table->unsignedInteger('qty_kbn')->default(0)->after('notified_on');
        });
    }

    public function down(): void
    {
        Schema::table('kesei_closing_notifications', function (Blueprint $table) {
            $table->dropColumn('qty_kbn');
        });
    }
};
