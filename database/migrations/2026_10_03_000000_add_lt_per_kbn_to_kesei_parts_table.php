<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kesei_parts', function (Blueprint $table) {
            // Lead Time per Kanban (minutes) — how long a heijunka-paced
            // "release" queue takes to mature one kanban. Null/0 = no
            // pacing at all, mathematically identical to today's behaviour
            // (every kanban releases the instant it arrives) — see
            // KeseiBoard::heijunkaRelease() and KeseiPull::demandRow().
            $table->unsignedInteger('lt_per_kbn')->nullable()->after('pulling_command_set_at');
        });
    }

    public function down(): void
    {
        Schema::table('kesei_parts', function (Blueprint $table) {
            $table->dropColumn('lt_per_kbn');
        });
    }
};
