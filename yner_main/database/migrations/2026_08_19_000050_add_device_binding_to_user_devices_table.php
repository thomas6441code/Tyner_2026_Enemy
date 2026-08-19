<?php

use App\Enums\DeviceStatus;
use App\Models\User;
use App\Models\UserDevice;
use App\Notifications\SystemNotification;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Enforce "one account ⇄ one device" in the database.
 *
 * The two unique indexes added here are the layer that cannot be talked out of it. The
 * authenticator can be lied to and a cookie can be cleared; a unique index cannot.
 */
return new class extends Migration
{
    private const SUPERSEDED_REASON = 'Superseded by the single-device policy.';

    public function up(): void
    {
        // Columns first, indexes last: the data below has to deduplicate existing rows before a
        // unique index over active_user_id could possibly be created.
        Schema::table('user_devices', function (Blueprint $table) {
            // Mirrors user_id while the device is active and is NULL once it is revoked. Both
            // MySQL and SQLite exempt NULLs from unique indexes, so a single unique column
            // expresses "at most one ACTIVE device per user" portably — no partial index, no
            // trigger, no application-level race.
            //
            // Deliberately NOT a foreign key: user_id beside it already carries the real
            // constraint (and the cascade delete), and adding an FK to an existing table is a
            // full table rebuild on SQLite, which the test suite runs on.
            $table->unsignedBigInteger('active_user_id')->nullable()->after('user_id');

            // SHA-256 of the device binding token, never the token itself. Unique, so one
            // token can only ever resolve to one device row.
            $table->string('device_token_hash', 64)->nullable()->after('platform');
            $table->timestamp('device_token_issued_at')->nullable()->after('device_token_hash');
        });

        $this->collapseToOneDevicePerUser();

        Schema::table('user_devices', function (Blueprint $table) {
            $table->unique('active_user_id');
            $table->unique('device_token_hash');
        });
    }

    public function down(): void
    {
        Schema::table('user_devices', function (Blueprint $table) {
            $table->dropUnique(['active_user_id']);
            $table->dropUnique(['device_token_hash']);
            $table->dropColumn(['active_user_id', 'device_token_hash', 'device_token_issued_at']);
        });

        // Revocations performed above are NOT reversed. They are audit evidence, and the
        // audit_logs rows explaining them stay too — an undo that erases the record of what
        // happened is worse than no undo at all.
    }

    /**
     * Keep each user's most recently used active device and revoke the rest.
     *
     * "Most recently used" rather than "most recently registered": the phone someone actually
     * checks in with is the one they still have in their hand. Never-used devices (NULL
     * last_used_at) sort last, and ties break on the newer row.
     */
    private function collapseToOneDevicePerUser(): void
    {
        $grouped = DB::table('user_devices')
            ->where('status', DeviceStatus::Active->value)
            ->get(['id', 'user_id', 'device_name', 'last_used_at'])
            ->groupBy('user_id');

        $now = now();

        foreach ($grouped as $userId => $devices) {
            $ordered = $devices
                ->sortByDesc(fn ($device) => [$device->last_used_at ?? '', $device->id])
                ->values();

            $keep = $ordered->shift();

            DB::table('user_devices')->where('id', $keep->id)->update(['active_user_id' => $userId]);

            if ($ordered->isEmpty()) {
                continue;
            }

            // Tell the owner which phones were retired. Silently losing the ability to check in
            // tomorrow morning is the failure mode this notification exists to prevent.
            $owner = User::find($userId);

            foreach ($ordered as $device) {
                DB::table('user_devices')->where('id', $device->id)->update([
                    'status' => DeviceStatus::Revoked->value,
                    'active_user_id' => null,
                    'revoked_at' => $now,
                    'revoked_reason' => self::SUPERSEDED_REASON,
                    'updated_at' => $now,
                ]);

                DB::table('audit_logs')->insert([
                    'user_id' => $userId,
                    'auditable_type' => UserDevice::class,
                    'auditable_id' => $device->id,
                    'action' => 'device.superseded',
                    'old_values' => json_encode(['status' => DeviceStatus::Active->value]),
                    'new_values' => json_encode(['status' => DeviceStatus::Revoked->value]),
                    'note' => self::SUPERSEDED_REASON,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $owner?->notify(SystemNotification::deviceSuperseded(
                    $device->device_name,
                    url('/devices'),
                ));
            }
        }
    }
};
