<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerAccountEntry;
use App\Models\Order;
use Illuminate\Support\Facades\DB;

/**
 * Gère le compte client (ardoise + avance) de façon cohérente.
 * Convention de solde (account_balance) :
 *   > 0 → le client a une AVANCE (la boutique lui doit)
 *   < 0 → le client a une DETTE / ardoise (il doit à la boutique)
 *
 * Effet de chaque type sur le solde :
 *   credit     (vente à crédit)        → solde -= montant   (la dette augmente)
 *   repayment  (remboursement)         → solde += montant   (la dette baisse)
 *   deposit    (dépôt d'avance)        → solde += montant   (l'avance augmente)
 *   withdrawal (achat payé sur avance) → solde -= montant   (l'avance baisse)
 */
class CustomerAccountService
{
    private const SIGN = [
        'credit'     => -1,
        'repayment'  => +1,
        'deposit'    => +1,
        'withdrawal' => -1,
    ];

    /**
     * Enregistre un mouvement et met à jour le solde du client (transaction atomique).
     */
    public function record(
        Customer $customer,
        string $type,
        float $amount,
        ?int $userId = null,
        ?Order $order = null,
        ?string $dueDate = null,
        ?string $paymentMethod = null,
        ?string $note = null,
    ): CustomerAccountEntry {
        abort_unless(isset(self::SIGN[$type]), 422, 'Type de mouvement invalide.');
        abort_if($amount <= 0, 422, 'Le montant doit être positif.');

        return DB::transaction(function () use ($customer, $type, $amount, $userId, $order, $dueDate, $paymentMethod, $note) {
            $customer = Customer::lockForUpdate()->find($customer->id);

            $newBalance = round($customer->account_balance + (self::SIGN[$type] * $amount), 2);

            $entry = CustomerAccountEntry::create([
                'tenant_id'      => $customer->tenant_id,
                'customer_id'    => $customer->id,
                'order_id'       => $order?->id,
                'user_id'        => $userId,
                'type'           => $type,
                'amount'         => $amount,
                'balance_after'  => $newBalance,
                'due_date'       => $dueDate,
                'payment_method' => $paymentMethod,
                'note'           => $note,
            ]);

            $customer->update(['account_balance' => $newBalance]);

            return $entry;
        });
    }
}
