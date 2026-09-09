<?php

namespace App\Console\Commands;

use App\Models\SubscriptionPayment;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\PeriodicReportNotification;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Rapports automatiques (exécutée chaque jour par le scheduler).
 * Chaque utilisateur choisit sa fréquence (report_frequency) :
 * - daily   → envoyé tous les jours (période : hier)
 * - weekly  → envoyé le lundi (période : semaine précédente)
 * - monthly → envoyé le 1er du mois (période : mois précédent)
 *
 * Destinataires :
 * - Admin d'espace : activité de SON espace (ventes, commandes, clients, stock bas, dépenses)
 * - Super admin    : plateforme globale + détail par pays (uniquement les pays ayant un admin pays)
 * - Admin pays     : plateforme limitée à son pays
 */
class SendPeriodicReports extends Command
{
    protected $signature   = 'reports:send {--frequency= : Forcer une fréquence (daily|weekly|monthly) pour test}';
    protected $description = 'Envoie les rapports périodiques aux admins selon leur fréquence choisie';

    public function handle(): int
    {
        $due = $this->dueFrequencies();

        if (empty($due)) {
            $this->info('Aucune fréquence due aujourd\'hui.');
            return self::SUCCESS;
        }

        $sent = 0;

        foreach ($due as $frequency) {
            [$from, $to, $periodLabel] = $this->period($frequency);

            $users = User::with(['tenant', 'roles'])
                ->where('report_frequency', $frequency)
                ->where('is_active', true)
                ->get();

            foreach ($users as $user) {
                try {
                    $notification = $this->buildReport($user, $from, $to, $periodLabel, $frequency);
                    if ($notification) {
                        $user->notify($notification);
                        $sent++;
                    }
                } catch (\Exception $e) {
                    Log::warning("Rapport périodique échoué (user {$user->id}) : " . $e->getMessage());
                }
            }
        }

        $this->info("Rapports envoyés : {$sent}");

        return self::SUCCESS;
    }

    /** Fréquences à traiter aujourd'hui. */
    private function dueFrequencies(): array
    {
        if ($forced = $this->option('frequency')) {
            return [$forced];
        }

        $due = ['daily'];
        if (now()->isMonday())       $due[] = 'weekly';
        if (now()->day === 1)        $due[] = 'monthly';

        return $due;
    }

    /** Bornes et libellé de la période couverte. */
    private function period(string $frequency): array
    {
        return match ($frequency) {
            'weekly' => [
                now()->subWeek()->startOfWeek(),
                now()->subWeek()->endOfWeek(),
                'Semaine du ' . now()->subWeek()->startOfWeek()->format('d/m') . ' au ' . now()->subWeek()->endOfWeek()->format('d/m/Y'),
            ],
            'monthly' => [
                now()->subMonth()->startOfMonth(),
                now()->subMonth()->endOfMonth(),
                now()->subMonth()->translatedFormat('F Y'),
            ],
            default => [ // daily
                now()->subDay()->startOfDay(),
                now()->subDay()->endOfDay(),
                'Journée du ' . now()->subDay()->format('d/m/Y'),
            ],
        };
    }

    /** Construit le rapport adapté au rôle de l'utilisateur. */
    private function buildReport(User $user, Carbon $from, Carbon $to, string $periodLabel, string $frequency): ?PeriodicReportNotification
    {
        $freqLabel = match ($frequency) { 'weekly' => 'hebdomadaire', 'monthly' => 'mensuel', default => 'journalier' };

        if ($user->hasRole('super_admin')) {
            $sections = [$this->platformSection('Plateforme — Global', $from, $to, null)];

            // Détail par pays : uniquement les pays ayant un admin pays
            $countries = User::role('country_admin')
                ->whereNotNull('country_code')
                ->distinct()
                ->pluck('country_code');

            foreach ($countries as $code) {
                $sections[] = $this->platformSection("Pays — {$code}", $from, $to, $code);
            }

            return new PeriodicReportNotification("📊 Rapport {$freqLabel} Qiwam — Plateforme", $periodLabel, $sections);
        }

        if ($user->hasRole('country_admin') && $user->country_code) {
            $sections = [$this->platformSection("Pays — {$user->country_code}", $from, $to, $user->country_code)];

            return new PeriodicReportNotification("📊 Rapport {$freqLabel} Qiwam — {$user->country_code}", $periodLabel, $sections);
        }

        // Admin d'espace (tenant)
        if ($user->tenant_id && $user->isTenantAdmin() && $user->tenant) {
            return new PeriodicReportNotification(
                "📊 Rapport {$freqLabel} — {$user->tenant->name}",
                $periodLabel,
                [$this->tenantSection($user->tenant, $from, $to)]
            );
        }

        return null; // employés / autres rôles : pas de rapport
    }

