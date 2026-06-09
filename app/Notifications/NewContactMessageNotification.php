<?php

namespace App\Notifications;

use App\Models\ContactMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NewContactMessageNotification extends Notification
{
    use Queueable;

    public function __construct(public ContactMessage $contact) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $subjectLabels = [
            'demo'        => 'Demande de démo',
            'pricing'     => 'Informations tarifaires',
            'support'     => 'Support technique',
            'partnership' => 'Partenariat',
            'other'       => 'Autre',
        ];

        $subjectLabel = $subjectLabels[$this->contact->subject] ?? $this->contact->subject;

        return (new MailMessage)
            ->subject('📬 Nouveau message de contact : ' . $subjectLabel)
            ->greeting('Bonjour,')
            ->line('Un nouveau message de contact a été reçu sur le portail Qiwam.')
            ->line('**De :** ' . $this->contact->name . ' (' . $this->contact->email . ')')
            ->line('**Sujet :** ' . $subjectLabel)
            ->line('**Message :**')
            ->line($this->contact->message)
            ->action('Voir dans le tableau de bord', url('/admin'))
            ->line('Répondez directement à ' . $this->contact->email . ' pour traiter cette demande.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'contact_id'   => $this->contact->id,
            'name'         => $this->contact->name,
            'email'        => $this->contact->email,
            'subject'      => $this->contact->subject,
            'message'      => \Illuminate\Support\Str::limit($this->contact->message, 120),
            'type'         => 'new_contact_message',
            'message_text' => 'Nouveau message de ' . $this->contact->name . ' : ' . \Illuminate\Support\Str::limit($this->contact->message, 80),
        ];
    }
}
