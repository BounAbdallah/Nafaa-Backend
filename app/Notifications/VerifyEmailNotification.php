<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\URL;

class VerifyEmailNotification extends VerifyEmail
{
    /**
     * Build the mail representation of the notification.
     */
    public function toMail(mixed $notifiable): MailMessage
    {
        $verificationUrl = $this->verificationUrl($notifiable);

        return (new MailMessage)
            ->subject('Vérifiez votre adresse e-mail — Qiwam ERP')
            ->greeting('Bonjour ' . $notifiable->name . ' !')
            ->line('Merci de rejoindre **Qiwam ERP**, la solution de gestion intelligente pour votre entreprise.')
            ->line('Cliquez sur le bouton ci-dessous pour activer votre compte.')
            ->action('Vérifier mon adresse e-mail', $verificationUrl)
            ->line('Ce lien expirera dans 60 minutes.')
            ->line('Si vous n\'avez pas créé de compte, aucune action n\'est requise.')
            ->salutation('L\'équipe Qiwam ERP — Noor Web Services');
    }

    /**
     * Override the verification URL to use FRONTEND_URL instead of APP_URL.
     */
    protected function verificationUrl(mixed $notifiable): string
    {
        $backendUrl = URL::temporarySignedRoute(
            'verification.verify',
            Carbon::now()->addMinutes(Config::get('auth.verification.expire', 60)),
            [
                'id'   => $notifiable->getKey(),
                'hash' => sha1($notifiable->getEmailForVerification()),
            ]
        );

        // Extract id, hash and query string from backend URL
        // Backend: https://qiwam.noorwebservices.com/api/v1/auth/email/verify/{id}/{hash}?expires=...&signature=...
        // Frontend: https://app-qiwam.noorwebservices.com/auth/verify-email/{id}/{hash}?expires=...&signature=...
        $frontendUrl = rtrim(config('app.frontend_url', config('app.url')), '/');
        $appUrl      = rtrim(config('app.url'), '/');

        // Replace backend base URL + API path with frontend base URL + frontend path
        return str_replace(
            $appUrl . '/api/v1/auth/email/verify/',
            $frontendUrl . '/auth/verify-email/',
            $backendUrl
        );
    }
}
