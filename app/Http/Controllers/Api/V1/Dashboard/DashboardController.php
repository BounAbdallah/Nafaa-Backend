<?php

namespace App\Http\Controllers\Api\V1\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DashboardController extends Controller
{
    public function index(\Illuminate\Http\Request $request): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $user    = auth()->user();
        $isAdmin = $user->isTenantAdmin();
        $userId  = $isAdmin ? null : $user->id;
        $now = Carbon::now();
        $startOfMonth = $now->copy()->startOfMonth();
        $startOfLastMonth = $now->copy()->subMonth()->startOfMonth();
        $endOfLastMonth = $now->copy()->subMonth()->endOfMonth();

        $startDate = $request->query('start_date') ? Carbon::parse($request->query('start_date'))->startOfDay() : $startOfMonth;
        $endDate = $request->query('end_date') ? Carbon::parse($request->query('end_date'))->endOfDay() : $now;
        $isCustomRange = $request->has('start_date');

        // 1. Revenus pour la période
        $revenueMonth = Order::where('tenant_id', $tenantId)
            ->where('status', '!=', 'cancelled')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->when($userId, fn($q) => $q->where('user_id', $userId))
            ->sum('total_amount');

        // 1b. Revenus mois dernier (pour comparaison, uniquement si période par défaut)
        $revenueLastMonth = 0;
        $revenueGrowth = 0;
        if (!$isCustomRange) {
            $revenueLastMonth = Order::where('tenant_id', $tenantId)
                ->where('status', '!=', 'cancelled')
                ->whereBetween('created_at', [$startOfLastMonth, $endOfLastMonth])
                ->when($userId, fn($q) => $q->where('user_id', $userId))
                ->sum('total_amount');

            $revenueGrowth = $revenueLastMonth > 0
                ? round((($revenueMonth - $revenueLastMonth) / $revenueLastMonth) * 100, 1)
                : 0;
        }

        // 2. Commandes pour la période
        $ordersCountMonth = Order::where('tenant_id', $tenantId)
            ->where('status', '!=', 'cancelled')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->when($userId, fn($q) => $q->where('user_id', $userId))
            ->count();

        // 2b. Commandes mois dernier
        $ordersGrowth = 0;
        if (!$isCustomRange) {
            $ordersCountLastMonth = Order::where('tenant_id', $tenantId)
                ->where('status', '!=', 'cancelled')
                ->whereBetween('created_at', [$startOfLastMonth, $endOfLastMonth])
                ->when($userId, fn($q) => $q->where('user_id', $userId))
                ->count();

            $ordersGrowth = $ordersCountLastMonth > 0
                ? round((($ordersCountMonth - $ordersCountLastMonth) / $ordersCountLastMonth) * 100, 1)
                : 0;
        }

        // 3. Commandes actives (en attente de paiement ou de traitement)
        // Note: Dans notre cas actuel, la plupart des ventes POS sont directes.
        $activeOrdersCount = Order::where('tenant_id', $tenantId)
            ->where('status', 'pending')
            ->when($userId, fn($q) => $q->where('user_id', $userId))
            ->count();

        // 4. Produits en stock bas
        $lowStockCount = $isAdmin ? Product::where('tenant_id', $tenantId)
            ->whereIn('type', ['product', 'material'])
            ->whereColumn('stock_quantity', '<=', 'stock_alert')
            ->count() : null;

        // 5. Total Clients
        $totalCustomers = $isAdmin ? Customer::where('tenant_id', $tenantId)->count() : null;
        $totalProducts  = $isAdmin ? Product::where('tenant_id', $tenantId)->count() : null;

        // 6. Commandes récentes
        $recentOrders = Order::with('customer:id,name')
            ->where('tenant_id', $tenantId)
            ->when($userId, fn($q) => $q->where('user_id', $userId))
            ->latest()
            ->limit(5)
            ->get()
            ->map(function ($order) {
                return [
                    'id' => $order->id,
                    'reference' => $order->reference,
                    'customer_name' => $order->customer?->name ?? 'Client de passage',
                    'total_amount' => $order->total_amount,
                    'status' => $order->status,
                    'created_at' => $order->created_at->toISOString(),
                ];
            });

        // 7. Top Produits vendus pour la période
        $topProducts = DB::table('order_items')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->join('products', 'order_items.product_id', '=', 'products.id')
            ->where('orders.tenant_id', $tenantId)
            ->where('orders.status', '!=', 'cancelled')
            ->whereNull('orders.deleted_at')
            ->whereBetween('orders.created_at', [$startDate, $endDate])
            ->when($userId, fn($q) => $q->where('orders.user_id', $userId))
            ->select('products.name', DB::raw('SUM(order_items.quantity) as total_qty'), DB::raw('SUM(order_items.subtotal) as total_revenue'))
            ->groupBy('products.id', 'products.name')
            ->orderByDesc('total_qty')
            ->limit(5)
            ->get();

