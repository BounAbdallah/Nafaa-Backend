<?php

namespace App\Services\Ai\Tools;

use App\Models\Expense;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Bulk-creates multiple expenses at once.
 * Designed for CSV imports and table-based entries.
 */
class BulkCreateExpensesTool implements AiTool
{
    public function name(): string
    {
        return 'bulk_create_expenses';
    }

    public function schema(): array
    {
        $categories     = Expense::categories();
        $paymentMethods = Expense::paymentMethods();

        return [
            'type' => 'function',
            'function' => [
                'name'        => $this->name(),
                'description' => "Crée plusieurs dépenses d'un coup à partir d'une liste.",
                'parameters'  => [
                    'type' => 'object',
                    'properties' => [
                        'items' => [
                            'type'  => 'array',
                            'minItems' => 1,
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'description'    => ['type' => 'string'],
                                    'amount'         => ['type' => 'number'],
                                    'category'       => ['type' => 'string', 'enum' => array_keys($categories)],
                                    'payment_method' => ['type' => 'string', 'enum' => array_keys($paymentMethods)],
                                    'expense_date'   => ['type' => 'string', 'format' => 'date'],
                                    'notes'          => ['type' => 'string'],
                                ],
                                'required' => ['description', 'amount'],
                            ],
                        ],
                    ],
                    'required' => ['items'],
                ],
            ],
        ];
    }

    public function execute(array $args): array
    {
        $tenantId = (int) auth()->user()?->tenant_id;
        $userId   = auth()->id();
        if (! $tenantId) {
            return ['ok' => false, 'error' => 'Utilisateur sans tenant.'];
        }

        $items = $args['items'] ?? [];
        if (! is_array($items) || empty($items)) {
            return ['ok' => false, 'error' => 'Aucun item fourni.'];
        }

        $created = [];
        $skipped = [];

        DB::transaction(function () use ($items, $tenantId, $userId, &$created, &$skipped) {
            foreach ($items as $idx => $item) {
                $description = trim((string) ($item['description'] ?? ''));
                $amount      = (float) ($item['amount'] ?? 0);

                if ($description === '' || $amount <= 0) {
                    $skipped[] = ['row' => $idx + 1, 'reason' => 'Description vide ou montant invalide'];
                    continue;
                }

                $expense = Expense::create([
                    'tenant_id'      => $tenantId,
                    'user_id'        => $userId,
                    'description'    => $description,
                    'amount'         => $amount,
                    'category'       => (string) ($item['category'] ?? 'autre'),
                    'payment_method' => (string) ($item['payment_method'] ?? 'cash'),
                    'expense_date'   => isset($item['expense_date']) ? Carbon::parse($item['expense_date'])->format('Y-m-d') : date('Y-m-d'),
                    'notes'          => (string) ($item['notes'] ?? '') ?: null,
                ]);

                $created[] = [
                    'id'          => $expense->id,
                    'description' => $expense->description,
                    'amount'      => $expense->amount,
                ];
            }
        });

        return [
            'ok'      => count($created) > 0,
            'created' => $created,
            'skipped' => $skipped,
            'count'   => count($created),
            'message' => count($created) === 0
                ? "Aucune dépense n'a pu être créée."
                : "Import terminé : " . count($created) . " dépense(s) créée(s)." . (count($skipped) ? " (" . count($skipped) . " ligne(s) ignorée(s))" : ""),
        ];
    }
}
