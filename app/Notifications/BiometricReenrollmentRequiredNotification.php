<?php

namespace App\Notifications;

use App\Models\Device;
use App\Models\EmployeeBiometric;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BiometricReenrollmentRequiredNotification extends Notification
{
    use Queueable;

    public function __construct(
        protected Device $targetDevice,
        protected EmployeeBiometric $template,
        protected string $reason,
    ) {}

    public function via(object $notifiable): array
    {
        return $notifiable->routeNotificationForMail() ? ['mail'] : [];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $biometricType = ucfirst((string) $this->template->biometric_type);

        return (new MailMessage)
            ->subject("Biometric re-enrollment required for {$this->targetDevice->name}")
            ->greeting("Hello {$notifiable->full_name},")
            ->line("Your {$biometricType} template could not be transferred automatically to device {$this->targetDevice->name}.")
            ->line("Reason: {$this->reason}")
            ->line('Please complete biometric enrollment on the target device before using it for attendance.')
            ->line('If the device is online, the system may also queue a remote enrollment prompt on the terminal.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'device_id' => $this->targetDevice->id,
            'device_name' => $this->targetDevice->name,
            'biometric_type' => $this->template->biometric_type,
            'reason' => $this->reason,
        ];
    }
}
