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
        $tenantId = Auth::user()->tenant_id;
        
        $query = Order::where('tenant_id', $tenantId)
            ->with(['customer', 'user'])
            ->latest();

        if ($request->search) {
            $query->where('reference', 'like', "%{$request->search}%");
        }

        if ($request->status) {
            $query->where('status', $request->status);
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
        $request->validate([
            'customer_id'    => 'nullable|exists:customers,id',
            'items'          => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity'   => 'required|numeric|min:0.001',
            'payments'       => 'required|array|min:1',
            'payments.*.method'  => 'required|string',
            'payments.*.amount'  => 'required|numeric|min:0',
            'payments.*.reference' => 'nullable|string',
            'discount_amount'=> 'nullable|numeric|min:0',
            'tax_amount'     => 'nullable|numeric|min:0',
            'notes'          => 'nullable|string',
        ]);

        $tenantId = Auth::user()->tenant_id;
        $userId   = Auth::id();

        return DB::transaction(function () use ($request, $tenantId, $userId) {
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
            
            $paidAmount = collect($request->payments)->sum('amount');
            $change     = max(0, $paidAmount - $total);

            // Déterminer le statut de paiement
            $paymentStatus = 'paid';
            if ($paidAmount < $total) {
                $paymentStatus = $paidAmount > 0 ? 'partial' : 'unpaid';
            }

            $primaryMethod = $request->payments[0]['method'];
            if (count($request->payments) > 1) {
                $primaryMethod = 'multiple';
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
            
            // Enregistrer les paiements détaillés
            foreach ($request->payments as $p) {
                $order->payments()->create([
                    'payment_method' => $p['method'],
                    'amount'         => $p['amount'],
                    'reference'      => $p['reference'] ?? null,
                ]);
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
