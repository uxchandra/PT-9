<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lot_makings', function (Blueprint $table) {
            // One of KeseiPull::LOCATIONS' slugs ('finish-goods' / 'store-3')
            // — which scanner card this part's pulling command shows up
            // under. Its own attribute, independent of whether the part also
            // has a KeseiPart row, so a Lot Making part never needs a Kesei
            // entry just to be scannable.
            $table->string('level')->nullable()->after('part_id');
        });
    }

    public function down(): void
    {
        Schema::table('lot_makings', function (Blueprint $table) {
            $table->dropColumn('level');
        });
    }
};
