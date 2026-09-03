<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_entries', function (Blueprint $table) {
            $table->id();

            // One row per calendar day: which pattern board runs that day.
            $table->date('date')->unique();
            $table->foreignId('pattern_board_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_entries');
    }
};
