<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrderController extends Controller
{
    public function index(Request $request)
    {
        $user     = Auth::user();
        $tenantId = $user->tenant_id;

        $query = Order::where('tenant_id', $tenantId)
            ->with(['customer', 'user'])
            ->latest();

        // Employees only see their own orders
        if (!$user->isTenantAdmin()) {
            $query->where('user_id', $user->id);
        }

        if ($request->search) {
            $query->where('reference', 'like', "%{$request->search}%");
        }

        if ($request->status) {
            $query->where('status', $request->status);
        }

        if ($request->filled('start_date')) {
            $query->whereDate('created_at', '>=', $request->start_date);
        }

        if ($request->filled('end_date')) {
            $query->whereDate('created_at', '<=', $request->end_date);
        }

        $orders = $query->paginate(min(100, $request->per_page ?? 15));

        return response()->json([
            'orders' => $orders->items(),
            'meta'   => [
                'current_page' => $orders->currentPage(),
                'last_page'    => $orders->lastPage(),
                'total'        => $orders->total(),
            ]
        ]);
    }

    public function store(Request $request)
    {
        abort_unless(Auth::user()->canDo('orders', 'create'), 403, 'Permission refusée : créer une commande.');
        $request->validate([
            'customer_id'    => 'nullable|exists:customers,id',
            'items'          => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity'   => 'required|numeric|min:0.001',
            'payments'       => 'nullable|array',
            'payments.*.method'  => 'required|string',
            'payments.*.amount'  => 'required|numeric|min:0',
            'payments.*.reference' => 'nullable|string',
            'payment_mode'   => 'nullable|in:cash,credit,deposit', // cash (défaut), crédit (ardoise), avance
            'due_date'       => 'nullable|date',                   // échéance du crédit (null = indéfinie)
            'discount_amount'=> 'nullable|numeric|min:0',
            'tax_amount'     => 'nullable|numeric|min:0',
            'notes'          => 'nullable|string',
        ]);

        $mode = $request->get('payment_mode', 'cash');

        // Crédit et avance exigent un client identifié
        if (in_array($mode, ['credit', 'deposit'], true) && ! $request->customer_id) {
            return response()->json([
                'message' => 'Un client doit être sélectionné pour une vente à crédit ou sur avance.',
            ], 422);
        }
        // Le mode comptant exige au moins un paiement
        if ($mode === 'cash' && empty($request->payments)) {
            return response()->json(['message' => 'Aucun paiement fourni.'], 422);
        }

        $tenantId = Auth::user()->tenant_id;
        $userId   = Auth::id();

        return DB::transaction(function () use ($request, $tenantId, $userId, $mode) {
            $subtotal = 0;
            $orderItemsData = [];

            foreach ($request->items as $item) {
                $product = Product::where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($item['product_id']);

                if ($product->stock_quantity < $item['quantity']) {
                    throw new \Exception("Stock insuffisant pour le produit : {$product->name}");
                }

                $itemSubtotal = $item['quantity'] * $product->selling_price;
                $subtotal += $itemSubtotal;

                $orderItemsData[] = [
                    'product_id'  => $product->id,
                    'description' => $product->name,
                    'quantity'    => $item['quantity'],
                    'unit_price'  => $product->selling_price,
                    'subtotal'    => $itemSubtotal,
                ];

                $product->decrement('stock_quantity', $item['quantity']);
            }

            $tax      = $request->tax_amount ?? 0;
            $discount = $request->discount_amount ?? 0;
            $total    = $subtotal + $tax - $discount;

            $payments  = $request->payments ?? [];
            $cashPaid  = collect($payments)->sum('amount'); // argent réel reçu maintenant

            $customer = $request->customer_id
                ? \App\Models\Customer::where('tenant_id', $tenantId)->lockForUpdate()->find($request->customer_id)
                : null;

            // ── Vente sur AVANCE : on pioche dans le dépôt du client ──
            if ($mode === 'deposit') {
                if (! $customer || $customer->deposit < $total) {
                    throw new \Exception("Avance insuffisante. Disponible : " . number_format($customer?->deposit ?? 0, 0, ',', ' ') . " FCFA.");
                }
                $paidAmount    = $total; // payé via l'avance
                $change        = 0;
                $paymentStatus = 'paid';
                $primaryMethod = 'avance';
            }
            // ── Vente à CRÉDIT : le reste devient une dette ──
            elseif ($mode === 'credit') {
                $paidAmount    = min($cashPaid, $total);
                $change        = 0;
                $paymentStatus = $paidAmount > 0 ? 'partial' : 'unpaid';
                $primaryMethod = $paidAmount > 0 ? 'credit_partial' : 'credit';
            }
            // ── Vente au COMPTANT (comportement habituel) ──
            else {
                $paidAmount    = $cashPaid;
                $change        = max(0, $paidAmount - $total);
                $paymentStatus = $paidAmount < $total ? ($paidAmount > 0 ? 'partial' : 'unpaid') : 'paid';
                $primaryMethod = count($payments) > 1 ? 'multiple' : ($payments[0]['method'] ?? 'cash');
            }

            $order = Order::create([
                'tenant_id'       => $tenantId,
                'customer_id'     => $request->customer_id,
                'user_id'         => $userId,
                'reference'       => $this->generateReference(),
                'status'          => 'completed',
                'payment_status'  => $paymentStatus,
                'payment_method'  => $primaryMethod,
                'subtotal'        => $subtotal,
                'tax_amount'      => $tax,
                'discount_amount' => $discount,
                'total_amount'    => $total,
                'paid_amount'     => $paidAmount,
                'change_amount'   => $change,
                'notes'           => $request->notes,
            ]);

            $order->items()->createMany($orderItemsData);

            // Paiements réels détaillés
            foreach ($payments as $p) {
                $order->payments()->create([
                    'payment_method' => $p['method'],
                    'amount'         => $p['amount'],
                    'reference'      => $p['reference'] ?? null,
                ]);
            }

            // ── Mouvements de compte client ──
            $accountService = app(\App\Services\CustomerAccountService::class);
            if ($mode === 'deposit') {
                $accountService->record($customer, 'withdrawal', $total, $userId, $order, null, 'avance',
                    "Achat payé sur avance — commande {$order->reference}");
            } elseif ($mode === 'credit') {
                $creditAmount = round($total - $paidAmount, 2);
                if ($creditAmount > 0) {
                    $accountService->record($customer, 'credit', $creditAmount, $userId, $order,
                        $request->due_date, null, "Vente à crédit — commande {$order->reference}");
                }
            }

            return response()->json([
                'message' => 'Commande créée avec succès.',
                'order'   => $order->load(['items', 'payments', 'customer']),
            ], 201);
        });
    }

    public function show($id)
    {
        $tenantId = Auth::user()->tenant_id;
        $order = Order::where('tenant_id', $tenantId)
            ->with(['items.product', 'customer', 'user'])
            ->findOrFail($id);

        return response()->json(['order' => $order]);
    }

    public function destroy($id)
    {
        abort_unless(Auth::user()->canDo('orders', 'delete'), 403, 'Permission refusée : supprimer une commande.');
        $tenantId = Auth::user()->tenant_id;
        $order = Order::where('tenant_id', $tenantId)->findOrFail($id);

        return DB::transaction(function () use ($order) {
            foreach ($order->items as $item) {
                if ($item->product_id) {
                    Product::where('id', $item->product_id)->increment('stock_quantity', $item->quantity);
                }
            }

            $order->delete();

            return response()->json(['message' => 'Commande annulée et stock restauré.']);
        });
    }

    public function downloadInvoice($id)
    {
        $user = Auth::user();

        $order = Order::where('tenant_id', $user->tenant_id)
            ->with(['items', 'payments', 'customer', 'tenant'])
            ->findOrFail($id);

        $pdf = Pdf::loadView('pdf.invoice', compact('order'));

        return $pdf->download("facture-{$order->reference}.pdf");
    }

    private function generateReference(): string
    {
        $date = now()->format('Ymd');
        do {
            $ref = 'ORD-' . $date . '-' . strtoupper(Str::random(5));
        } while (Order::where('reference', $ref)->exists());

        return $ref;
    }

    public function meta()
    {
        // Utile pour les listes de sélection (clients, produits dispos, etc)
        $tenantId = Auth::user()->tenant_id;
        return response()->json([
            'payment_methods' => [
                ['value' => 'cash', 'label' => 'Espèces'],
                ['value' => 'wave', 'label' => 'Wave'],
                ['value' => 'orange_money', 'label' => 'Orange Money'],
                ['value' => 'card', 'label' => 'Carte Bancaire'],
                ['value' => 'check', 'label' => 'Chèque'],
            ],
            'statuses' => [
                ['value' => 'completed', 'label' => 'Terminée'],
                ['value' => 'pending',   'label' => 'En attente'],
                ['value' => 'cancelled', 'label' => 'Annulée'],
            ]
        ]);
    }
}
