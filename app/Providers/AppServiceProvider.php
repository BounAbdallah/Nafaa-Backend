<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
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
            $id        = $notifiable->getKey();
            $hash      = sha1($notifiable->getEmailForVerification());
            $expires   = now()->addMinutes(60)->timestamp;
            $signature = hash_hmac('sha256', "{$id}{$hash}{$expires}", config('app.key'));

            return config('app.frontend_url', env('FRONTEND_URL', 'http://localhost:3000'))
                ."/auth/verify-email/{$id}/{$hash}?expires={$expires}&signature={$signature}";
        });
    }
}
