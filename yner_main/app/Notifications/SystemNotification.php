<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
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
    ) {
    }

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

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
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