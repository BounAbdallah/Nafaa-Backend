<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

use Illuminate\Support\Facades\Schedule;

// Relances d'abonnement — tous les jours à 8h (heure serveur)
Schedule::command('subscriptions:send-reminders')->dailyAt('08:00');
