<?php

namespace App\Notifications;

use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TeamInvitationNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly Tenant $tenant,
        public readonly string $invitedByName,
        public readonly ?string $tempPassword,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $frontendUrl = rtrim(config('app.frontend_url', config('app.url')), '/');
        $loginUrl    = $frontendUrl . '/login';

        $mail = (new MailMessage)
            ->subject('Vous avez été ajouté à l\'espace ' . $this->tenant->name . ' — Qiwam ERP')
            ->greeting('Bonjour ' . $notifiable->name . ' !')
            ->line('**' . $this->invitedByName . '** vous a ajouté comme membre de l\'espace de travail **' . $this->tenant->name . '** sur Qiwam ERP.');

        if ($this->tempPassword) {
            $mail
                ->line('---')
                ->line('**Vos identifiants de connexion :**')
                ->line('• E-mail : ' . $notifiable->email)
                ->line('• Mot de passe temporaire : `' . $this->tempPassword . '`')
                ->line('⚠️ Pensez à changer votre mot de passe dès votre première connexion.');
        } else {
            $mail->line('Vous pouvez vous connecter avec votre mot de passe habituel.');
        }

        return $mail
            ->action('Se connecter à Qiwam', $loginUrl)
            ->line('Bienvenue dans l\'équipe !')
            ->salutation("L'équipe Qiwam ERP — Noor Web Services");
    }
}
