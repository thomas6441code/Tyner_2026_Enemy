<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The only supported way to move an account's binding onto a different phone.
 *
 * Employees cannot revoke their own device: self-service unlinking would reduce the whole
 * one-account-one-device rule to a two-click formality, since anyone wanting to share an
 * account could simply unlink and re-link on demand. Instead they ask, an Admin or HR Officer
 * decides, and the decision is recorded here permanently.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_reset_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Denormalised for the review screen, which lists people by employee, and kept
            // nullable because the employee link can be severed after the fact.
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();

            // The device being replaced, recorded at request time. nullOnDelete rather than
            // cascade: losing the device row must not erase the request that replaced it.
            $table->foreignId('user_device_id')->nullable()->constrained()->nullOnDelete();

            $table->text('reason');
            $table->string('status', 20)->default('pending');

            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();

            // Approval opens a window, it does not grant a permanent right. Registration is
            // only permitted while now() < approved_until AND used_at is still null, so one
            // approval buys exactly one device.
            $table->timestamp('approved_until')->nullable();
            $table->timestamp('used_at')->nullable();

            // Audit context only. Never read to make a decision — see config/device.php on why
            // IP addresses are useless as identity here.
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_reset_requests');
    }
};
