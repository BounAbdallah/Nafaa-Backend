<?php

namespace App\Services\Ai\Tools;

use App\Models\Expense;
use Illuminate\Support\Carbon;

/**
 * Tool to record a new expense via AI.
 * Example: "Enregistre une dépense de 5000 pour le loyer payée par Wave"
 */
class CreateExpenseTool implements AiTool
{
    public function name(): string
    {
        return 'create_expense';
    }

    public function schema(): array
    {
        $categories     = Expense::categories();
        $paymentMethods = Expense::paymentMethods();

        return [
            'type' => 'function',
            'function' => [
                'name'        => $this->name(),
                'description' => "Enregistre une nouvelle dépense financière.",
                'parameters'  => [
                    'type' => 'object',
                    'properties' => [
                        'amount' => [
                            'type'        => 'number',
                            'description' => 'Montant de la dépense en FCFA.',
                        ],
                        'description' => [
                            'type'        => 'string',
                            'description' => 'Description de la dépense (ex: "Facture électricité Janvier").',
                        ],
                        'category' => [
                            'type'        => 'string',
                            'enum'        => array_keys($categories),
                            'description' => "Catégorie de dépense (défaut 'autre'). Options: " . implode(', ', array_keys($categories)),
                        ],
                        'payment_method' => [
                            'type'        => 'string',
                            'enum'        => array_keys($paymentMethods),
                            'description' => "Moyen de paiement (défaut 'cash'). Options: " . implode(', ', array_keys($paymentMethods)),
                        ],
                        'expense_date' => [
                            'type'        => 'string',
                            'format'      => 'date',
                            'description' => 'Date de la dépense (YYYY-MM-DD). Par défaut: aujourd\'hui.',
                        ],
                        'notes' => [
                            'type'        => 'string',
                            'description' => 'Notes additionnelles (optionnel).',
                        ],
                    ],
                    'required' => ['amount', 'description'],
                ],
            ],
        ];
    }

    public function execute(array $args): array
    {
        $amount      = (float) ($args['amount'] ?? 0);
        $description = trim((string) ($args['description'] ?? ''));
        $category    = (string) ($args['category'] ?? 'autre');
        $payment     = (string) ($args['payment_method'] ?? 'cash');
        $date        = (string) ($args['expense_date'] ?? date('Y-m-d'));

        if ($amount <= 0) {
            return ['ok' => false, 'error' => 'Le montant doit être supérieur à zéro.'];
        }
        if ($description === '') {
            return ['ok' => false, 'error' => 'Une description est requise.'];
        }

        $user     = auth()->user();
        $tenantId = (int) $user?->tenant_id;

        if (! $tenantId) {
            return ['ok' => false, 'error' => 'Utilisateur non authentifié ou sans tenant.'];
        }

        // Validate category
        if (! array_key_exists($category, Expense::categories())) {
            $category = 'autre';
        }

        // Validate payment method
        if (! array_key_exists($payment, Expense::paymentMethods())) {
            $payment = 'cash';
        }

        try {
            $expense = Expense::create([
                'tenant_id'      => $tenantId,
                'user_id'        => $user->id,
                'category'       => $category,
                'description'    => $description,
                'amount'         => $amount,
                'payment_method' => $payment,
                'expense_date'   => Carbon::parse($date)->format('Y-m-d'),
                'notes'          => (string) ($args['notes'] ?? '') ?: null,
            ]);

            $catLabel = Expense::categories()[$category] ?? $category;

            return [
                'ok'             => true,
                'expense_id'     => $expense->id,
                'amount'         => $expense->amount,
                'description'    => $expense->description,
                'category'       => $catLabel,
                'message'        => "Dépense de " . number_format($amount, 0, ',', ' ') . " FCFA enregistrée pour « {$description} » (Catégorie: {$catLabel}).",
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => "Échec de l'enregistrement : " . $e->getMessage()];
        }
    }
}
