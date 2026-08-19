<?php

namespace App\Rules;

use App\Support\MailDomainResolver;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Rejects email addresses no mail could ever reach.
 *
 * Registration is the one place where an unreachable address is fatal rather than
 * annoying: the applicant never receives a password, so approval mints an employee
 * record and a single-use invitation that can only expire unused. Catching it at
 * submission keeps that dead end out of the review queue entirely.
 *
 * Syntax is left to the `email` rule — this only adds the deliverability question, and
 * stays silent when the value is malformed so the two rules never double-report.
 */
class DeliverableEmail implements ValidationRule
{
    public function __construct(private readonly ?MailDomainResolver $resolver = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Off in testing and available as an escape hatch for air-gapped deployments,
        // where an unreachable resolver would otherwise reject every valid address.
        if (! config('auth.registration.verify_email_domain', true)) {
            return;
        }

        if (! is_string($value) || filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            return;
        }

        $resolver = $this->resolver ?? app(MailDomainResolver::class);

        if (! $resolver->acceptsMail($value)) {
            $fail('This email address is not available — no mail server answers for its domain. Use a working address such as your Gmail, Outlook, or organisation email.');
        }
    }
}
