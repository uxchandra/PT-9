<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lot_makings', function (Blueprint $table) {
            // Same "Perintah Pulling" concept as kesei_parts — see that
            // migration. Only has an effect when this part's level is
            // finish-goods (the only mode with a target at all); harmless
            // to set on a store-3 (free-mode) row.
            $table->unsignedInteger('pulling_command')->nullable()->after('level');
            $table->timestamp('pulling_command_set_at')->nullable()->after('pulling_command');
        });
    }

    public function down(): void
    {
        Schema::table('lot_makings', function (Blueprint $table) {
            $table->dropColumn(['pulling_command', 'pulling_command_set_at']);
        });
    }
};
