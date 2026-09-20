<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kesei_parts', function (Blueprint $table) {
            // Which of the 10 fixed production cycles (C1..C10) this part
            // belongs to — stored as a JSON array of ints 1-10, e.g. [1,3,5].
            $table->json('cycles')->nullable()->after('material_level');
        });

        Schema::table('lot_makings', function (Blueprint $table) {
            $table->json('cycles')->nullable()->after('material_level');
        });
    }

    public function down(): void
    {
        Schema::table('kesei_parts', function (Blueprint $table) {
            $table->dropColumn('cycles');
        });

        Schema::table('lot_makings', function (Blueprint $table) {
            $table->dropColumn('cycles');
        });
    }
};
