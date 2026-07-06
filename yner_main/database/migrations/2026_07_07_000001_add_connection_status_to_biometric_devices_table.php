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
        Schema::table('biometric_devices', function (Blueprint $table) {
            $table->timestamp('last_checked_at')->nullable()->after('status');
            $table->enum('last_status', ['connected', 'error'])->nullable()->after('last_checked_at');
            $table->text('last_status_message')->nullable()->after('last_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('biometric_devices', function (Blueprint $table) {
            $table->dropColumn(['last_checked_at', 'last_status', 'last_status_message']);
        });
    }
};
