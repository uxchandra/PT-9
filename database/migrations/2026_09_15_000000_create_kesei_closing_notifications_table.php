<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kesei_closing_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kesei_part_id')->constrained()->cascadeOnDelete();
            $table->date('notified_on');
            $table->timestamp('created_at')->nullable();

            // One WhatsApp per part per day, so re-running the check never spams.
            $table->unique(['kesei_part_id', 'notified_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kesei_closing_notifications');
    }
};