    /** Section plateforme (globale ou filtrée sur un pays). */
    private function platformSection(string $title, Carbon $from, Carbon $to, ?string $country): array
    {
        $tenantIds = $country
            ? Tenant::where('settings->country', $country)->pluck('id')
            : null;

        $scopeT = fn ($q) => $tenantIds !== null ? $q->whereIn('id', $tenantIds) : $q;
        $scopeP = fn ($q) => $tenantIds !== null ? $q->whereIn('tenant_id', $tenantIds) : $q;
        $scopeU = fn ($q) => $tenantIds !== null ? $q->whereIn('tenant_id', $tenantIds) : $q;

        $newTenants   = $scopeT(Tenant::query())->whereBetween('created_at', [$from, $to])->count();
        $expired      = $scopeT(Tenant::query())->whereBetween('plan_expires_at', [$from, $to])->count();
        $totalUsers   = $scopeU(User::query())->whereNotNull('tenant_id')->count();
        $newUsers     = $scopeU(User::query())->whereNotNull('tenant_id')->whereBetween('created_at', [$from, $to])->count();
        $paid         = $scopeP(SubscriptionPayment::where('status', 'paid'))->whereBetween('updated_at', [$from, $to])->sum('amount');
        $overdueTotal = $scopeP(SubscriptionPayment::where('status', 'overdue'))->sum('amount');

        return [
            'title' => $title,
            'rows'  => [
                ['label' => 'Nouveaux abonnements (espaces)', 'value' => (string) $newTenants],
                ['label' => 'Abonnements expirés',            'value' => (string) $expired],
                ['label' => 'Utilisateurs (total)',           'value' => (string) $totalUsers],
                ['label' => 'Nouveaux utilisateurs',          'value' => (string) $newUsers],
                ['label' => 'Encaissé sur la période',        'value' => number_format((float) $paid, 0, ',', ' ') . ' FCFA'],
                ['label' => 'Impayés (cumul)',                'value' => number_format((float) $overdueTotal, 0, ',', ' ') . ' FCFA'],
            ],
        ];
    }

    /** Section activité d'un espace (tenant). */
    private function tenantSection(Tenant $tenant, Carbon $from, Carbon $to): array
    {
        $currency = $tenant->settings['currency'] ?? 'XOF';
        $fmt      = fn ($n) => number_format((float) $n, 0, ',', ' ') . ' ' . ($currency === 'XOF' ? 'FCFA' : $currency);

        $orders = DB::table('orders')
            ->where('tenant_id', $tenant->id)
            ->where('status', '!=', 'cancelled')
            ->whereNull('deleted_at')
            ->whereBetween('created_at', [$from, $to]);

        $orderCount = (clone $orders)->count();
        $revenue    = (clone $orders)->sum('total_amount');

        $expenses = DB::table('expenses')
            ->where('tenant_id', $tenant->id)
            ->whereBetween('created_at', [$from, $to])
            ->sum('amount');

        $newCustomers = DB::table('customers')
            ->where('tenant_id', $tenant->id)
            ->whereBetween('created_at', [$from, $to])
            ->count();

        $lowStock = DB::table('products')
            ->where('tenant_id', $tenant->id)
            ->whereIn('type', ['product', 'material'])
            ->whereColumn('stock_quantity', '<=', 'stock_alert')
            ->count();

        return [
            'title' => 'Activité de ' . $tenant->name,
            'rows'  => [
                ['label' => 'Chiffre d\'affaires',     'value' => $fmt($revenue)],
                ['label' => 'Commandes',               'value' => (string) $orderCount],
                ['label' => 'Dépenses',                'value' => $fmt($expenses)],
                ['label' => 'Nouveaux clients',        'value' => (string) $newCustomers],
                ['label' => 'Produits en stock bas',   'value' => (string) $lowStock],
            ],
        ];
    }
}
