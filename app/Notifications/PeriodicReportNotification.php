<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Rapport périodique (journalier / hebdomadaire / mensuel).
 * Contenu générique : un titre, une période, et des sections de lignes [label => valeur].
 *
 * $sections = [
 *   ['title' => 'Vue d\'ensemble', 'rows' => [['label' => 'Ventes', 'value' => '120 000 FCFA'], …]],
 *   …
 * ]
 */
class PeriodicReportNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $title,
        public readonly string $periodLabel,
        public readonly array $sections,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->title)
            ->greeting('Bonjour ' . $notifiable->name . ',')
            ->line("Voici votre rapport pour la période : **{$this->periodLabel}**.");

        foreach ($this->sections as $section) {
            $mail->line('---');
            $mail->line('**' . $section['title'] . '**');
            foreach ($section['rows'] as $row) {
                $mail->line("• {$row['label']} : **{$row['value']}**");
            }
        }

        return $mail->line('---')
            ->line('Rapport généré automatiquement par Qiwam ERP. Vous pouvez changer la fréquence dans votre profil.');
    }

    public function toArray(object $notifiable): array
    {
        // Résumé court pour la notification in-app
        $firstRows = collect($this->sections)
            ->flatMap(fn ($s) => $s['rows'])
            ->take(3)
            ->map(fn ($r) => "{$r['label']} : {$r['value']}")
            ->implode(' · ');

        return [
            'type'    => 'periodic_report',
            'title'   => $this->title,
            'message' => "{$this->periodLabel} — {$firstRows}",
        ];
    }
}
