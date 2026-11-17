<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

class CheckoutVerification extends Notification
{
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function url(object $notifiable): string
    {
        return URL::temporarySignedRoute('checkout.verify', now()->addHour(), [
            'id' => $notifiable->getKey(),
            'hash' => sha1($notifiable->getEmailForVerification()),
        ]);
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Verify your email to continue on Bambodia')
            ->line('Verify your email to return to your saved Bambodia cart.')
            ->action('Verify email and return to cart', $this->url($notifiable))
            ->line('This link expires in one hour. If you did not request it, you can ignore this email.');
    }
}
