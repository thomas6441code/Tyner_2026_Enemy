<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SystemNotification extends Notification
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $meta
     */
    private function __construct(
        private readonly string $type,
        private readonly string $title,
        private readonly string $message,
        private readonly ?string $actionUrl = null,
        private readonly ?string $actionLabel = null,
        private readonly array $meta = [],
        private readonly ?string $mailSubject = null,
    ) {}

    public static function permissionRequestSubmitted(string $employeeName, int $requestId, string $actionUrl): self
    {
        return new self(
            type: 'permission-request.submitted',
            title: 'New permission request',
            message: "{$employeeName} submitted permission request #{$requestId}.",
            actionUrl: $actionUrl,
            actionLabel: 'Review request',
            meta: [
                'employee_name' => $employeeName,
                'request_id' => $requestId,
            ],
            mailSubject: 'New permission request submitted',
        );
    }

    public static function permissionRequestReviewed(string $employeeName, string $decision, int $requestId, string $actionUrl): self
    {
        $title = $decision === 'approved' ? 'Permission request approved' : 'Permission request rejected';

        return new self(
            type: "permission-request.{$decision}",
            title: $title,
            message: "Your permission request #{$requestId} was {$decision}.",
            actionUrl: $actionUrl,
            actionLabel: 'View request',
            meta: [
                'employee_name' => $employeeName,
                'request_id' => $requestId,
                'decision' => $decision,
            ],
            mailSubject: $title,
        );
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public static function attendanceAlert(string $title, string $message, string $actionUrl, array $meta = []): self
    {
        return new self(
            type: 'attendance.alert',
            title: $title,
            message: $message,
            actionUrl: $actionUrl,
            actionLabel: 'Open AI insights',
            meta: $meta,
            mailSubject: $title,
        );
    }

    public static function reportSummaryReady(string $scopeLabel, string $periodLabel, string $actionUrl): self
    {
        return new self(
            type: 'report-summary.ready',
            title: 'Monthly report summary ready',
            message: "{$periodLabel} summary for {$scopeLabel} is ready.",
            actionUrl: $actionUrl,
            actionLabel: 'Open summaries',
            meta: [
                'scope' => $scopeLabel,
                'period' => $periodLabel,
            ],
            mailSubject: 'Monthly report summary ready',
        );
    }

    public static function signInReminder(string $employeeName, string $dateLabel, string $actionUrl): self
    {
        return new self(
            type: 'attendance.sign-in-reminder',
            title: 'Sign-in reminder',
            message: "{$employeeName}, you have no attendance record for {$dateLabel} yet.",
            actionUrl: $actionUrl,
            actionLabel: 'Open attendance',
            meta: [
                'employee_name' => $employeeName,
                'date' => $dateLabel,
            ],
            mailSubject: 'Please sign in for today',
        );
    }

    public static function registrationRequestSubmitted(string $applicantName, int $requestId, string $actionUrl): self
    {
        return new self(
            type: 'registration-request.submitted',
            title: 'New registration request',
            message: "{$applicantName} submitted registration request #{$requestId}.",
            actionUrl: $actionUrl,
            actionLabel: 'Review request',
            meta: [
                'applicant_name' => $applicantName,
                'request_id' => $requestId,
            ],
            mailSubject: 'New registration request submitted',
        );
    }

    public static function registrationRequestApproved(string $applicantName, string $activationUrl): self
    {
        return new self(
            type: 'registration-request.approved',
            title: 'Your registration was approved',
            message: "{$applicantName}, your EAPMS registration has been approved. Use the link below to create your account — it can only be used once.",
            actionUrl: $activationUrl,
            actionLabel: 'Create EAPMS account',
            meta: [
                'applicant_name' => $applicantName,
            ],
            mailSubject: 'Your EAPMS registration was approved',
        );
    }

    public static function registrationRequestRejected(string $applicantName, ?string $reason): self
    {
        return new self(
            type: 'registration-request.rejected',
            title: 'Your registration was not approved',
            message: trim("{$applicantName}, your EAPMS registration request was not approved. ".($reason ?? '')),
            meta: [
                'applicant_name' => $applicantName,
                'reason' => $reason,
            ],
            mailSubject: 'Your EAPMS registration request',
        );
    }

    public static function accountActivated(string $employeeName, string $actionUrl): self
    {
        return new self(
            type: 'account.activated',
            title: 'Account activated',
            message: "{$employeeName} completed account activation and can now sign in.",
            actionUrl: $actionUrl,
            actionLabel: 'View employees',
            meta: [
                'employee_name' => $employeeName,
            ],
            mailSubject: 'EAPMS account activated',
        );
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        // Applicant notifications are sent on-demand (Notification::route('mail', ...))
        // because no User row exists until activation. An AnonymousNotifiable has nowhere
        // to store a database notification, so mail is the only viable channel.
        if ($notifiable instanceof AnonymousNotifiable) {
            return ['mail'];
        }

        return ['database', 'mail'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return $this->payload();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return $this->payload();
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->mailSubject ?? $this->title)
            ->line($this->message);

        if ($this->actionUrl !== null) {
            $mail->action($this->actionLabel ?? 'Open', $this->actionUrl);
        }

        return $mail;
    }

    public function databaseType(object $notifiable): string
    {
        return $this->type;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return array_filter([
            'type' => $this->type,
            'title' => $this->title,
            'message' => $this->message,
            'action_url' => $this->actionUrl,
            'action_label' => $this->actionLabel,
            'meta' => $this->meta,
        ], fn (mixed $value) => $value !== null);
    }
}
