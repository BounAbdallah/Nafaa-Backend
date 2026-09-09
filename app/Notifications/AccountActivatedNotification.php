<?php

namespace App\Notifications;

use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Envoyée au propriétaire du tenant quand un super admin active son compte.
 */
class AccountActivatedNotification extends Notification
{
    use Queueable;

    public function __construct(public readonly Tenant $tenant) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $frontendUrl = rtrim(config('app.frontend_url', config('app.url')), '/');
        $loginUrl    = $frontendUrl . '/auth/login';

        return (new MailMessage)
            ->subject('✅ Votre espace Qiwam est maintenant actif !')
            ->greeting('Félicitations ' . $notifiable->name . ' !')
            ->line('Votre espace de travail **' . $this->tenant->name . '** vient d\'être **approuvé et activé** par notre équipe.')
            ->line('Vous pouvez dès maintenant vous connecter et commencer à gérer votre activité.')
            ->action('Se connecter maintenant', $loginUrl)
            ->line('---')
            ->line('**Ce qui vous attend sur Qiwam :**')
            ->line('• 📦 Gestion de votre stock en temps réel')
            ->line('• 💰 Suivi des ventes et factures')
            ->line('• 👥 Gestion des clients et de l\'équipe')
            ->line('• 📊 Tableaux de bord et rapports détaillés')
            ->line('---')
            ->line('Si vous avez des questions, notre équipe est disponible pour vous accompagner.')
            ->salutation("Bonne continuation ! L'équipe Qiwam ERP — Noor Web Services");
    }
}
