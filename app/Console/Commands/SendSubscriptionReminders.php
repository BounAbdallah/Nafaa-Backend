<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Notifications\SubscriptionReminderNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Relances d'abonnement (exécutée chaque jour par le scheduler) :
 * - essai se terminant dans 3 jours / terminé aujourd'hui
 * - abonnement expirant dans 7 jours, 3 jours / expiré aujourd'hui
 * Chaque condition matche un jour précis → pas de doublons d'un jour à l'autre.
 */
class SendSubscriptionReminders extends Command
{
    protected $signature   = 'subscriptions:send-reminders';
    protected $description = "Envoie les relances d'essai et d'abonnement aux propriétaires d'espaces";

    public function handle(): int
    {
        $sent = 0;

        // ── Essais se terminant dans 3 jours ──────────────────────────────
        $sent += $this->notify(
            Tenant::whereDate('trial_ends_at', now()->addDays(3)->toDateString()),
            'trial_ending', 3
        );

        // ── Essais terminés aujourd'hui ────────────────────────────────────
        $sent += $this->notify(
            Tenant::whereDate('trial_ends_at', now()->toDateString()),
            'trial_ended'
        );

        // ── Abonnements expirant dans 7 jours / 3 jours ───────────────────
        foreach ([7, 3] as $days) {
            $sent += $this->notify(
                Tenant::whereDate('plan_expires_at', now()->addDays($days)->toDateString()),
                'plan_expiring', $days
            );
        }

        // ── Abonnements expirés aujourd'hui ────────────────────────────────
        $sent += $this->notify(
            Tenant::whereDate('plan_expires_at', now()->toDateString()),
            'plan_expired'
        );

        $this->info("Relances envoyées : {$sent}");

        return self::SUCCESS;
    }

    private function notify($query, string $type, int $days = 0): int
    {
        $count = 0;

        $query->where('is_active', true)
            ->with('owner')
            ->get()
            ->each(function (Tenant $tenant) use ($type, $days, &$count) {
                if (! $tenant->owner) return;

                try {
                    $tenant->owner->notify(new SubscriptionReminderNotification($tenant, $type, $days));
                    $count++;
                } catch (\Exception $e) {
                    Log::warning("Relance abonnement échouée (tenant {$tenant->id}, {$type}) : " . $e->getMessage());
                }
            });

        return $count;
    }
}
