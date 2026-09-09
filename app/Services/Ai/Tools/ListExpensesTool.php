<?php

namespace App\Services\Ai\Tools;

use App\Models\Expense;
use Illuminate\Support\Carbon;

/**
 * Tool to list and summarise expenses.
 * Example: "Liste mes dépenses du mois de Mai"
 */
class ListExpensesTool implements AiTool
{
    public function name(): string
    {
        return 'list_expenses';
    }

    public function schema(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name'        => $this->name(),
                'description' => "Liste et résume les dépenses financières sur une période donnée.",
                'parameters'  => [
                    'type' => 'object',
                    'properties' => [
                        'start_date' => [
                            'type'        => 'string',
                            'format'      => 'date',
                            'description' => 'Date de début (YYYY-MM-DD). Défaut: début du mois en cours.',
                        ],
                        'end_date' => [
                            'type'        => 'string',
                            'format'      => 'date',
                            'description' => 'Date de fin (YYYY-MM-DD). Défaut: aujourd\'hui.',
                        ],
                        'category' => [
                            'type'        => 'string',
                            'description' => 'Filtrer par catégorie (optionnel).',
                        ],
                    ],
                ],
            ],
        ];
    }

    public function execute(array $args): array
    {
        $tenantId = (int) auth()->user()?->tenant_id;
        if (! $tenantId) {
            return ['ok' => false, 'error' => 'Utilisateur non authentifié.'];
        }

        $start = (string) ($args['start_date'] ?? Carbon::now()->startOfMonth()->format('Y-m-d'));
        $end   = (string) ($args['end_date'] ?? Carbon::now()->format('Y-m-d'));
        $cat   = (string) ($args['category'] ?? '');

        $query = Expense::query()
            ->where('tenant_id', $tenantId)
            ->whereBetween('expense_date', [$start, $end]);

        if ($cat !== '') {
            $query->where('category', $cat);
        }

        $expenses = $query->orderBy('expense_date', 'desc')->get();
        $total    = $expenses->sum('amount');

        if ($expenses->isEmpty()) {
            return [
                'ok'      => true,
                'total'   => 0,
                'count'   => 0,
                'message' => "Aucune dépense trouvée entre le " . Carbon::parse($start)->format('d/m/Y') . " et le " . Carbon::parse($end)->format('d/m/Y') . ".",
            ];
        }

        $summary = $expenses->take(10)->map(function ($e) {
            return "- " . Carbon::parse($e->expense_date)->format('d/m') . " : " . $e->description . " (" . number_format($e->amount, 0, ',', ' ') . " FCFA)";
        })->implode("\n");

        if ($expenses->count() > 10) {
            $summary .= "\n... (et " . ($expenses->count() - 10) . " autres)";
        }

        return [
            'ok'      => true,
            'total'   => $total,
            'count'   => $expenses->count(),
            'expenses'=> $expenses->take(20)->toArray(),
            'message' => "Total des dépenses : " . number_format($total, 0, ',', ' ') . " FCFA sur " . $expenses->count() . " opérations.\n\nDernières opérations :\n" . $summary,
        ];
    }
}
