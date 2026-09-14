<?php

namespace App\Services\Ai\Tools;

use App\Models\Customer;

/**
 * Tool to search and list customers.
 * Example: "Trouve le client Diop" or "Liste mes meilleurs clients"
 */
class ListCustomersTool implements AiTool
{
    public function name(): string
    {
        return 'list_customers';
    }

    public function schema(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name'        => $this->name(),
                'description' => "Recherche et liste les clients enregistrés.",
                'parameters'  => [
                    'type' => 'object',
                    'properties' => [
                        'search' => [
                            'type'        => 'string',
                            'description' => 'Nom, téléphone ou entreprise du client.',
                        ],
                        'sort_by_spent' => [
                            'type'        => 'boolean',
                            'description' => "Si vrai, liste les clients qui dépensent le plus en premier.",
                        ],
                    ],
                ],
            ],
        ];
    }

    public function execute(array $args): array
    {
        $tenantId = (int) auth()->user()?->tenant_id;
        $search   = trim((string) ($args['search'] ?? ''));
        $sortBy   = (bool) ($args['sort_by_spent'] ?? false);

        $query = Customer::query()->where('tenant_id', $tenantId);

        if ($search !== '') {
            $query->where(function($q) use ($search) {
                $q->where('name', 'LIKE', "%{$search}%")
                  ->orWhere('phone', 'LIKE', "%{$search}%")
                  ->orWhere('company', 'LIKE', "%{$search}%");
            });
        }

        if ($sortBy) {
            $query->orderBy('total_spent', 'desc');
        } else {
            $query->orderBy('name', 'asc');
        }

        $customers = $query->take(10)->get();

        if ($customers->isEmpty()) {
            return ['ok' => true, 'count' => 0, 'message' => "Aucun client trouvé pour « {$search} »."];
        }

        $list = $customers->map(function ($c) {
            $spent = number_format($c->total_spent, 0, ',', ' ') . " FCFA";
            return "• {$c->name} " . ($c->phone ? "({$c->phone})" : "") . " — {$spent} ({$c->orders_count} cmd)";
        })->implode("\n");

        return [
            'ok'        => true,
            'count'     => $customers->count(),
            'customers' => $customers->toArray(),
            'message'   => "Résultats de la recherche client :\n" . $list,
        ];
    }
}
