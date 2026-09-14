<?php

namespace App\Notifications;

use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Envoyée au propriétaire du tenant juste après la création de l'espace.
 * Confirme que le dossier est bien reçu et en attente de validation admin.
 */
class AccountCreatedNotification extends Notification
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

        return (new MailMessage)
            ->subject('🎉 Votre espace ' . $this->tenant->name . ' a bien été créé — Qiwam ERP')
            ->greeting('Bonjour ' . $notifiable->name . ' !')
            ->line('Bonne nouvelle : votre espace de travail **' . $this->tenant->name . '** a été créé avec succès sur Qiwam ERP.')
            ->line('---')
            ->line('**Récapitulatif de votre inscription :**')
            ->line('• Entreprise : ' . $this->tenant->name)
            ->line('• Secteur : ' . ($this->tenant->industry ?? '—'))
            ->line('• Pays : ' . ($this->tenant->settings['country'] ?? '—'))
            ->line('• Plan : ' . ucfirst($this->tenant->plan ?? 'Démarrage'))
            ->line('---')
            ->line('⏳ **Prochaine étape :** Votre dossier est en cours d\'examen par notre équipe. Vous recevrez un e-mail de confirmation dès que votre accès sera activé — généralement **sous 24 heures**.')
            ->line('En attendant, pensez à **vérifier votre adresse e-mail** en cliquant sur le lien que nous venons de vous envoyer séparément.')
            ->action('Accéder à Qiwam', $frontendUrl)
            ->line('Merci de nous faire confiance. Nous avons hâte de vous accompagner dans la croissance de votre activité.')
            ->salutation("L'équipe Qiwam ERP — Noor Web Services");
    }
}
