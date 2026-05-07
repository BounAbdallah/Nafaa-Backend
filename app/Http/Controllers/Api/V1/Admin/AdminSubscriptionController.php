<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionPayment;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminSubscriptionController extends Controller
{
    /**
     * Get inactive tenants requiring approval.
     */
    public function pendingApprovals(): JsonResponse
    {
        $tenants = Tenant::where('is_active', false)
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
        $tenant->loadMissing('pack');

        $updates = ['is_active' => true];

        // Apply pack modules automatically — super admin can still override later
        if ($tenant->pack && !empty($tenant->pack->features)) {
            $settings = $tenant->settings ?? [];
            $settings['enabled_modules'] = $tenant->pack->features;
            $updates['settings'] = $settings;
        }

        $tenant->update($updates);

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

        // ── Revenus tous temps ────────────────────────────────────────────────
        $totalRevenue   = SubscriptionPayment::where('status', 'paid')->sum('amount');
        $overdueRevenue = SubscriptionPayment::where('status', 'overdue')->sum('amount');

        // ── CA de l'année sélectionnée ────────────────────────────────────────
        $revenueYear = SubscriptionPayment::where('status', 'paid')
            ->where('year', $year)->sum('amount');

        // ── CA du mois courant ────────────────────────────────────────────────
        $revenueMonth = SubscriptionPayment::where('status', 'paid')
            ->where('year', $nowYear)->where('month', $nowMon)->sum('amount');

        // ── Revenus par mois pour l'année choisie (tous les 12 mois) ─────────
        $paidByMonth = SubscriptionPayment::selectRaw('month, SUM(amount) as total')
            ->where('year', $year)->where('status', 'paid')
            ->groupBy('month')->pluck('total', 'month');

        $overdueByMonth = SubscriptionPayment::selectRaw('month, SUM(amount) as total')
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
        $activeCount  = Tenant::where('is_active', true)->count();
        $pendingCount = Tenant::where('is_active', false)->count();
        $newThisMonth = Tenant::whereYear('created_at', $nowYear)
            ->whereMonth('created_at', $nowMon)->count();

        // ── Répartition par plan ──────────────────────────────────────────────
        $tenantsByPlan = Tenant::select('plan', DB::raw('count(*) as count'))
            ->groupBy('plan')->get();

        // ── Répartition par pack (actifs) ─────────────────────────────────────
        $tenantsByPack = Tenant::where('is_active', true)
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
        $tenants = Tenant::where('is_active', true)->get();
        foreach ($tenants as $t) {
            $startYear  = (int) $t->created_at->format('Y');
            $startMonth = (int) $t->created_at->format('n');
            if ($year < $startYear) continue;
            $from = ($year === $startYear) ? $startMonth : 1;
            $expectedPayments += max(0, $maxBillableMonth - $from + 1);
        }
        $paidPayments = SubscriptionPayment::where('status', 'paid')
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
        $newTenantsByMonth = Tenant::selectRaw('MONTH(created_at) as month, COUNT(*) as count')
            ->whereYear('created_at', $year)
            ->groupBy('month')
            ->pluck('count', 'month');
        $growthByMonth = collect(range(1, 12))->map(fn ($m) => [
            'month' => $m,
            'count' => (int) ($newTenantsByMonth[$m] ?? 0),
        ]);

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

        $query = Tenant::with(['owner', 'pack', 'payments' => function ($q) use ($year) {
            $q->where('year', $year);
        }])->latest();

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
}
