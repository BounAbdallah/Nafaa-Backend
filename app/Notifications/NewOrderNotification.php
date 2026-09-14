<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class NewOrderNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $reference,
        public readonly float  $total,
        public readonly string $currency = 'FCFA',
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type'         => 'new_order',
            'message_text' => "Nouvelle vente {$this->reference} — " . number_format($this->total, 0, ',', ' ') . " {$this->currency}.",
            'reference'    => $this->reference,
            'total'        => $this->total,
            'url'          => '/orders',
        ];
    }
}
