<?php

namespace App\Notifications;

use App\Mail\PlatformEmail;
use App\Support\EmailOutbox;
use App\Support\EmailTemplates;
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

    public function toMail(object $notifiable): PlatformEmail
    {
        $copy = EmailTemplates::render(EmailOutbox::template(EmailTemplates::VERIFY), [
            'name' => $notifiable->name,
            'site_name' => config('app.name'),
        ]);
        $copy['button'] = $copy['button'] ?: EmailTemplates::defaults(EmailTemplates::VERIFY)['button'];

        return (new PlatformEmail($copy, $this->url($notifiable)))->to($notifiable->email);
    }
}
