<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * High-water marks for server-allocated sequences.
 *
 * WHY THIS EXISTS. Employee codes are derived from the highest code currently in use, which is
 * drift-proof — the data cannot disagree with itself. But it is not monotonic: delete the newest
 * employee and the next hire inherits their code, and with it their historical raw punches
 * (which are matched on `device_user_id = employee_code`), their permission requests, and their
 * name on three months of exported reports. A code must never be reissued.
 *
 * So the sequence is `max(stored mark, highest in use) + 1`. The stored mark makes it monotonic
 * across deletions; reading the live maximum makes it self-correcting when a row is inserted
 * behind the counter's back — by a seeder, an import, or a migration. Either half alone is
 * wrong in a way that only shows up months later.
 *
 * Generic by name rather than a dedicated employee-code table: the next sequence that needs the
 * same treatment should not need another migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sequence_counters', function (Blueprint $table) {
            // e.g. "employee_code:EMP-". The scope is part of the name, so changing a prefix
            // starts a fresh sequence instead of inheriting an unrelated counter.
            $table->string('name', 128)->primary();
            $table->unsignedBigInteger('value')->default(0);
            $table->timestamps();
        });

        $this->backfillEmployeeCodes();
    }

    public function down(): void
    {
        Schema::dropIfExists('sequence_counters');
    }

    /**
     * Seed the mark from codes already issued, so the first allocation after this migration
     * does not hand out a number that is currently in use.
     */
    private function backfillEmployeeCodes(): void
    {
        $prefix = (string) config('employee.code_prefix', 'EMP-');

        $highest = DB::table('employees')
            ->where('employee_code', 'like', str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $prefix).'%')
            ->pluck('employee_code')
            ->map(function (string $code) use ($prefix) {
                $tail = substr($code, strlen($prefix));

                return preg_match('/^\d+$/', $tail) === 1 ? (int) $tail : 0;
            })
            ->max();

        if (! $highest) {
            return;
        }

        DB::table('sequence_counters')->insert([
            'name' => 'employee_code:'.$prefix,
            'value' => $highest,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};
