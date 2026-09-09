<?php

namespace App\Notifications;

use App\Models\Ambassador;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AmbassadorWelcomeNotification extends Notification
{
    use Queueable;

    public function __construct(
        public Ambassador $ambassador,
        public string $temporaryPassword,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $referralUrl = $this->ambassador->referral_url;

        return (new MailMessage)
            ->subject('🎉 Bienvenue dans le programme Ambassadeur Qiwam !')
            ->greeting('Bonjour ' . $notifiable->name . ',')
            ->line('Vous avez été ajouté au programme ambassadeur de **Qiwam ERP**. Félicitations !')
            ->line('---')
            ->line('### Vos informations de connexion')
            ->line('**Email :** ' . $notifiable->email)
            ->line('**Mot de passe temporaire :** `' . $this->temporaryPassword . '`')
            ->action('Accéder à mon espace ambassadeur', url(config('app.frontend_url', 'http://localhost:3000') . '/auth/login'))
            ->line('> ⚠️ Merci de changer votre mot de passe dès votre première connexion.')
            ->line('---')
            ->line('### Votre lien de parrainage')
            ->line('Partagez ce lien avec vos prospects pour qu\'ils s\'inscrivent via vous :')
            ->line('`' . $referralUrl . '`')
            ->line('**Votre commission :** ' . $this->ambassador->commission_rate . '% sur chaque abonnement activé.')
            ->line('---')
            ->line('Pour toute question : contact@noorwebservice.com');
    }
}
