<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('raw_attendance_logs', function (Blueprint $table) {
            $table->id();
            $table->string('device_user_id');
            $table->dateTime('punched_at');
            $table->string('device_serial');
            $table->json('raw_payload')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['device_serial', 'device_user_id', 'punched_at'],
                'raw_attendance_logs_dedupe_unique'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('raw_attendance_logs');
    }
};
