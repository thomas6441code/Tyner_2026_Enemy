<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Link a computed attendance record to the approved permission that backs its leave
     * status. The Phase 6 sync engine sets this; the calculator reads it to avoid clobbering
     * a synced leave day back to Absent on the nightly recompute.
     */
    public function up(): void
    {
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->foreignId('permission_request_id')
                ->nullable()
                ->after('is_manual')
                ->constrained()
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->dropConstrainedForeignId('permission_request_id');
        });
    }
};
