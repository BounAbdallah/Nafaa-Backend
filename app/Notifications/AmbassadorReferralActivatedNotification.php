<?php

namespace App\Notifications;

use App\Models\AmbassadorReferral;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AmbassadorReferralActivatedNotification extends Notification
{
    use Queueable;

    public function __construct(public AmbassadorReferral $referral) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('💰 Nouveau filleul activé — ' . $this->referral->client_name)
            ->greeting('Bonjour ' . $notifiable->name . ',')
            ->line('Excellente nouvelle ! Un de vos filleuls vient d\'activer son abonnement Qiwam ERP.')
            ->line('**Client :** ' . $this->referral->client_name)
            ->line('**Plan :** ' . ($this->referral->subscription_plan ?? 'N/A'))
            ->line('**Montant mensuel :** ' . number_format($this->referral->subscription_amount, 0, ',', ' ') . ' FCFA')
            ->line('**Votre commission :** ' . number_format($this->referral->commission_amount, 0, ',', ' ') . ' FCFA/mois (' . $this->referral->commission_rate . '%)')
            ->action('Voir mon tableau de bord', url(config('app.frontend_url', 'http://localhost:3000') . '/ambassador'))
            ->line('Continuez à partager votre lien pour augmenter vos gains !');
    }
}
