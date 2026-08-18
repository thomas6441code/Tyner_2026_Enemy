<?php

namespace App\Console\Commands;

use App\Models\AccountInvitation;
use Illuminate\Console\Command;

/**
 * Retention sweep for activation links that were never redeemed.
 *
 * Only invitations that expired or were revoked **without ever being used** are removed. They
 * record nothing that happened: the approval that minted them is already in `audit_logs` and on
 * the `registration_requests` row, so deleting them loses no evidence while keeping the
 * Invitations screen readable and its stats honest.
 *
 * Used invitations are never pruned at any age — a used invitation is the proof that a specific
 * account was activated from a specific approval, which is exactly the trail the request →
 * approve → invite flow exists to create.
 */
class PruneInvitations extends Command
{
    protected $signature = 'invitations:prune
                            {--days=90 : Delete unredeemed invitations dead for at least this many days}
                            {--dry-run : Report what would be deleted without deleting it}';

    protected $description = 'Delete expired or revoked account invitations that were never used';

    public function handle(): int
    {
        $days = (int) $this->option('days');

        if ($days < 1) {
            $this->error('--days must be at least 1.');

            return self::FAILURE;
        }

        $cutoff = now()->subDays($days);

        // "Dead" is measured from whichever moment ended the invitation's life — its expiry, or
        // the revocation if HR pulled it early — not from created_at, so a long-lived invitation
        // is not pruned the moment it lapses.
        $query = AccountInvitation::query()
            ->whereNull('used_at')
            ->where(function ($q) use ($cutoff) {
                $q->where(fn ($q) => $q->whereNull('revoked_at')->where('expires_at', '<', $cutoff))
                    ->orWhere('revoked_at', '<', $cutoff);
            });

        $count = (clone $query)->count();

        if ($count === 0) {
            $this->info("No unredeemed invitations dead for {$days} day(s) or more.");

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->info("Would delete {$count} unredeemed invitation(s) dead since before {$cutoff->toDateTimeString()}.");

            return self::SUCCESS;
        }

        $deleted = $query->delete();

        $this->info("Deleted {$deleted} unredeemed invitation(s) dead since before {$cutoff->toDateTimeString()}.");

        return self::SUCCESS;
    }
}
