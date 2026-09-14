<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Assignment Machine" — master data for a Lot Making part, mirroring
 * Assignment Mesin on the Pattern page (Machine + Part + Proses), minus
 * board/shift since a Lot Making part floats between boards day to day (see
 * the patterns table migration) rather than sitting on one fixed line.
 *
 * One row per process step: a part with 3 process steps has up to 3 rows
 * here, one per proses number, each naming which machine that step runs on.
 * Lot Making Planning requires a matching row here before a part can be
 * assigned to a machine — same as Assignment Mesin requiring a matching
 * Kelompok Pattern row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lot_making_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('part_id')->constrained()->cascadeOnDelete();
            $table->foreignId('machine_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('proses');
            $table->timestamps();

            // One machine per process step per part.
            $table->unique(['part_id', 'proses']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lot_making_assignments');
    }
};
