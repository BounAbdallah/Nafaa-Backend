<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

use Illuminate\Support\Facades\Schedule;

// Relances d'abonnement — tous les jours à 8h (heure serveur)
Schedule::command('subscriptions:send-reminders')->dailyAt('08:00');

// Rapports périodiques — tous les jours à 7h (la commande gère daily/weekly/monthly)
Schedule::command('reports:send')->dailyAt('07:00');
