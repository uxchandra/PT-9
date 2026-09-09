<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kesei_scans', function (Blueprint $table) {
            $table->id();
            $table->string('part_no');
            $table->string('location');          // scanner card slug: finish-goods / store-3
            $table->string('raw');               // full QR payload, for audit
            $table->foreignId('scanned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('scanned_at');
            $table->timestamps();

            // The scan Andon board reads ticks by part + time window.
            $table->index(['part_no', 'scanned_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kesei_scans');
    }
};
