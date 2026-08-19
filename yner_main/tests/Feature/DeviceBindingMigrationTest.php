<?php

namespace Tests\Feature;

use App\Enums\DeviceStatus;
use App\Models\User;
use App\Models\UserDevice;
use App\Notifications\SystemNotification;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The one-off collapse that made existing data fit the single-device rule.
 *
 * Nothing else can reach this code: a fresh `migrate` runs it against an empty table, so the
 * branch that actually matters — a user who already had several phones — would otherwise ship
 * untested and only run once, in production, on real people's ability to check in.
 *
 * The test rewinds the schema to its pre-migration shape, plants the awkward data, and replays
 * the migration.
 */
class DeviceBindingMigrationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Undo the binding columns so rows can be inserted the way they existed before.
     */
    private function rewindSchema(): void
    {
        Schema::table('user_devices', function (Blueprint $table) {
            $table->dropUnique(['active_user_id']);
            $table->dropUnique(['device_token_hash']);
            $table->dropColumn(['active_user_id', 'device_token_hash', 'device_token_issued_at']);
        });
    }

    private function replayMigration(): void
    {
        (require database_path('migrations/2026_08_19_000050_add_device_binding_to_user_devices_table.php'))->up();
    }

    private function legacyDevice(User $user, string $name, ?string $lastUsedAt): int
    {
        return DB::table('user_devices')->insertGetId([
            'user_id' => $user->id,
            'credential_id' => 'cred-'.fake()->unique()->numberBetween(1, 100_000),
            'public_key' => '{}',
            'sign_count' => 0,
            'rp_id' => 'eapms.test',
            'device_name' => $name,
            'status' => DeviceStatus::Active->value,
            'last_used_at' => $lastUsedAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_the_most_recently_used_device_survives_and_the_rest_are_revoked(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->rewindSchema();

        $stale = $this->legacyDevice($user, 'Old Phone', '2026-01-01 08:00:00');
        $current = $this->legacyDevice($user, 'Current Phone', '2026-08-01 08:00:00');
        $neverUsed = $this->legacyDevice($user, 'Spare Phone', null);

        $this->replayMigration();

        // "Most recently used", not "most recently registered": the phone someone actually
        // punches with is the one still in their hand. Spare Phone is newest by id and loses.
        $this->assertSame($current, UserDevice::boundTo($user->id)?->id);

        foreach ([$stale, $neverUsed] as $id) {
            $device = UserDevice::find($id);
            $this->assertSame(DeviceStatus::Revoked, $device->status);
            $this->assertNull($device->active_user_id);
            $this->assertSame('Superseded by the single-device policy.', $device->revoked_reason);
        }

        // Each retirement is evidence, and the owner is told — silently losing the ability to
        // check in tomorrow morning is the failure this notification exists to prevent.
        $this->assertSame(2, DB::table('audit_logs')->where('action', 'device.superseded')->count());
        Notification::assertSentToTimes($user, SystemNotification::class, 2);
    }

    public function test_users_are_collapsed_independently(): void
    {
        Notification::fake();

        $a = User::factory()->create();
        $b = User::factory()->create();

        $this->rewindSchema();

        $this->legacyDevice($a, 'A old', '2026-01-01 08:00:00');
        $aKeep = $this->legacyDevice($a, 'A current', '2026-08-01 08:00:00');
        $bOnly = $this->legacyDevice($b, 'B only', null);

        $this->replayMigration();

        $this->assertSame($aKeep, UserDevice::boundTo($a->id)?->id);

        // A user who only ever had one device must come through untouched — no revocation, no
        // notification, nothing for them to act on.
        $this->assertSame($bOnly, UserDevice::boundTo($b->id)?->id);
        $this->assertSame(DeviceStatus::Active, UserDevice::find($bOnly)->status);
        Notification::assertNotSentTo($b, SystemNotification::class);
    }

    public function test_the_unique_index_is_in_force_after_the_collapse(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->rewindSchema();
        $this->legacyDevice($user, 'One', '2026-08-01 08:00:00');
        $this->legacyDevice($user, 'Two', '2026-08-02 08:00:00');

        // The collapse has to succeed BEFORE the index is added, or the migration itself would
        // fail on exactly the data it exists to clean up.
        $this->replayMigration();

        $this->assertSame(1, UserDevice::whereNotNull('active_user_id')->count());

        $this->expectException(QueryException::class);

        UserDevice::create([
            'user_id' => $user->id,
            'credential_id' => 'cred-extra',
            'public_key' => '{}',
            'rp_id' => 'eapms.test',
            'device_name' => 'Three',
        ]);
    }
}
