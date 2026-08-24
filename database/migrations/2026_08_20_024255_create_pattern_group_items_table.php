<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pattern_group_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pattern_board_id')->constrained()->cascadeOnDelete();
            $table->foreignId('part_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('urutan')->comment('display order within the board, top to bottom');
            $table->unsignedInteger('lot')->nullable();
            $table->decimal('cover_stock_days', 5, 2)->nullable();
            $table->unsignedInteger('loading_time')->comment('minutes, per single process');
            $table->unsignedInteger('jumlah_proses')->comment('total instances required for this part in this board');
            $table->timestamps();

            $table->unique(['pattern_board_id', 'part_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pattern_group_items');
    }
};
