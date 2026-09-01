<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('pattern_actuals', function (Blueprint $table) {
            $table->unsignedInteger('kanban_override')->nullable()->after('actual_kanban');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pattern_actuals', function (Blueprint $table) {
            $table->dropColumn('kanban_override');
        });
    }
};
