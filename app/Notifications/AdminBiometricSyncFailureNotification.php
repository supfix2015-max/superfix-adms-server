<?php

namespace App\Notifications;

use App\Models\BiometricTemplate;
use App\Models\Device;
use App\Models\Employee;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AdminBiometricSyncFailureNotification extends Notification
{
    use Queueable;

    public function __construct(
        protected Employee $employee,
        protected Device $targetDevice,
        protected BiometricTemplate $template,
        protected string $reason,
    ) {}

    public function via(object $notifiable): array
    {
        return filled($notifiable->email) ? ['mail'] : [];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Biometric sync failed for {$this->employee->employee_id}")
            ->greeting("Hello {$notifiable->name},")
            ->line("Biometric sync failed for employee {$this->employee->full_name} ({$this->employee->employee_id}).")
            ->line("Target device: {$this->targetDevice->name} ({$this->targetDevice->ip_address})")
            ->line("Template type: {$this->template->template_type}")
            ->line("Reason: {$this->reason}")
            ->line('The device has been flagged for manual review or re-enrollment.');
    }
}
