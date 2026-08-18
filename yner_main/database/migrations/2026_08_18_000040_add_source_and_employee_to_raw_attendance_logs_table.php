<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Open `raw_attendance_logs` to a second attendance channel.
 *
 * Both channels — biometric terminals and WebAuthn mobile check-in — converge on this one
 * table, so AttendanceCalculator, the permission-sync engine, the reports and the AI pipeline
 * all keep working with no knowledge that a second source exists.
 *
 * Deliberately additive only. Existing rows take the column defaults (`employee_id` null,
 * `source` 'device'), which is exactly what they are, so there is no data migration and this
 * is safe to run against production.
 *
 * NOTE what is NOT changed: `device_serial` and `device_user_id` stay NOT NULL and keep the
 * `raw_attendance_logs_dedupe_unique` index. Making them nullable for mobile rows would look
 * tidier and would silently disable deduplication for every one of those rows, because MySQL
 * treats NULLs as distinct inside a unique index. Mobile rows write sentinel values instead —
 * see RawAttendanceLog::MOBILE_SERIAL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('raw_attendance_logs', function (Blueprint $table) {
            // Set only by the mobile channel, where the employee is known at write time from
            // the authenticated session. Device rows leave it null and keep resolving through
            // device_enrollments as they always have.
            $table->foreignId('employee_id')->nullable()->after('device_serial')
                ->constrained()->nullOnDelete();

            $table->string('source', 20)->default('device')->after('employee_id')->index();

            $table->index(['employee_id', 'punched_at']);
        });
    }

    public function down(): void
    {
        Schema::table('raw_attendance_logs', function (Blueprint $table) {
            $table->dropIndex(['employee_id', 'punched_at']);
            $table->dropIndex(['source']);
            $table->dropConstrainedForeignId('employee_id');
            $table->dropColumn('source');
        });
    }
};
