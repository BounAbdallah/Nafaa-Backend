<?php

namespace App\Notifications;

use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Relance d'abonnement envoyée au propriétaire d'un espace :
 * - trial_ending  : l'essai se termine bientôt
 * - trial_ended   : l'essai vient de se terminer
 * - plan_expiring : l'abonnement expire bientôt
 * - plan_expired  : l'abonnement a expiré
 */
class SubscriptionReminderNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly Tenant $tenant,
        public readonly string $type,
        public readonly int $days = 0,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    private function content(): array
    {
        return match ($this->type) {
            'trial_ending' => [
                'subject' => "⏳ Votre période d'essai se termine dans {$this->days} jour" . ($this->days > 1 ? 's' : ''),
                'lines'   => [
                    "Votre période d'essai pour l'espace **{$this->tenant->name}** se termine dans **{$this->days} jour" . ($this->days > 1 ? 's' : '') . '**.',
                    'Pour continuer à utiliser Qiwam sans interruption, pensez à régler votre abonnement.',
                ],
            ],
            'trial_ended' => [
                'subject' => "🔔 Votre période d'essai est terminée",
                'lines'   => [
                    "La période d'essai de l'espace **{$this->tenant->name}** est arrivée à son terme.",
                    'Réglez votre abonnement pour conserver l\'accès à toutes les fonctionnalités.',
                ],
            ],
            'plan_expiring' => [
                'subject' => "⏳ Votre abonnement expire dans {$this->days} jour" . ($this->days > 1 ? 's' : ''),
                'lines'   => [
                    "L'abonnement de l'espace **{$this->tenant->name}** expire dans **{$this->days} jour" . ($this->days > 1 ? 's' : '') . '**.',
                    'Renouvelez-le dès maintenant pour éviter toute interruption de service.',
                ],
            ],
            default => [ // plan_expired
                'subject' => "⛔ Votre abonnement a expiré",
                'lines'   => [
                    "L'abonnement de l'espace **{$this->tenant->name}** a expiré.",
                    'L\'accès à votre espace est suspendu jusqu\'au renouvellement. Contactez l\'équipe Qiwam ou réglez votre abonnement.',
                ],
            ],
        };
    }

    public function toMail(object $notifiable): MailMessage
    {
        $content     = $this->content();
        $frontendUrl = rtrim(config('app.frontend_url', config('app.url')), '/');

        $mail = (new MailMessage)
            ->subject($content['subject'])
            ->greeting('Bonjour ' . $notifiable->name . ',');

        foreach ($content['lines'] as $line) {
            $mail->line($line);
        }

        return $mail
            ->action('Voir mon abonnement', $frontendUrl . '/subscription')
            ->line('Merci de votre confiance — l\'équipe Qiwam.');
    }

    public function toArray(object $notifiable): array
    {
        $content = $this->content();

        return [
            'type'      => 'subscription_' . $this->type,
            'tenant_id' => $this->tenant->id,
            'title'     => $content['subject'],
            'message'   => strip_tags(str_replace('**', '', $content['lines'][0])),
        ];
    }
}
