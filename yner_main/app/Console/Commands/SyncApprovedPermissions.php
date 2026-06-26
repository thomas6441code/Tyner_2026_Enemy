<?php

namespace App\Console\Commands;

use App\Enums\PermissionStatus;
use App\Models\PermissionRequest;
use App\Services\AttendanceSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class SyncApprovedPermissions extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'attendance:sync-permissions
                            {--from= : Only sync requests overlapping on/after this date (Y-m-d)}
                            {--to= : Only sync requests overlapping on/before this date (Y-m-d)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Replay attendance sync over all approved permission requests (idempotent backfill)';

    /**
     * Execute the console command.
     */
    public function handle(AttendanceSyncService $sync): int
    {
        $query = PermissionRequest::where('status', PermissionStatus::Approved);

        if ($from = $this->option('from')) {
            $query->whereDate('end_date', '>=', Carbon::parse($from)->toDateString());
        }

        if ($to = $this->option('to')) {
            $query->whereDate('start_date', '<=', Carbon::parse($to)->toDateString());
        }

        $totals = ['created' => 0, 'updated' => 0, 'skipped' => 0];
        $requests = 0;

        $query->with('employee')->lazy()->each(function (PermissionRequest $request) use ($sync, &$totals, &$requests) {
            $result = $sync->syncForApproval($request);
            foreach ($totals as $key => $value) {
                $totals[$key] += $result[$key];
            }
            $requests++;
        });

        $this->info("Synced {$requests} approved request(s): {$totals['created']} created, {$totals['updated']} updated, {$totals['skipped']} skipped.");

        return self::SUCCESS;
    }
}
