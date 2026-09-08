<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lot_makings', function (Blueprint $table) {
            $table->id();
            $table->string('assy_part_code');
            $table->foreignId('part_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('qty_kanban')->nullable();
            $table->unsignedInteger('lot')->nullable();
            $table->unsignedInteger('loading_time')->nullable();
            $table->unsignedInteger('dandori')->nullable();
            $table->unsignedInteger('lot_produksi')->nullable();
            $table->unsignedInteger('safety_stock')->nullable();
            $table->unsignedInteger('total_kanban_edar')->nullable();
            $table->string('next_process')->nullable();
            $table->unsignedInteger('kapasitas_rak')->nullable();
            $table->timestamps();

            $table->index('assy_part_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lot_makings');
    }
};
