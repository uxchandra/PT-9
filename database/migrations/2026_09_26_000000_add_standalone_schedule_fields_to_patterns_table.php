<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A regular Assignment Mesin always has a matching Kelompok Pattern
 * (PatternGroupItem) row — that's where its loading_time/jumlah_proses/
 * dandori/total_kanban come from, and it's enforced by validation.
 *
 * Lot Making Planning assignments are different on purpose: a Lot Making
 * part floats between boards/machines day to day (today's board A, tomorrow
 * board C) rather than sitting on one fixed line, so forcing it into
 * Kelompok Pattern — meant for a stable, permanent schedule — doesn't fit.
 * These nullable columns let such a Pattern row carry its own scheduling
 * data instead, used by AndonScheduleBuilder only when no Kelompok Pattern
 * row matches (see buildShiftBlocks) — a normal Assignment Mesin row never
 * sets these and keeps working exactly as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patterns', function (Blueprint $table) {
            $table->unsignedInteger('loading_time')->nullable()->after('proses');
            $table->unsignedInteger('jumlah_proses')->nullable()->after('loading_time');
            $table->unsignedInteger('dandori')->nullable()->after('jumlah_proses');
            $table->unsignedInteger('total_kanban')->nullable()->after('dandori');
        });
    }

    public function down(): void
    {
        Schema::table('patterns', function (Blueprint $table) {
            $table->dropColumn(['loading_time', 'jumlah_proses', 'dandori', 'total_kanban']);
        });
    }
};
