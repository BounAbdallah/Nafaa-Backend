<?php

namespace App\Notifications;

use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TenantCreatedNotification extends Notification
{
    use Queueable;

    public function __construct(public Tenant $tenant)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Nouvel espace de travail à approuver : ' . $this->tenant->name)
            ->greeting('Bonjour Super Admin,')
            ->line('Un nouvel espace de travail vient d\'être créé sur Qiwam.')
            ->line('Nom de l\'entreprise : ' . $this->tenant->name)
            ->line('Secteur : ' . $this->tenant->industry)
            ->action('Examiner la demande', url('/admin/users?tenant_status=pending'))
            ->line('Veuillez examiner les détails et approuver l\'accès si tout est en ordre.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'tenant_id'   => $this->tenant->id,
            'tenant_name' => $this->tenant->name,
            'owner_name'  => $this->tenant->owner?->name,
            'message'     => 'Nouvel espace de travail créé : ' . $this->tenant->name,
            'type'        => 'new_subscription',
        ];
    }
}
