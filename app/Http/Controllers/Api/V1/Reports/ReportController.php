<?php

namespace App\Http\Controllers\Api\V1\Reports;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Expense;
use App\Models\Product;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

use App\Models\Production;
use Barryvdh\DomPDF\Facade\Pdf;

class ReportController extends Controller
{
    public function exportExpensesPdf(Request $request)
    {
        $startDate = $request->query('start_date');
        $endDate = $request->query('end_date');
        $tenantId = $request->user()->tenant_id;

        $query = Expense::where('tenant_id', $tenantId);

        if ($startDate && $endDate) {
            $query->whereBetween('expense_date', [$startDate, $endDate]);
        }

        $expenses = $query->orderBy('expense_date', 'desc')->get();
        $total = $expenses->sum('amount');

        $pdf = Pdf::loadView('pdf.expenses', [
            'expenses' => $expenses,
            'total' => $total,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'tenant' => $request->user()->tenant
        ]);

        return $pdf->download('rapport-depenses.pdf');
    }

    public function exportProductionPdf(Request $request)
    {
        $startDate = $request->query('start_date');
        $endDate = $request->query('end_date');
        $tenantId = $request->user()->tenant_id;

        $query = Production::where('tenant_id', $tenantId);

        if ($startDate && $endDate) {
            if (strlen($startDate) > 10) {
                $query->whereBetween('created_at', [$startDate, $endDate]);
            } else {
                $query->whereBetween('created_at', [
                    Carbon::parse($startDate)->startOfDay(),
                    Carbon::parse($endDate)->endOfDay()
                ]);
            }
        }

        $productions = $query->with(['product', 'user'])->orderBy('created_at', 'desc')->get();

        $pdf = Pdf::loadView('pdf.production', [
            'productions' => $productions,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'tenant' => $request->user()->tenant
        ]);

        return $pdf->download('rapport-production.pdf');
    }
    /**
     * Clôture de journée : Ventes, paiements, etc.
     */
    public function dailySummary(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $startDate = $request->query('start_date', Carbon::today()->toDateString());
        $endDate = $request->query('end_date', Carbon::today()->toDateString());
        
        $start = Carbon::parse($startDate)->startOfDay();
        $end = Carbon::parse($endDate)->endOfDay();

        $orders = Order::where('tenant_id', $tenantId)
            ->where('status', '!=', 'cancelled')
            ->whereBetween('created_at', [$start, $end])
            ->get();

        $totalSales = $orders->sum('total_amount');
        $totalDiscount = $orders->sum('discount_amount');
        $ordersCount = $orders->count();

        $paymentMethods = $orders->groupBy('payment_method')->map(function ($group) {
            return [
                'count' => $group->count(),
                'total' => $group->sum('total_amount')
            ];
        });

        // Top products sold today
        $topProducts = DB::table('order_items')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->join('products', 'order_items.product_id', '=', 'products.id')
            ->where('orders.tenant_id', $tenantId)
            ->where('orders.status', '!=', 'cancelled')
            ->whereBetween('orders.created_at', [$start, $end])
            ->select('products.name', DB::raw('SUM(order_items.quantity) as total_qty'), DB::raw('SUM(order_items.subtotal) as total_revenue'))
            ->groupBy('products.id', 'products.name')
            ->orderByDesc('total_qty')
            ->limit(5)
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
                'total_sales' => $totalSales,
                'total_discount' => $totalDiscount,
                'orders_count' => $ordersCount,
                'payment_methods' => $paymentMethods,
                'top_products' => $topProducts,
            ]
        ]);
    }

    /**
     * Rapport Financier : Revenus vs Dépenses
     */
    public function financialSummary(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $period = $request->query('period', 'month'); // custom, week, month, year
        
        $now = Carbon::now();
        if ($period === 'custom') {
            $startDate = $request->query('start_date', $now->startOfMonth()->toDateString());
            $endDate = $request->query('end_date', $now->endOfMonth()->toDateString());
        } elseif ($period === 'week') {
            $startDate = $now->startOfWeek()->toDateString();
            $endDate = $now->endOfWeek()->toDateString();
        } elseif ($period === 'year') {
            $startDate = $now->startOfYear()->toDateString();
            $endDate = $now->endOfYear()->toDateString();
        } else {
            $startDate = $now->startOfMonth()->toDateString();
            $endDate = $now->endOfMonth()->toDateString();
        }

        // Revenues
        $revenues = Order::where('tenant_id', $tenantId)
            ->where('status', '!=', 'cancelled')
            ->whereBetween('created_at', [Carbon::parse($startDate)->startOfDay(), Carbon::parse($endDate)->endOfDay()])
            ->sum('total_amount');

        // Expenses
        $expenses = Expense::where('tenant_id', $tenantId)
            ->whereBetween('expense_date', [$startDate, $endDate])
            ->sum('amount');

        // Net Profit
        $netProfit = $revenues - $expenses;

        // Daily breakdown for charts
        $dailyRevenues = DB::table('orders')
            ->where('tenant_id', $tenantId)
            ->where('status', '!=', 'cancelled')
            ->whereBetween('created_at', [Carbon::parse($startDate)->startOfDay(), Carbon::parse($endDate)->endOfDay()])
            ->select(DB::raw('DATE(created_at) as date'), DB::raw('SUM(total_amount) as total'))
            ->groupBy('date')
            ->get()
            ->keyBy('date');

        $dailyExpenses = DB::table('expenses')
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->whereBetween('expense_date', [$startDate, $endDate])
            ->select('expense_date as date', DB::raw('SUM(amount) as total'))
            ->groupBy('date')
            ->get()
            ->keyBy('date');

        $chartData = [];
        $currentDate = Carbon::parse($startDate);
        $end = Carbon::parse($endDate);

        while ($currentDate->lte($end)) {
            $dateStr = $currentDate->toDateString();
            $chartData[] = [
                'date' => $currentDate->format('d/m'),
                'revenue' => $dailyRevenues->has($dateStr) ? (float) $dailyRevenues->get($dateStr)->total : 0,
                'expense' => $dailyExpenses->has($dateStr) ? (float) $dailyExpenses->get($dateStr)->total : 0,
            ];
            $currentDate->addDay();
        }

        return response()->json([
            'success' => true,
            'data' => [
                'period' => $period,
                'revenues' => $revenues,
                'expenses' => $expenses,
                'net_profit' => $netProfit,
                'chart_data' => $chartData,
            ]
        ]);
    }

    /**
     * Rapport de Valeur du Stock
     */
    public function inventoryValuation(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $perPage = $request->query('per_page', 15);

        // Calculate Global Totals first
        $allProducts = Product::where('tenant_id', $tenantId)
            ->where('stock_quantity', '>', 0)
            ->get(['type', 'stock_quantity', 'selling_price', 'cost_price', 'id']);

        $totalValuationSelling = $allProducts->sum(fn($p) => $p->stock_quantity * $p->selling_price);
        $totalValuationCost = $allProducts->sum(fn($p) => $p->stock_quantity * ($p->cost_price ?? 0));
        $materialValuation = $allProducts->where('type', 'material')->sum(fn($p) => $p->stock_quantity * ($p->cost_price ?? 0));
        $productValuation = $allProducts->where('type', '!=', 'material')->sum(fn($p) => $p->stock_quantity * ($p->cost_price ?? 0));

        // BOM Stock Valuation (Global)
        $bomProductIds = \App\Models\Bom::where('tenant_id', $tenantId)->pluck('product_id')->toArray();
        $bomStockValuation = $allProducts->whereIn('id', $bomProductIds)->sum(fn($p) => $p->stock_quantity * ($p->cost_price ?? 0));

        // Paginated Details
        $paginated = Product::where('tenant_id', $tenantId)
            ->where('stock_quantity', '>', 0)
            ->orderByRaw('stock_quantity * cost_price DESC')
            ->paginate($perPage);

        $details = collect($paginated->items())->map(function ($item) {
            return [
                'id' => $item->id,
                'name' => $item->name,
                'type' => $item->type,
                'category' => $item->category,
                'quantity' => $item->stock_quantity,
                'unit' => $item->unit,
                'cost_price' => $item->cost_price,
                'selling_price' => $item->selling_price,
                'valuation_cost' => $item->stock_quantity * ($item->cost_price ?? 0),
                'valuation_selling' => $item->stock_quantity * $item->selling_price,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => [
                'summary' => [
                    'total_valuation_selling' => $totalValuationSelling,
                    'total_valuation_cost' => $totalValuationCost,
                    'potential_profit' => $totalValuationSelling - $totalValuationCost,
                    'materials_valuation' => $materialValuation,
                    'products_valuation' => $productValuation,
                    'bom_stock_valuation' => $bomStockValuation,
                ],
                'details' => $details,
                'meta' => [
                    'current_page' => $paginated->currentPage(),
                    'last_page' => $paginated->lastPage(),
                    'total' => $paginated->total(),
                    'per_page' => $paginated->perPage(),
                ]
            ]
        ]);
    }

    /**
     * Rapport de Performance Équipe
     */
    public function teamPerformance(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $startDate = $request->query('start_date', Carbon::now()->startOfMonth()->toDateString());
        $endDate = $request->query('end_date', Carbon::now()->endOfMonth()->toDateString());

        $start = Carbon::parse($startDate)->startOfDay();
        $end = Carbon::parse($endDate)->endOfDay();

        $performance = DB::table('orders')
            ->join('users', 'orders.user_id', '=', 'users.id')
            ->where('orders.tenant_id', $tenantId)
            ->where('orders.status', '!=', 'cancelled')
            ->whereBetween('orders.created_at', [$start, $end])
            ->select('users.id', 'users.name', DB::raw('SUM(orders.total_amount) as total_revenue'), DB::raw('COUNT(orders.id) as total_orders'))
            ->groupBy('users.id', 'users.name')
            ->orderByDesc('total_revenue')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
                'performance' => $performance,
            ]
        ]);
    }

    /**
     * Rapport Analyse Clients
     */
    public function customerAnalytics(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $startDate = $request->query('start_date', Carbon::now()->startOfMonth()->toDateString());
        $endDate = $request->query('end_date', Carbon::now()->endOfMonth()->toDateString());

        $start = Carbon::parse($startDate)->startOfDay();
        $end = Carbon::parse($endDate)->endOfDay();

        $topCustomers = DB::table('orders')
            ->join('customers', 'orders.customer_id', '=', 'customers.id')
            ->where('orders.tenant_id', $tenantId)
            ->where('orders.status', '!=', 'cancelled')
            ->whereBetween('orders.created_at', [$start, $end])
            ->select('customers.id', 'customers.name', 'customers.phone', DB::raw('SUM(orders.total_amount) as total_spent'), DB::raw('COUNT(orders.id) as total_orders'))
            ->groupBy('customers.id', 'customers.name', 'customers.phone')
            ->orderByDesc('total_spent')
            ->limit(20)
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
                'top_customers' => $topCustomers,
            ]
        ]);
    }
}
