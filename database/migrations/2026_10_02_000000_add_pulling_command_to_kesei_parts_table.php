<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kesei_parts', function (Blueprint $table) {
            // "Perintah Pulling" — the current pulling target for this row's
            // Finish Goods card, directly editable any time (not a one-off
            // setup value). Whatever's typed here becomes the new baseline
            // KeseiPull::demandRow() adds on top of stock decreases seen
            // since it was set, so it carries on being tracked automatically
            // from there — until the next closing folds it away like any
            // other pile. Has no effect on a Store 3 (free-mode) row, which
            // has no target at all.
            $table->unsignedInteger('pulling_command')->nullable()->after('level');
            $table->timestamp('pulling_command_set_at')->nullable()->after('pulling_command');
        });
    }

    public function down(): void
    {
        Schema::table('kesei_parts', function (Blueprint $table) {
            $table->dropColumn(['pulling_command', 'pulling_command_set_at']);
        });
    }
};