        // 8. Historique des ventes pour la période (max 30 jours si custom, sinon 15)
        $historyStart = $isCustomRange ? $startDate : $now->copy()->subDays(14)->startOfDay();
        $salesHistory = DB::table('orders')
            ->where('tenant_id', $tenantId)
            ->where('status', '!=', 'cancelled')
            ->whereNull('deleted_at')
            ->whereBetween('created_at', [$historyStart, $endDate])
            ->when($userId, fn($q) => $q->where('user_id', $userId))
            ->select(DB::raw('DATE(created_at) as date'), DB::raw('SUM(total_amount) as total'), DB::raw('COUNT(*) as count'))
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(function ($item) {
                return [
                    'date' => Carbon::parse($item->date)->format('d/m'),
                    'total' => (float) $item->total,
                    'count' => (int) $item->count,
                ];
            });

        // 9. Répartition des ventes par catégorie pour la période
        // Jointure LEFT JOIN sur categories pour récupérer le nom réel
        $salesByCategory = DB::table('order_items')
            ->join('orders',    'order_items.order_id',   '=', 'orders.id')
            ->join('products',  'order_items.product_id', '=', 'products.id')
            ->leftJoin('categories', 'products.category_id', '=', 'categories.id')
            ->where('orders.tenant_id', $tenantId)
            ->where('orders.status', '!=', 'cancelled')
            ->whereNull('orders.deleted_at')
            ->whereBetween('orders.created_at', [$startDate, $endDate])
            ->when($userId, fn($q) => $q->where('orders.user_id', $userId))
            ->select(
                DB::raw('COALESCE(categories.name, products.category, \'Sans catégorie\') as category'),
                DB::raw('SUM(order_items.subtotal) as value')
            )
            ->groupBy('categories.id', 'categories.name', 'products.category')
            ->orderByDesc('value')
            ->get()
            ->map(fn($item) => [
                'category' => $item->category,
                'value'    => (float) $item->value,
            ]);

        // 10. Top Clients pour la période
        $topCustomers = DB::table('orders')
            ->join('customers', 'orders.customer_id', '=', 'customers.id')
            ->where('orders.tenant_id', $tenantId)
            ->where('orders.status', '!=', 'cancelled')
            ->whereNull('orders.deleted_at')
            ->whereBetween('orders.created_at', [$startDate, $endDate])
            ->when($userId, fn($q) => $q->where('orders.user_id', $userId))
            ->select('customers.name', DB::raw('SUM(orders.total_amount) as total'), DB::raw('COUNT(orders.id) as count'))
            ->groupBy('customers.id', 'customers.name')
            ->orderByDesc('total')
            ->limit(10)
            ->get();

        // 11. Ventes par Utilisateur (Agent) pour la période
        $salesByUser = DB::table('orders')
            ->join('users', 'orders.user_id', '=', 'users.id')
            ->where('orders.tenant_id', $tenantId)
            ->where('orders.status', '!=', 'cancelled')
            ->whereNull('orders.deleted_at')
            ->whereBetween('orders.created_at', [$startDate, $endDate])
            ->when($userId, fn($q) => $q->where('orders.user_id', $userId))
            ->select('users.name', DB::raw('SUM(orders.total_amount) as total'))
            ->groupBy('users.id', 'users.name')
            ->orderByDesc('total')
            ->limit(5)
            ->get();

        // 12. Répartition par Méthode de Paiement pour la période
        $paymentMethodsDist = DB::table('orders')
            ->where('tenant_id', $tenantId)
            ->where('status', '!=', 'cancelled')
            ->whereNull('deleted_at')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->when($userId, fn($q) => $q->where('user_id', $userId))
            ->select('payment_method', DB::raw('COUNT(*) as count'), DB::raw('SUM(total_amount) as total'))
            ->groupBy('payment_method')
            ->get();

        return response()->json([
            'stats' => [
                'is_admin' => $isAdmin,
                'revenue_month' => (float) $revenueMonth,
                'revenue_growth' => $revenueGrowth,
                'orders_count_month' => $ordersCountMonth,
                'orders_growth' => $ordersGrowth,
                'active_orders_count' => $activeOrdersCount,
                'low_stock_count' => $lowStockCount,
                'total_customers' => $totalCustomers,
                'total_products' => $totalProducts,
            ],
            'recent_orders' => $recentOrders,
            'top_products' => $topProducts,
            'sales_history' => $salesHistory,
            'sales_by_category' => $salesByCategory,
            'top_customers' => $topCustomers,
            'sales_by_user' => $salesByUser,
            'payment_methods_dist' => $paymentMethodsDist,
        ]);
    }
}
