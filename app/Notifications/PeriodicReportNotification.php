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
            ->line("Voici votre rapport pour la période : **{$this->periodLabel}**.")
            ->line('Vous le trouverez également en pièce jointe au format PDF.');

        foreach ($this->sections as $section) {
            $mail->line('---');
            $mail->line('**' . $section['title'] . '**');
            foreach ($section['rows'] as $row) {
                $mail->line("• {$row['label']} : **{$row['value']}**");
            }
        }

        $mail->line('---')
            ->line('Rapport généré automatiquement par Qiwam ERP. Vous pouvez changer la fréquence dans votre profil.');

        // ── Pièce jointe PDF ──
        try {
            $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.periodic-report', [
                // dompdf ne sait pas rendre les émojis — on les retire du titre PDF
                'title'         => trim(preg_replace('/[\x{1F000}-\x{1FFFF}\x{2600}-\x{27BF}]/u', '', $this->title)),
                'periodLabel'   => $this->periodLabel,
                'sections'      => $this->sections,
                'recipientName' => $notifiable->name,
            ]);

            $filename = 'rapport-qiwam-' . now()->format('Y-m-d') . '.pdf';
            $mail->attachData($pdf->output(), $filename, ['mime' => 'application/pdf']);
        } catch (\Exception $e) {
            // Le PDF est un bonus — l'e-mail part quand même si la génération échoue
            \Illuminate\Support\Facades\Log::warning('PDF du rapport non généré : ' . $e->getMessage());
        }

        return $mail;
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
