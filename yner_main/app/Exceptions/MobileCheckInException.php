<?php

namespace App\Exceptions;

use App\Enums\CheckInRejection;
use App\Models\MobileCheckIn;
use RuntimeException;

/**
 * A mobile check-in was refused by one of the gates in MobileCheckInService.
 *
 * Carries the machine-readable reason and, once the attempt has been persisted, the
 * `mobile_check_ins` row recording it. Every rejection is written to that table before this
 * is thrown — refusing a check-in without leaving a trace would defeat the point of a channel
 * whose only real control is its audit trail.
 */
class MobileCheckInException extends RuntimeException
{
    public function __construct(
        public readonly CheckInRejection $reason,
        public ?MobileCheckIn $attempt = null,
    ) {
        parent::__construct($reason->message());
    }
}
