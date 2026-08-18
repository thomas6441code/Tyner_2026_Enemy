<?php

namespace App\Models;

use App\Enums\RegistrationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class RegistrationRequest extends Model
{
    /**
     * Only applicant-supplied fields are mass assignable.
     *
     * `email`, `status`, `employee_id`, `reviewed_by`, `reviewed_at` and `ip_address` are
     * server-controlled and are assigned individually — the public store() path accepts an
     * anonymous payload, so anything here is reachable by an untrusted request body.
     */
    protected $fillable = [
        'first_name',
        'last_name',
        'phone',
        'department_id',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'status' => RegistrationStatus::class,
            'reviewed_at' => 'datetime',
        ];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function invitation(): HasOne
    {
        return $this->hasOne(AccountInvitation::class);
    }

    public function isPending(): bool
    {
        return $this->status === RegistrationStatus::Pending;
    }

    public function fullName(): string
    {
        return "{$this->first_name} {$this->last_name}";
    }
}
