<?php

namespace App\Notifications\Auth;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DeviceAccessNotification extends Notification
{
    use Queueable;

    public function __construct(
        public string $accessUrl,
        public string $deviceName,
        public string $ipAddress,
        public string $expiresAt
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Authorize Device Access - MR-Pride Electrical Supplies')
            ->greeting("Hello {$notifiable->name},")
            ->line('An attempt to access the MR-Pride administrative portal was detected from an unrecognized device:')
            ->line("**Device:** {$this->deviceName}")
            ->line("**IP Address:** {$this->ipAddress}")
            ->line('If this was you, please click the button below from that physical device to authorize it and gain access to the login page and dashboard:')
            ->action('Authorize This Device & Access Portal', $this->accessUrl)
            ->line("This single-use authorization link expires on {$this->expiresAt}.")
            ->line('If you did not initiate this access request, please contact the Owner/Superadmin immediately.');
    }
}
