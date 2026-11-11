<?php

namespace App\Notifications\Auth;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TemporaryPasswordNotification extends Notification
{
    use Queueable;

    public function __construct(
        public string $temporaryPassword
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your Temporary Password - MR-Pride Electrical Supplies')
            ->greeting("Hello {$notifiable->name},")
            ->line('You have requested a temporary password for your MR-Pride account.')
            ->line("Your temporary password is: **{$this->temporaryPassword}**")
            ->action('Log In Now', url('/login'))
            ->line('For your security, you will be required to change this temporary password immediately after logging in.')
            ->line('If you did not request this temporary password, please secure your account or notify the administrator immediately.');
    }
}
