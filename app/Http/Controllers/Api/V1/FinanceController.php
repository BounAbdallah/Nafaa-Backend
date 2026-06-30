<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Tableau de bord financier (rôle comptable + admin).
 * Synthèse de l'argent qui entre et sort, sur une période.
 * Gated par la fonctionnalité « accounting ».
 */
class FinanceController extends Controller
{
    private function assertEnabled(): void
    {
        abort_unless(Auth::user()->tenant?->hasFeature('accounting'), 403,
            'La comptabilité n\'est pas activée sur votre abonnement.');
        abort_unless(Auth::user()->canDo('accounting', 'view'), 403, 'Permission refusée.');
    }

    public function summary(Request $request): JsonResponse
    {
        $this->assertEnabled();
        $tenantId = Auth::user()->tenant_id;

        $from = $request->filled('from')
            ? \Carbon\Carbon::parse($request->from)->startOfDay()
            : now()->startOfMonth();
        $to = $request->filled('to')
            ? \Carbon\Carbon::parse($request->to)->endOfDay()
            : now()->endOfDay();

        // ── Entrées : ventes encaissées (paid_amount des commandes non annulées) ──
        $salesPaid = (float) DB::table('orders')
            ->where('tenant_id', $tenantId)
            ->where('status', '!=', 'cancelled')
            ->whereNull('deleted_at')
            ->whereBetween('created_at', [$from, $to])
            ->sum('paid_amount');

        // Remboursements de dettes encaissés sur la période (argent qui rentre)
        $repayments = (float) DB::table('customer_account_entries')
            ->where('tenant_id', $tenantId)
            ->where('type', 'repayment')
            ->whereBetween('created_at', [$from, $to])
            ->sum('amount');

        // Dépôts d'avance reçus (argent qui rentre)
        $deposits = (float) DB::table('customer_account_entries')
            ->where('tenant_id', $tenantId)
            ->where('type', 'deposit')
            ->whereBetween('created_at', [$from, $to])
            ->sum('amount');

        // ── Sorties : dépenses ──
        $expenses = (float) DB::table('expenses')
            ->where('tenant_id', $tenantId)
            ->whereBetween('created_at', [$from, $to])
            ->sum('amount');

        $moneyIn  = $salesPaid + $repayments + $deposits;
        $net      = $moneyIn - $expenses;

        // ── Créances en cours (ce que les clients doivent, tous temps) ──
        $outstandingDebt = abs((float) DB::table('customers')
            ->where('tenant_id', $tenantId)
            ->where('account_balance', '<', 0)
            ->sum('account_balance'));

        // ── Dépenses par catégorie ──
        $byCategory = DB::table('expenses')
            ->where('tenant_id', $tenantId)
            ->whereBetween('created_at', [$from, $to])
            ->select('category', DB::raw('SUM(amount) as total'))
            ->groupBy('category')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($r) => ['category' => $r->category ?: 'Autre', 'total' => (float) $r->total]);

        // ── Évolution mensuelle (6 derniers mois) : entrées vs sorties ──
        $months = collect(range(5, 0))->map(function ($i) use ($tenantId) {
            $start = now()->startOfMonth()->subMonths($i);
            $end   = $start->copy()->endOfMonth();

            $in = (float) DB::table('orders')->where('tenant_id', $tenantId)
                ->where('status', '!=', 'cancelled')->whereNull('deleted_at')
                ->whereBetween('created_at', [$start, $end])->sum('paid_amount');
            $out = (float) DB::table('expenses')->where('tenant_id', $tenantId)
                ->whereBetween('created_at', [$start, $end])->sum('amount');

            return [
                'label'    => $start->translatedFormat('M'),
                'entrees'  => $in,
                'sorties'  => $out,
            ];
        });

        return response()->json([
            'success' => true,
            'data'    => [
                'period'           => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
                'sales_paid'       => $salesPaid,
                'repayments'       => $repayments,
                'deposits'         => $deposits,
                'money_in'         => $moneyIn,
                'expenses'         => $expenses,
                'net'              => $net,
                'outstanding_debt' => $outstandingDebt,
                'by_category'      => $byCategory,
                'monthly'          => $months,
            ],
        ]);
    }
}
