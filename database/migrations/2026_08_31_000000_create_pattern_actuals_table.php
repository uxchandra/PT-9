<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The actual kanban really produced for one machine assignment
     * (Pattern), entered manually on the Andon Planning board — one row per
     * pattern per calendar day (the board's own 07:00-06:00(+1) day, keyed
     * by its start date).
     */
    public function up(): void
    {
        Schema::create('pattern_actuals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pattern_id')->constrained()->cascadeOnDelete();
            $table->date('produced_on');
            $table->unsignedInteger('actual_kanban')->nullable();
            $table->timestamps();

            $table->unique(['pattern_id', 'produced_on']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pattern_actuals');
    }
};
