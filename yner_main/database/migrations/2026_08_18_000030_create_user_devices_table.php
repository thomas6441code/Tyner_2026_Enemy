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
        Schema::create('user_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // 512 chars x 4 bytes (utf8mb4) = 2048 bytes, comfortably under InnoDB's 3072-byte
            // index limit. DO NOT widen this without moving the unique index onto a hash
            // column — a wider unique key simply fails to create on MySQL.
            $table->string('credential_id', 512)->unique();

            // The whole serialized CredentialRecord. The library round-trips it, and
            // reconstructing a credential from scattered scalar columns is exactly where
            // hand-rolled WebAuthn implementations break. The scalars below exist for
            // querying and admin display only.
            $table->text('public_key');

            $table->unsignedBigInteger('sign_count')->default(0);
            $table->string('aaguid')->nullable();
            $table->json('transports')->nullable();
            $table->string('attestation_type')->nullable();

            // Recorded per credential, not read from config at verify time: credentials are
            // bound to the RP ID they were created under, so a domain change must yield a
            // clean "re-register this device" rather than an unexplained signature failure.
            $table->string('rp_id');

            $table->string('device_name');
            $table->string('platform')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamp('last_used_at')->nullable();
            $table->string('last_used_ip', 45)->nullable();

            // Revocation is a soft state kept forever: a revoked authenticator is evidence
            // about who could have checked in and when, so it is never hard-deleted.
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_reason')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_devices');
    }
};
