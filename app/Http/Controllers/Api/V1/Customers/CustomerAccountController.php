<?php

namespace App\Http\Controllers\Api\V1\Customers;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerAccountEntry;
use App\Services\CustomerAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CustomerAccountController extends Controller
{
    public function __construct(private readonly CustomerAccountService $service) {}

    private function authorizeTenant(Customer $customer): void
    {
        abort_if($customer->tenant_id !== Auth::user()->tenant_id, 403);
    }

    /** Bloque si la fonctionnalité crédit n'est pas activée sur l'abonnement. */
    private function assertFeatureEnabled(): void
    {
        abort_unless(
            Auth::user()->tenant?->hasFeature('credit'),
            403,
            'La fonctionnalité « crédit / compte client » n\'est pas activée sur votre abonnement.'
        );
    }

    /** Solde + historique des mouvements d'un client. */
    public function show(Request $request, Customer $customer): JsonResponse
    {
        $this->assertFeatureEnabled();
        $this->authorizeTenant($customer);

        $entries = $customer->accountEntries()->with('user:id,name')->paginate($request->get('per_page', 20));

        return response()->json([
            'success' => true,
            'data'    => [
                'balance' => (float) $customer->account_balance,
                'debt'    => $customer->debt,
                'deposit' => $customer->deposit,
                'entries' => collect($entries->items())->map(fn ($e) => [
                    'id'             => $e->id,
                    'type'           => $e->type,
                    'type_label'     => CustomerAccountEntry::LABELS[$e->type] ?? $e->type,
                    'amount'         => $e->amount,
                    'balance_after'  => $e->balance_after,
                    'due_date'       => $e->due_date?->toDateString(),
                    'payment_method' => $e->payment_method,
                    'note'           => $e->note,
                    'order_id'       => $e->order_id,
                    'by'             => $e->user?->name,
                    'created_at'     => $e->created_at->toIso8601String(),
                ]),
                'meta' => [
                    'total'        => $entries->total(),
                    'current_page' => $entries->currentPage(),
                    'last_page'    => $entries->lastPage(),
                ],
            ],
        ]);
    }

    /** Le client rembourse tout ou partie de son ardoise. */
    public function repay(Request $request, Customer $customer): JsonResponse
    {
        $this->assertFeatureEnabled();
        $this->authorizeTenant($customer);
        abort_unless(Auth::user()->canDo('orders', 'create'), 403, 'Permission refusée.');

        $data = $request->validate([
            'amount'         => 'required|numeric|min:1',
            'payment_method' => 'nullable|string',
            'note'           => 'nullable|string|max:255',
        ]);

        if ($customer->debt <= 0) {
            return response()->json(['success' => false, 'message' => "Ce client n'a aucune dette."], 422);
        }
        if ($data['amount'] > $customer->debt) {
            return response()->json(['success' => false, 'message' => 'Le montant dépasse la dette du client.'], 422);
        }

        $this->service->record($customer, 'repayment', $data['amount'], Auth::id(), null, null,
            $data['payment_method'] ?? 'cash', $data['note'] ?? 'Remboursement de dette');

        return response()->json([
            'success' => true,
            'message' => 'Remboursement enregistré.',
            'data'    => ['balance' => (float) $customer->fresh()->account_balance],
        ]);
    }

    /** Le client dépose de l'argent d'avance à la boutique. */
    public function deposit(Request $request, Customer $customer): JsonResponse
    {
        $this->assertFeatureEnabled();
        $this->authorizeTenant($customer);
        abort_unless(Auth::user()->canDo('orders', 'create'), 403, 'Permission refusée.');

        $data = $request->validate([
            'amount'         => 'required|numeric|min:1',
            'payment_method' => 'nullable|string',
            'note'           => 'nullable|string|max:255',
        ]);

        $this->service->record($customer, 'deposit', $data['amount'], Auth::id(), null, null,
            $data['payment_method'] ?? 'cash', $data['note'] ?? 'Dépôt d\'avance');

        return response()->json([
            'success' => true,
            'message' => 'Dépôt enregistré.',
            'data'    => ['balance' => (float) $customer->fresh()->account_balance],
        ]);
    }

    /** Liste des clients qui doivent de l'argent (ardoises). */
    public function debtors(Request $request): JsonResponse
    {
        $this->assertFeatureEnabled();
        $tenantId = Auth::user()->tenant_id;

        $query = Customer::where('tenant_id', $tenantId)
            ->where('account_balance', '<', 0)
            ->orderBy('account_balance'); // les plus grosses dettes d'abord

        if ($search = $request->get('search')) {
            $query->where(fn ($q) => $q->where('name', 'like', "%$search%")->orWhere('phone', 'like', "%$search%"));
        }

        $customers = $query->paginate($request->get('per_page', 20));

        $totalDebt = (float) Customer::where('tenant_id', $tenantId)->where('account_balance', '<', 0)->sum('account_balance');

        return response()->json([
            'success' => true,
            'data'    => [
                'customers' => collect($customers->items())->map(fn ($c) => [
                    'id'     => $c->id,
                    'name'   => $c->name,
                    'phone'  => $c->phone,
                    'debt'   => $c->debt,
                ]),
                'total_debt' => abs($totalDebt),
                'meta' => [
                    'total'        => $customers->total(),
                    'current_page' => $customers->currentPage(),
                    'last_page'    => $customers->lastPage(),
                ],
            ],
        ]);
    }
}
