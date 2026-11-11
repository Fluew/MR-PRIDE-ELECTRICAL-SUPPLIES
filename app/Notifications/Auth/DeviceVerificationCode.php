<?php

namespace App\Notifications\Auth;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DeviceVerificationCode extends Notification
{
    use Queueable;

    public function __construct(
        public string $code,
        public string $deviceName,
        public string $ipAddress
    ) {
        //
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('MR-Pride: New Device Verification Code')
            ->greeting("Hello {$notifiable->name},")
            ->line('An unrecognized device has attempted to sign in to your MR-Pride administrative account:')
            ->line("**Device:** {$this->deviceName}")
            ->line("**IP Address:** {$this->ipAddress}")
            ->line('Please enter the following 6-digit verification code to approve this device:')
            ->line("## **{$this->code}**")
            ->line('This code is valid for 15 minutes. If you did not make this request, please contact your system administrator immediately.')
            ->salutation("Best regards,\nMR-Pride Electrical Supplies Security Team");
    }
}
