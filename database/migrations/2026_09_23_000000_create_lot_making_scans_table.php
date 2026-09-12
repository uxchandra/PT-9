<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lot_making_scans', function (Blueprint $table) {
            $table->id();
            $table->string('part_no');
            $table->string('raw');
            $table->foreignId('scanned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('scanned_at');
            $table->timestamps();

            $table->index(['part_no', 'scanned_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lot_making_scans');
    }
};
