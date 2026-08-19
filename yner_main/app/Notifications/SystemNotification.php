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

    public static function deviceRegistered(string $deviceName, string $actionUrl): self
    {
        return new self(
            type: 'device.registered',
            title: 'New device registered',
            message: "\"{$deviceName}\" can now be used to check in from a phone. If this was not you, revoke it immediately.",
            actionUrl: $actionUrl,
            actionLabel: 'Manage devices',
            meta: [
                'device_name' => $deviceName,
            ],
            mailSubject: 'A new device was registered on your EAPMS account',
        );
    }

    public static function deviceRevoked(string $deviceName, string $actionUrl): self
    {
        return new self(
            type: 'device.revoked',
            title: 'Device revoked',
            message: "\"{$deviceName}\" can no longer be used to check in.",
            actionUrl: $actionUrl,
            actionLabel: 'Manage devices',
            meta: [
                'device_name' => $deviceName,
            ],
            mailSubject: 'A device was removed from your EAPMS account',
        );
    }

    /**
     * The single-device policy retired a device the user already had. Sent by the data
     * migration and whenever an approved reset revokes the outgoing phone.
     */
    public static function deviceSuperseded(string $deviceName, string $actionUrl): self
    {
        return new self(
            type: 'device.superseded',
            title: 'Device unlinked',
            message: "\"{$deviceName}\" is no longer linked to your account. Only one device may be linked at a time, so check in from your current phone — or request a device reset if you no longer have it.",
            actionUrl: $actionUrl,
            actionLabel: 'Open My Device',
            meta: [
                'device_name' => $deviceName,
            ],
            mailSubject: 'A device was unlinked from your EAPMS account',
        );
    }

    /**
     * Someone tried to register a phone that is already bound to a different account. This is
     * the account-sharing signal the whole binding exists to catch, so an Admin hears about
     * every one of them.
     */
    public static function deviceLinkConflict(string $attemptedByName, string $boundToName, string $actionUrl): self
    {
        return new self(
            type: 'device.link_conflict',
            title: 'Device already linked to another account',
            message: "{$attemptedByName} tried to register a phone that is already linked to {$boundToName}. One device may only be linked to one account.",
            actionUrl: $actionUrl,
            actionLabel: 'Review devices',
            meta: [
                'attempted_by' => $attemptedByName,
                'bound_to' => $boundToName,
            ],
            mailSubject: 'EAPMS security alert: device link conflict',
        );
    }

    public static function deviceResetRequested(string $employeeName, int $requestId, string $actionUrl): self
    {
        return new self(
            type: 'device-reset.requested',
            title: 'New device reset request',
            message: "{$employeeName} asked to link a different device (request #{$requestId}).",
            actionUrl: $actionUrl,
            actionLabel: 'Review request',
            meta: [
                'employee_name' => $employeeName,
                'request_id' => $requestId,
            ],
            mailSubject: 'New device reset request',
        );
    }

    public static function deviceResetApproved(string $employeeName, string $deadlineLabel, string $actionUrl): self
    {
        return new self(
            type: 'device-reset.approved',
            title: 'Device reset approved',
            message: "{$employeeName}, your previous device has been unlinked. Register your new phone before {$deadlineLabel} — after that the approval expires and you will need to ask again.",
            actionUrl: $actionUrl,
            actionLabel: 'Register my device',
            meta: [
                'employee_name' => $employeeName,
                'deadline' => $deadlineLabel,
            ],
            mailSubject: 'Your EAPMS device reset was approved',
        );
    }

    public static function deviceResetRejected(string $employeeName, ?string $reason, string $actionUrl): self
    {
        return new self(
            type: 'device-reset.rejected',
            title: 'Device reset not approved',
            message: trim("{$employeeName}, your device reset request was not approved. ".($reason ?? '')),
            actionUrl: $actionUrl,
            actionLabel: 'Open My Device',
            meta: [
                'employee_name' => $employeeName,
                'reason' => $reason,
            ],
            mailSubject: 'Your EAPMS device reset request',
        );
    }

    /**
     * A sign-counter regression means two authenticators are signing with one key. The device
     * is revoked automatically; this tells an Admin why.
     */
    public static function deviceCloneSuspected(string $ownerName, string $deviceName, string $actionUrl): self
    {
        return new self(
            type: 'device.clone_suspected',
            title: 'Possible cloned device',
            message: "\"{$deviceName}\" belonging to {$ownerName} reported a decreasing signature counter and has been revoked automatically. This can indicate a duplicated credential.",
            actionUrl: $actionUrl,
            actionLabel: 'Review devices',
            meta: [
                'device_name' => $deviceName,
                'owner_name' => $ownerName,
            ],
            mailSubject: 'EAPMS security alert: possible cloned device',
        );
    }

    /**
     * An accepted check-in whose implied travel since the previous punch is not physically
     * plausible. Deliberately a notification rather than a rejection: the employee keeps their
     * attendance and a human decides whether the movement was real.
     */
    public static function mobileCheckInFlagged(string $employeeName, string $reason, string $actionUrl): self
    {
        return new self(
            type: 'mobile.check_in_flagged',
            title: 'Unusual mobile check-in',
            message: "A mobile check-in by {$employeeName} was accepted but flagged. {$reason}",
            actionUrl: $actionUrl,
            actionLabel: 'Review check-ins',
            meta: [
                'employee_name' => $employeeName,
                'reason' => $reason,
            ],
            mailSubject: 'EAPMS: unusual mobile check-in flagged',
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
