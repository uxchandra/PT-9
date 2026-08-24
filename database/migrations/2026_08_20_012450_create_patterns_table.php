<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patterns', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('machine_id')->constrained()->cascadeOnDelete();
            $table->foreignId('part_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('loading_time')->comment('minutes');
            $table->unsignedInteger('jumlah_proses');
            $table->unsignedInteger('proses')->comment('current process number out of jumlah_proses');
            $table->unsignedInteger('total_kanban');
            $table->unsignedInteger('dandori')->comment('minutes');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patterns');
    }
};
