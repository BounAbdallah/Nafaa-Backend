<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Concerns\ScopesByCountry;
use App\Http\Controllers\Controller;
use App\Models\SubscriptionPayment;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminSubscriptionController extends Controller
{
    use ScopesByCountry;

    /**
     * IDs des tenants visibles par l'admin courant.
     * null = aucun filtre (super admin) ; array = restreint au pays.
     */
    private function visibleTenantIds(): ?array
    {
        $user = request()->user();

        // Admin pays : toujours restreint à son pays
        if ($this->isCountryAdmin($user) && $user->country_code) {
            return Tenant::where('settings->country', $user->country_code)->pluck('id')->all();
        }

        // Super admin : filtre pays optionnel (?country=GN)
        if ($country = request('country')) {
            return Tenant::where('settings->country', strtoupper($country))->pluck('id')->all();
        }

        return null;
    }

    /**
     * Get inactive tenants requiring approval.
     */
    public function pendingApprovals(): JsonResponse
    {
        $ids = $this->visibleTenantIds();
        $tenants = Tenant::where('is_active', false)
            ->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))
            ->with('owner')
            ->latest()
            ->get();

        return response()->json([
            'success' => true,
            'tenants' => $tenants,
        ]);
    }

    /**
     * Approve and activate a tenant.
     * Automatically applies the pack's features as enabled_modules.
     */
    public function approve(Tenant $tenant): JsonResponse
    {
        $this->assertCanManageTenant(request()->user(), $tenant);
        $tenant->loadMissing('pack');

        $updates = ['is_active' => true];

        // Apply pack modules automatically — super admin can still override later
        if ($tenant->pack && !empty($tenant->pack->features)) {
            $settings = $tenant->settings ?? [];
            $settings['enabled_modules'] = $tenant->pack->features;
            $updates['settings'] = $settings;
        }

        $tenant->update($updates);

        // ── Commission ambassadeur ─────────────────────────────────────────
        if ($tenant->ambassador_id) {
            $ambassador = $tenant->ambassador()->with('user')->first();
            if ($ambassador && $ambassador->status === 'active') {
                $subscriptionAmount = $tenant->pack?->price ?? 12500;
                $commissionAmount   = round($subscriptionAmount * $ambassador->commission_rate / 100, 2);

                $owner = $tenant->owner;

                $referral = \App\Models\AmbassadorReferral::updateOrCreate(
                    ['ambassador_id' => $ambassador->id, 'tenant_id' => $tenant->id],
                    [
                        'client_name'         => $owner?->name ?? $tenant->name,
                        'client_email'        => $owner?->email ?? '',
                        'subscription_plan'   => $tenant->plan,
                        'subscription_amount' => $subscriptionAmount,
                        'commission_rate'     => $ambassador->commission_rate,
                        'commission_amount'   => $commissionAmount,
                        'status'              => 'active',
                        'activated_at'        => now(),
                    ]
                );

                $ambassador->recalculate();

                // Notifier l'ambassadeur
                try {
                    $ambassador->user->notify(new \App\Notifications\AmbassadorReferralActivatedNotification($referral));
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::warning('Ambassador notification failed: ' . $e->getMessage());
                }
            }
        }

        return response()->json([
            'success' => true,
            'message' => "L'espace {$tenant->name} a été activé avec succès.",
        ]);
    }

    /**
     * Revenue + subscription statistics for the admin dashboard.
     * Accepts: ?year=2026
     */
    public function stats(): JsonResponse
    {
        $year    = (int) request('year', date('Y'));
        $nowYear = (int) date('Y');
        $nowMon  = (int) date('n');

        $ids   = $this->visibleTenantIds();
        $scopeP = fn ($q) => $q->when($ids !== null, fn ($qq) => $qq->whereIn('subscription_payments.tenant_id', $ids));
        $scopeT = fn ($q) => $q->when($ids !== null, fn ($qq) => $qq->whereIn('tenants.id', $ids));

        // ── Revenus tous temps ────────────────────────────────────────────────
        $totalRevenue   = $scopeP(SubscriptionPayment::where('status', 'paid'))->sum('amount');
        $overdueRevenue = $scopeP(SubscriptionPayment::where('status', 'overdue'))->sum('amount');

        // ── CA de l'année sélectionnée ────────────────────────────────────────
        $revenueYear = $scopeP(SubscriptionPayment::where('status', 'paid'))
            ->where('year', $year)->sum('amount');

        // ── CA du mois courant ────────────────────────────────────────────────
        $revenueMonth = $scopeP(SubscriptionPayment::where('status', 'paid'))
            ->where('year', $nowYear)->where('month', $nowMon)->sum('amount');

        // ── Revenus par mois pour l'année choisie (tous les 12 mois) ─────────
        $paidByMonth = $scopeP(SubscriptionPayment::selectRaw('month, SUM(amount) as total'))
            ->where('year', $year)->where('status', 'paid')
            ->groupBy('month')->pluck('total', 'month');

        $overdueByMonth = $scopeP(SubscriptionPayment::selectRaw('month, SUM(amount) as total'))
            ->where('year', $year)->where('status', 'overdue')
            ->groupBy('month')->pluck('total', 'month');

        $revenueByMonth = collect(range(1, 12))->map(fn ($m) => [
            'month'   => $m,
            'paid'    => (float) ($paidByMonth[$m]   ?? 0),
            'overdue' => (float) ($overdueByMonth[$m] ?? 0),
        ]);

        // ── CA par pack (année) ───────────────────────────────────────────────
        $revenueByPack = SubscriptionPayment::selectRaw(
                'tenants.pack_id,
                 SUM(subscription_payments.amount) as total,
                 COUNT(DISTINCT subscription_payments.tenant_id) as clients'
            )
            ->join('tenants', 'subscription_payments.tenant_id', '=', 'tenants.id')
            ->where('subscription_payments.status', 'paid')
            ->where('subscription_payments.year', $year)
            ->whereNotNull('tenants.pack_id')
            ->when($ids !== null, fn ($q) => $q->whereIn('tenants.id', $ids))
            ->groupBy('tenants.pack_id')
            ->get()
            ->map(function ($row) {
                $pack = \App\Models\Pack::find($row->pack_id);
                return [
                    'pack_id'  => $row->pack_id,
                    'name'     => $pack?->name ?? 'Inconnu',
                    'price'    => (float) ($pack?->price ?? 0),
                    'total'    => (float) $row->total,
                    'clients'  => (int)   $row->clients,
                ];
            });

        // ── Espaces actifs / en attente ───────────────────────────────────────
        $tQ = fn () => Tenant::query()->when($ids !== null, fn ($q) => $q->whereIn('id', $ids));
        $activeCount  = $tQ()->where('is_active', true)->count();
        $pendingCount = $tQ()->where('is_active', false)->count();
        $newThisMonth = $tQ()->whereYear('created_at', $nowYear)
            ->whereMonth('created_at', $nowMon)->count();

        // ── Répartition par plan ──────────────────────────────────────────────
        $tenantsByPlan = $tQ()->select('plan', DB::raw('count(*) as count'))
            ->groupBy('plan')->get();

        // ── Répartition par pack (actifs) ─────────────────────────────────────
        $tenantsByPack = $tQ()->where('is_active', true)
            ->select('pack_id', DB::raw('count(*) as count'))
            ->groupBy('pack_id')
            ->get()
            ->map(function ($row) {
                $pack = \App\Models\Pack::find($row->pack_id);
                return [
                    'name'  => $pack?->name ?? 'Sans pack',
                    'count' => (int) $row->count,
                ];
            });

        // ── Taux de collecte de l'année ───────────────────────────────────────
        $maxBillableMonth = ($year < $nowYear) ? 12 : $nowMon;
        $expectedPayments = 0;
        $tenants = $tQ()->where('is_active', true)->get();
        foreach ($tenants as $t) {
            $startYear  = (int) $t->created_at->format('Y');
            $startMonth = (int) $t->created_at->format('n');
            if ($year < $startYear) continue;
            $from = ($year === $startYear) ? $startMonth : 1;
            $expectedPayments += max(0, $maxBillableMonth - $from + 1);
        }
        $paidPayments = $scopeP(SubscriptionPayment::where('status', 'paid'))
            ->where('year', $year)->count();
        $collectionRate = $expectedPayments > 0
            ? round(($paidPayments / $expectedPayments) * 100, 1)
            : 0;

        // ── MONITORING ────────────────────────────────────────────────────────
        $moduleMapping = [
            'Order'         => 'Ventes & POS',
            'Production'    => 'Production (BOM)',
            'Bom'           => 'Production (BOM)',
            'Product'       => 'Gestion Stock',
            'Category'      => 'Gestion Stock',
            'Expense'       => 'Finance & Dépenses',
            'Customer'      => 'CRM / Clients',
            'Supplier'      => 'Achats / Fournisseurs',
            'PurchaseOrder' => 'Achats / Fournisseurs',
        ];

        $moduleUsage = \App\Models\ActivityLog::select('subject_type', DB::raw('count(*) as count'))
            ->whereNotNull('subject_type')
            ->groupBy('subject_type')
            ->orderBy('count', 'desc')
            ->get()
            ->map(fn ($item) => [
                'name'  => $moduleMapping[class_basename($item->subject_type)] ?? class_basename($item->subject_type),
                'count' => (int) $item->count,
            ])
            ->groupBy('name')
            ->map(fn ($g) => ['name' => $g[0]['name'], 'count' => $g->sum('count')])
            ->values()->sortByDesc('count')->take(8);

        $activeUsers24h = \App\Models\User::where('last_login_at', '>=', now()->subDay())->count();
        $totalUsers     = \App\Models\User::count();

        $loginActivity = \App\Models\ActivityLog::select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('count(*) as count')
            )
            ->where('action', 'login')
            ->where('created_at', '>=', now()->subDays(30))
            ->groupBy('date')
            ->orderBy('date', 'asc')
            ->get();

        $totalLogins = \App\Models\ActivityLog::where('action', 'login')->count();

        // Nouveaux espaces par mois (année courante)
        $newTenantsByMonth = $tQ()->selectRaw('MONTH(created_at) as month, COUNT(*) as count')
            ->whereYear('created_at', $year)
            ->groupBy('month')
            ->pluck('count', 'month');
        $growthByMonth = collect(range(1, 12))->map(fn ($m) => [
            'month' => $m,
            'count' => (int) ($newTenantsByMonth[$m] ?? 0),
        ]);

        // ── CA par pays (filtrable par plage de mois : ?month_from=1&month_to=6) ──
        $monthFrom = max(1, min(12, (int) request('month_from', 1)));
        $monthTo   = max($monthFrom, min(12, (int) request('month_to', 12)));

        $revenueByCountry = SubscriptionPayment::selectRaw(
                "JSON_UNQUOTE(JSON_EXTRACT(tenants.settings, '$.country')) as country,
                 SUM(CASE WHEN subscription_payments.status = 'paid' THEN subscription_payments.amount ELSE 0 END) as paid,
                 SUM(CASE WHEN subscription_payments.status = 'overdue' THEN subscription_payments.amount ELSE 0 END) as overdue,
                 COUNT(DISTINCT subscription_payments.tenant_id) as clients"
            )
            ->join('tenants', 'subscription_payments.tenant_id', '=', 'tenants.id')
            ->where('subscription_payments.year', $year)
            ->whereBetween('subscription_payments.month', [$monthFrom, $monthTo])
            ->when($ids !== null, fn ($q) => $q->whereIn('tenants.id', $ids))
            ->groupBy('country')
            ->get()
            ->map(fn ($row) => [
                'country' => $row->country ?: 'Inconnu',
                'paid'    => (float) $row->paid,
                'overdue' => (float) $row->overdue,
                'clients' => (int) $row->clients,
            ]);

        $tenantsByCountry = $tQ()
            ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(settings, '$.country')) as country, COUNT(*) as count")
            ->groupBy('country')
            ->get()
            ->map(fn ($row) => ['country' => $row->country ?: 'Inconnu', 'count' => (int) $row->count]);

        return response()->json([
            'success' => true,
            'year'    => $year,
            'stats'   => [
                // Revenue
                'total_revenue'    => (float) $totalRevenue,
                'overdue_revenue'  => (float) $overdueRevenue,
                'revenue_year'     => (float) $revenueYear,
                'revenue_month'    => (float) $revenueMonth,
                'revenue_by_month' => $revenueByMonth,
                'revenue_by_pack'  => $revenueByPack->values(),
                'revenue_by_country'  => $revenueByCountry->values(),
                'revenue_country_period' => ['from' => $monthFrom, 'to' => $monthTo],
                'tenants_by_country'  => $tenantsByCountry->values(),
                'collection_rate'  => $collectionRate,
                // Espaces
                'active_count'     => $activeCount,
                'pending_count'    => $pendingCount,
                'new_this_month'   => $newThisMonth,
                'total_users'      => $totalUsers,
                'tenants_by_plan'  => $tenantsByPlan,
                'tenants_by_pack'  => $tenantsByPack->values(),
                'growth_by_month'  => $growthByMonth,
                // Monitoring
                'module_usage'     => $moduleUsage->values(),
                'active_users_24h' => $activeUsers24h,
                'login_activity'   => $loginActivity,
                'total_logins'     => $totalLogins,
            ],
        ]);
    }

    /**
     * Get all tenants with a summary of their subscription status.
     * Accepts: ?year=2026 &pack_id=2 &status=active|pending
     */
    public function tracking(): JsonResponse
    {
        $year   = (int) request('year', date('Y'));
        $packId = request('pack_id');
        $status = request('status'); // 'active' | 'pending' | null = all

        $ids = $this->visibleTenantIds();
        $query = Tenant::with(['owner', 'pack', 'payments' => function ($q) use ($year) {
            $q->where('year', $year);
        }])
            ->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))
            ->latest();

        if ($packId) {
            $query->where('pack_id', $packId);
        }
        if ($status === 'active') {
            $query->where('is_active', true);
        } elseif ($status === 'pending') {
            $query->where('is_active', false);
        }

        $tenants = $query->get();

        return response()->json([
            'success' => true,
            'year'    => $year,
            'tenants' => $tenants,
        ]);
    }

    /**
     * Get detailed payment history for a specific tenant.
     */
    public function history(Tenant $tenant): JsonResponse
    {
        $this->assertCanManageTenant(request()->user(), $tenant);
        $payments = $tenant->payments()
            ->orderBy('year', 'desc')
            ->orderBy('month', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'tenant'   => $tenant->load(['owner', 'pack']),
            'payments' => $payments,
        ]);
    }

    /**
     * Record a payment for a specific month/year.
     */
    public function recordPayment(Request $request, Tenant $tenant): JsonResponse
    {
        $this->assertCanManageTenant($request->user(), $tenant);
        $request->validate([
            'month'  => 'required|integer|min:1|max:12',
            'year'   => 'required|integer|min:2020|max:2099',
            'amount' => 'required|numeric|min:0',
            'status' => 'required|string|in:paid,pending,overdue',
        ]);

        $reqYear    = (int) $request->year;
        $reqMonth   = (int) $request->month;
        $startYear  = (int) $tenant->created_at->format('Y');
        $startMonth = (int) $tenant->created_at->format('n');
        $nowYear    = (int) now()->format('Y');
        $nowMonth   = (int) now()->format('n');

        // Refuser les mois antérieurs au début de l'abonnement
        $beforeStart = $reqYear < $startYear
            || ($reqYear === $startYear && $reqMonth < $startMonth);

        if ($beforeStart) {
            $monthName = \Carbon\Carbon::createFromDate($startYear, $startMonth, 1)
                ->translatedFormat('F Y');
            return response()->json([
                'success' => false,
                'message' => "Impossible d'enregistrer un paiement avant le début de l'abonnement (démarré en {$monthName}).",
            ], 422);
        }

        // Refuser les mois futurs
        $isFuture = $reqYear > $nowYear
            || ($reqYear === $nowYear && $reqMonth > $nowMonth);

        if ($isFuture) {
            return response()->json([
                'success' => false,
                'message' => "Impossible d'enregistrer un paiement pour un mois futur.",
            ], 422);
        }

        $payment = SubscriptionPayment::updateOrCreate(
            [
                'tenant_id' => $tenant->id,
                'month'     => $request->month,
                'year'      => $request->year,
            ],
            [
                'amount'         => $request->amount,
                'status'         => $request->status,
                'paid_at'        => $request->status === 'paid' ? now() : null,
                'payment_method' => $request->payment_method ?? 'manual',
                'notes'          => $request->notes,
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Paiement enregistré.',
            'payment' => $payment,
        ]);
    }

    /**
     * Définir / prolonger / terminer la période d'essai d'un espace.
     * Body : { trial_days: 14 }  OU  { trial_ends_at: '2026-07-01' }  OU  { end_trial: true }
     */
    public function setTrial(Request $request, Tenant $tenant): JsonResponse
    {
        $this->assertCanManageTenant($request->user(), $tenant);
        $request->validate([
            'trial_days'    => 'nullable|integer|min:1|max:365',
            'trial_ends_at' => 'nullable|date',
            'end_trial'     => 'nullable|boolean',
        ]);

        if ($request->boolean('end_trial')) {
            $tenant->update(['trial_ends_at' => now()]);
            return response()->json([
                'success' => true,
                'message' => "Période d'essai terminée pour {$tenant->name}.",
                'tenant'  => $tenant->fresh(),
            ]);
        }

        if ($request->filled('trial_days')) {
            $tenant->update(['trial_ends_at' => now()->addDays((int) $request->trial_days)->endOfDay()]);
        } elseif ($request->filled('trial_ends_at')) {
            $tenant->update(['trial_ends_at' => \Carbon\Carbon::parse($request->trial_ends_at)->endOfDay()]);
        } else {
            return response()->json([
                'success' => false,
                'message' => 'Indique trial_days, trial_ends_at ou end_trial.',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => "Période d'essai mise à jour : jusqu'au " . $tenant->fresh()->trial_ends_at->format('d/m/Y') . '.',
            'tenant'  => $tenant->fresh(),
        ]);
    }

    /**
     * Personnaliser le prix de l'abonnement (remise manuelle) et/ou la date d'expiration.
     * Body : { custom_price: 25000 }  (null pour revenir au prix du pack)
     *        { plan_expires_at: '2026-12-31' }
     */
    public function setPricing(Request $request, Tenant $tenant): JsonResponse
    {
        $this->assertCanManageTenant($request->user(), $tenant);
        $request->validate([
            'custom_price'    => 'nullable|numeric|min:0',
            'plan_expires_at' => 'nullable|date',
        ]);

        $updates = [];

        if ($request->exists('custom_price')) {
            $updates['custom_price'] = $request->custom_price; // null = reset au prix du pack
        }

        if ($request->filled('plan_expires_at')) {
            $updates['plan_expires_at'] = \Carbon\Carbon::parse($request->plan_expires_at)->endOfDay();
        }

        if (empty($updates)) {
            return response()->json([
                'success' => false,
                'message' => 'Aucune modification fournie.',
            ], 422);
        }

        $tenant->update($updates);
        $tenant = $tenant->fresh()->load('pack');

        return response()->json([
            'success' => true,
            'message' => 'Abonnement mis à jour.',
            'tenant'  => $tenant,
            'effective_price' => $tenant->getEffectivePrice(),
        ]);
    }

    /**
     * Liste des demandes de changement de plan.
     * ?status=pending|approved|rejected (défaut : pending)
     */
    public function planRequests(Request $request): JsonResponse
    {
        $status = $request->get('status', 'pending');

        $query = \App\Models\PlanChangeRequest::with([
            'tenant:id,name,pack_id',
            'requester:id,name,email',
            'currentPack:id,name,price',
            'requestedPack:id,name,price',
        ])->latest();

        if (in_array($status, ['pending', 'approved', 'rejected'], true)) {
            $query->where('status', $status);
        }

        $ids = $this->visibleTenantIds();
        if ($ids !== null) {
            $query->whereIn('tenant_id', $ids);
        }

        return response()->json([
            'success'  => true,
            'requests' => $query->get(),
        ]);
    }

    /**
     * Approuver ou rejeter une demande de changement de plan.
     * Body : { decision: 'approved'|'rejected', admin_note?: string }
     * À l'approbation, le pack du tenant est changé et les modules du pack appliqués.
     */
    public function decidePlanRequest(Request $request, \App\Models\PlanChangeRequest $planRequest): JsonResponse
    {
        $request->validate([
            'decision'   => 'required|in:approved,rejected',
            'admin_note' => 'nullable|string|max:1000',
        ]);

        $this->assertCanManageTenant($request->user(), $planRequest->tenant);

        if ($planRequest->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Cette demande a déjà été traitée.',
            ], 422);
        }

        $planRequest->update([
            'status'     => $request->decision,
            'admin_note' => $request->admin_note,
            'decided_at' => now(),
        ]);

        if ($request->decision === 'approved') {
            $tenant = $planRequest->tenant;
            $pack   = $planRequest->requestedPack;

            $updates = ['pack_id' => $pack->id];

            // Appliquer les modules du nouveau pack
            if (!empty($pack->features)) {
                $settings = $tenant->settings ?? [];
                $settings['enabled_modules'] = $pack->features;
                $updates['settings'] = $settings;
            }

            $tenant->update($updates);
        }

        return response()->json([
            'success' => true,
            'message' => $request->decision === 'approved'
                ? 'Demande approuvée — le pack a été changé.'
                : 'Demande rejetée.',
            'request' => $planRequest->fresh()->load(['tenant', 'requestedPack']),
        ]);
    }
}
