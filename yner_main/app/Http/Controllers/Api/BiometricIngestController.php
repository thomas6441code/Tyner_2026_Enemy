<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RawAttendanceLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BiometricIngestController extends Controller
{
    /**
     * Ingest a batch of normalized punch logs from bio-service.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'logs' => ['required', 'array'],
            'logs.*.device_user_id' => ['required', 'string'],
            'logs.*.punched_at' => ['required', 'date'],
            'logs.*.device_serial' => ['required', 'string'],
        ]);

        $rows = collect($validated['logs'])->map(fn (array $log) => [
            'device_user_id' => $log['device_user_id'],
            'punched_at' => $log['punched_at'],
            'device_serial' => $log['device_serial'],
            'raw_payload' => json_encode($log),
            'created_at' => now(),
            'updated_at' => now(),
        ])->all();

        $before = RawAttendanceLog::count();
        RawAttendanceLog::insertOrIgnore($rows);
        $stored = RawAttendanceLog::count() - $before;

        return response()->json([
            'status' => 'ok',
            'stored' => $stored,
            'duplicates' => count($rows) - $stored,
        ]);
    }
}
