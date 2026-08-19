<?php

namespace App\Services;

use App\Models\Employee;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Allocates employee codes: sequential, gapless in practice, never reused, never typed.
 *
 * WHY THE SERVER OWNS THIS. The code is not a label, it is a key — device enrolments map a
 * biometric terminal's user ID onto it, exported reports are read by people who cannot see the
 * database, and the column is unique. A human typing it produces exactly two failure modes,
 * both expensive: a typo that silently detaches an employee from their punches, and a duplicate
 * that is rejected at the very end of a long form. Neither is recoverable by the person filling
 * the form in.
 *
 * HOW UNIQUENESS IS ACTUALLY GUARANTEED. Not by this class. `employees.employee_code` carries a
 * unique index, and that is the guarantee; everything here is about producing a *sensible*
 * candidate. Two requests arriving together will read the same maximum and propose the same
 * code — the index refuses the loser, and `create()` retries with the value that is now free.
 * A read-then-write sequence with no retry would be a race; a retry with no index would be a
 * silent duplicate. Both halves are load-bearing.
 *
 * WHY THE SEQUENCE IS BOTH DERIVED AND STORED. Reading the live maximum cannot drift, because
 * it IS the data — a code inserted by a seeder or an import is accounted for the moment it
 * lands. But it is not monotonic: delete the newest employee and the next hire would inherit
 * their code, and with it their historical raw punches (matched on `device_user_id`), their
 * permission requests, and their name on months of exported reports.
 *
 * So the next code is `max(stored high-water mark, highest in use) + 1`. The mark makes the
 * sequence monotonic across deletions; the live read makes it self-correcting when something
 * writes behind the counter's back. Either half alone is wrong in a way that surfaces months
 * later, on data nobody is watching. Gaps are the intended outcome — a code is spent forever
 * once issued.
 */
class EmployeeCodeGenerator
{
    /**
     * Enough to clear any realistic burst of concurrent creations; a failure past this is not
     * contention, it is a bug, and it should surface as one rather than spin.
     */
    private const MAX_ATTEMPTS = 5;

    private const COUNTER = 'employee_code:';

    public function prefix(): string
    {
        return (string) config('employee.code_prefix', 'EMP-');
    }

    public function padding(): int
    {
        return max(1, (int) config('employee.code_padding', 4));
    }

    /**
     * The code the next employee would receive, for display.
     *
     * Explicitly NOT a reservation — nothing is written and nothing is held. The form shows it
     * so the person filling it in knows what they are creating; the value that actually lands
     * is allocated at insert time, and under concurrency the two can differ. Never persist
     * this, and never send it back as an input.
     */
    public function peek(): string
    {
        return $this->format($this->highest() + 1);
    }

    /**
     * Create an employee with a freshly allocated code, retrying if someone beat us to it.
     *
     * Any `employee_code` in `$attributes` is discarded: this is the path for user-facing
     * creation, where accepting a caller-supplied code is the whole thing being prevented.
     * Seeders and tests that need a pinned code call `Employee::create()` directly, where the
     * model's `creating` hook fills in a code only when one is absent.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): Employee
    {
        unset($attributes['employee_code']);

        for ($attempt = 1; ; $attempt++) {
            try {
                return Employee::create($attributes + ['employee_code' => $this->next()]);
            } catch (QueryException $e) {
                // 23000 is the integrity-constraint class: another request committed this code
                // between our read and our write. Re-reading the maximum is the fix, and it
                // converges — the winner's row is now visible to us.
                if (! $this->isDuplicate($e) || $attempt >= self::MAX_ATTEMPTS) {
                    throw $e;
                }
            }
        }
    }

    /**
     * The next code in sequence. Used by the model hook and by `create()` above.
     */
    public function next(): string
    {
        return $this->format($this->highest() + 1);
    }

    /**
     * The largest sequence number currently present in the table, or 0 if there is none.
     *
     * Codes are pulled and parsed in PHP rather than compared in SQL. A `MAX(CAST(SUBSTRING(...)))`
     * would be one query, but the string functions differ between MySQL and SQLite — and the
     * test suite runs on SQLite while production runs on MySQL, so a subtle difference in
     * numeric-cast behaviour would be a bug that only ever appears in production.
     */
    /**
     * Record that a code has been issued, so it is never handed out again.
     *
     * Called from the model's `created` hook, which means it covers every path — including
     * seeders and fixtures that pin their own codes, whose numbers must still advance the mark
     * or the next generated code would collide with them.
     */
    public function remember(string $code): void
    {
        $sequence = $this->sequenceOf($code);

        if ($sequence <= 0) {
            return;
        }

        // Read-and-write under a lock rather than a `GREATEST(...)` update: the SQL function
        // differs between MySQL and SQLite, and the test suite runs on one while production
        // runs on the other. The row is contended only by employee creation, so the lock is
        // cheap.
        DB::transaction(function () use ($sequence) {
            $current = DB::table('sequence_counters')
                ->where('name', $this->counterName())
                ->lockForUpdate()
                ->value('value');

            if ($current === null) {
                DB::table('sequence_counters')->insert([
                    'name' => $this->counterName(),
                    'value' => $sequence,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } elseif ($sequence > (int) $current) {
                DB::table('sequence_counters')
                    ->where('name', $this->counterName())
                    ->update(['value' => $sequence, 'updated_at' => now()]);
            }
        });
    }

    /**
     * The counter is scoped to the prefix, so changing the prefix starts a fresh sequence
     * rather than inheriting a number from an unrelated one.
     */
    private function counterName(): string
    {
        return self::COUNTER.$this->prefix();
    }

    /**
     * The highest sequence number ever issued under this prefix: the greater of what is stored
     * and what is actually present.
     */
    private function highest(): int
    {
        return max($this->storedMark(), $this->highestInUse());
    }

    private function storedMark(): int
    {
        return (int) DB::table('sequence_counters')->where('name', $this->counterName())->value('value');
    }

    private function highestInUse(): int
    {
        return (int) Employee::query()
            ->where('employee_code', 'like', $this->escapeLike($this->prefix()).'%')
            ->pluck('employee_code')
            ->map(fn (string $code) => $this->sequenceOf($code))
            ->max();
    }

    /**
     * The numeric tail of a code, or 0 when it does not carry one.
     *
     * A code such as `EMP-TEMP` matches the prefix but has no sequence; treating it as 0 lets
     * it coexist harmlessly instead of derailing the count.
     */
    private function sequenceOf(string $code): int
    {
        $tail = substr($code, strlen($this->prefix()));

        return preg_match('/^\d+$/', $tail) === 1 ? (int) $tail : 0;
    }

    private function format(int $sequence): string
    {
        return $this->prefix().str_pad((string) $sequence, $this->padding(), '0', STR_PAD_LEFT);
    }

    /**
     * A prefix containing `%` or `_` would otherwise match far more than intended.
     */
    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $value);
    }

    private function isDuplicate(QueryException $e): bool
    {
        return (string) $e->getCode() === '23000';
    }
}
