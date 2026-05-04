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
     */
    public function approve(Tenant $tenant): JsonResponse
    {
        $tenant->update(['is_active' => true]);

        return response()->json([
            'success' => true,
            'message' => "L'espace {$tenant->name} a été activé avec succès.",
        ]);
    }

    /**
     * Get revenue and subscription statistics.
     */
    public function stats(): JsonResponse
    {
        $totalRevenue = SubscriptionPayment::where('status', 'paid')->sum('amount');
        $overdueRevenue = SubscriptionPayment::where('status', 'overdue')->sum('amount');
        
        // Get both paid and overdue monthly revenue
        $monthlyRevenue = SubscriptionPayment::selectRaw('month, year, 
                SUM(CASE WHEN status = "paid" THEN amount ELSE 0 END) as paid,
                SUM(CASE WHEN status = "overdue" THEN amount ELSE 0 END) as overdue
            ')
            ->groupBy('month', 'year')
            ->orderBy('year', 'desc')
            ->orderBy('month', 'desc')
            ->take(12)
            ->get();

        $activeCount = Tenant::where('is_active', true)->count();
        $pendingCount = Tenant::where('is_active', false)->count();

        $tenantsByPlan = Tenant::select('plan', DB::raw('count(*) as count'))
            ->groupBy('plan')
            ->get();

        // Activity Monitoring — Mapped to user-friendly modules
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
            ->map(function($item) use ($moduleMapping) {
                $baseName = class_basename($item->subject_type);
                return [
                    'name'  => $moduleMapping[$baseName] ?? $baseName,
                    'count' => (int) $item->count
                ];
            })
            ->groupBy('name')
            ->map(fn($group) => ['name' => $group[0]['name'], 'count' => $group->sum('count')])
            ->values()
            ->sortByDesc('count')
            ->take(6);

        $activeUsers24h = \App\Models\User::where('last_login_at', '>=', now()->subDay())->count();

        // Login Activity (Last 14 days)
        $loginActivity = \App\Models\ActivityLog::select(DB::raw('DATE(created_at) as date'), DB::raw('count(*) as count'))
            ->where('action', 'login')
            ->where('created_at', '>=', now()->subDays(14))
            ->groupBy('date')
            ->orderBy('date', 'asc')
            ->get();

        $totalLogins = \App\Models\ActivityLog::where('action', 'login')->count();

        return response()->json([
            'success' => true,
            'stats' => [
                'total_revenue'    => $totalRevenue,
                'overdue_revenue'  => $overdueRevenue,
                'monthly_revenue'  => $monthlyRevenue,
                'active_count'     => $activeCount,
                'pending_count'    => $pendingCount,
                'tenants_by_plan'  => $tenantsByPlan,
                'module_usage'     => $moduleUsage,
                'active_users_24h' => $activeUsers24h,
                'login_activity'   => $loginActivity,
                'total_logins'     => $totalLogins,
            ],
        ]);
    }

    /**
     * Get all tenants with a summary of their subscription status.
     */
    public function tracking(): JsonResponse
    {
        $year = (int) request('year', date('Y'));

        $tenants = Tenant::with(['owner', 'pack', 'payments' => function($q) use ($year) {
            $q->where('year', $year);
        }])
        ->latest()
        ->get();

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
            'year'   => 'required|integer',
            'amount' => 'required|numeric|min:0',
            'status' => 'required|string|in:paid,pending,overdue',
        ]);

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
