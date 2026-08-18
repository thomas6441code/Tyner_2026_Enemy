<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The audit trail for the mobile check-in channel — every attempt, accepted or rejected.
 *
 * Why a table rather than stuffing this into `raw_attendance_logs.raw_payload` as JSON: the
 * admin log has to sort and filter by distance, employee, direction and result. MySQL 8 can
 * only index JSON through generated columns and SQLite's JSON semantics differ from MySQL's,
 * so the JSON route means two query implementations and two sets of bugs. One migration buys
 * indexable, portable, testable queries instead.
 *
 * Rejected attempts live here too, and that is the point: geolocation is spoofable and the
 * geofence is a speed bump, so the record of who tried what, from where, on which device is
 * the control that actually matters.
 *
 * There is deliberately NO unique index on (employee_id, work_date, direction). It would have
 * to apply only to accepted rows, and MySQL has no partial indexes. The `lockForUpdate` check
 * inside MobileCheckInService::record() gives the same guarantee, and keeping every rejected
 * attempt alongside the accepted ones is far more useful for audit than a constraint would be.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mobile_check_ins', function (Blueprint $table) {
            $table->id();

            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // Null on rejected attempts — no punch was written. Null too if the device or
            // location row is later deleted; the snapshot columns below survive that.
            $table->foreignId('raw_attendance_log_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_device_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('work_location_id')->nullable()->constrained()->nullOnDelete();

            $table->date('work_date');
            $table->string('direction', 10);
            $table->dateTime('punched_at');

            // Exact decimals, not floats: a boundary comparison against a drifting float is a
            // support ticket nobody can reproduce.
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedInteger('accuracy_meters')->nullable();

            // SERVER-COMPUTED ONLY. Any distance the client sends is display-only and is never
            // persisted here — see GeofenceService's threat model.
            $table->unsignedInteger('distance_meters')->nullable();
            $table->boolean('within_geofence')->default(false);
            $table->boolean('webauthn_verified')->default(false);

            $table->string('result', 20);
            $table->string('rejection_reason', 40)->nullable();

            // Accepted, but something looked wrong — impossible travel, chiefly. Flagged rather
            // than rejected so a real employee with a bad GPS fix is not locked out of their day.
            $table->boolean('flagged')->default(false);
            $table->string('flag_reason')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();

            $table->timestamps();

            $table->index(['employee_id', 'work_date', 'direction', 'result']);
            $table->index(['work_date', 'result']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_check_ins');
    }
};
