<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        ResetPassword::createUrlUsing(function (object $notifiable, string $token) {
            return config('app.frontend_url', env('FRONTEND_URL', 'http://localhost:3000'))
                ."/auth/reset-password?token={$token}&email={$notifiable->getEmailForPasswordReset()}";
        });

        VerifyEmail::createUrlUsing(function (object $notifiable) {
            $id   = $notifiable->getKey();
            $hash = sha1($notifiable->getEmailForVerification());

            // On génère l'URL signée pour la route API (backend)
            $signedUrl = URL::temporarySignedRoute(
                'verification.verify',
                now()->addMinutes(60),
                ['id' => $id, 'hash' => $hash]
            );

            // On extrait les paramètres (expires, signature) pour les mettre sur l'URL frontend
            $queryString = parse_url($signedUrl, PHP_URL_QUERY);

            return config('app.frontend_url', env('FRONTEND_URL', 'http://localhost:3000'))
                ."/auth/verify-email/{$id}/{$hash}?{$queryString}";
        });
    }
}
