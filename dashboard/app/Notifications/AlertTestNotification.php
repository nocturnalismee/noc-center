<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Send test" message for the alert settings page (spec §9.4).
 */
class AlertTestNotification extends Notification
{
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('NOC dashboard test alert')
            ->line('This is a test message from the NOC dashboard alert settings.')
            ->line('If you received it, the email channel is working.');
    }
}
