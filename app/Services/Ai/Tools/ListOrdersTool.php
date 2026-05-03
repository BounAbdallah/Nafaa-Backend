<?php

namespace App\Services\Ai\Tools;

use App\Models\Order;
use Illuminate\Support\Carbon;

/**
 * Tool to list and summarise sales orders.
 * Example: "Combien j'ai vendu aujourd'hui ?" or "Liste les 5 dernières commandes"
 */
class ListOrdersTool implements AiTool
{
    public function name(): string
    {
        return 'list_orders';
    }

    public function schema(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name'        => $this->name(),
                'description' => "Liste et résume les ventes (commandes clients) sur une période donnée.",
                'parameters'  => [
                    'type' => 'object',
                    'properties' => [
                        'start_date' => [
                            'type'        => 'string',
                            'format'      => 'date',
                            'description' => 'Date de début (YYYY-MM-DD). Défaut: aujourd\'hui.',
                        ],
                        'end_date' => [
                            'type'        => 'string',
                            'format'      => 'date',
                            'description' => 'Date de fin (YYYY-MM-DD). Défaut: aujourd\'hui.',
                        ],
                        'status' => [
                            'type'        => 'string',
                            'enum'        => ['pending', 'completed', 'cancelled'],
                            'description' => "Filtrer par statut de commande.",
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

        $start = (string) ($args['start_date'] ?? Carbon::now()->format('Y-m-d'));
        $end   = (string) ($args['end_date'] ?? Carbon::now()->format('Y-m-d'));
        $status = (string) ($args['status'] ?? '');

        $query = Order::query()
            ->where('tenant_id', $tenantId)
            ->whereDate('created_at', '>=', $start)
            ->whereDate('created_at', '<=', $end);

        if ($status !== '') {
            $query->where('status', $status);
        }

        $orders = $query->with('customer')->orderBy('created_at', 'desc')->get();
        $total  = $orders->sum('total_amount');

        if ($orders->isEmpty()) {
            return [
                'ok'      => true,
                'total'   => 0,
                'count'   => 0,
                'message' => "Aucune commande trouvée entre le " . Carbon::parse($start)->format('d/m/Y') . " et le " . Carbon::parse($end)->format('d/m/Y') . ".",
            ];
        }

        $summary = $orders->take(8)->map(function ($o) {
            $client = $o->customer ? $o->customer->name : 'Client anonyme';
            $date   = $o->created_at->format('H:i');
            return "- #{$o->reference} ({$date}) : {$client} — " . number_format($o->total_amount, 0, ',', ' ') . " FCFA (" . __($o->status) . ")";
        })->implode("\n");

        if ($orders->count() > 8) {
            $summary .= "\n... (et " . ($orders->count() - 8) . " autres)";
        }

        $periodStr = $start === $end ? "aujourd'hui" : "sur la période";

        return [
            'ok'      => true,
            'total'   => $total,
            'count'   => $orders->count(),
            'orders'  => $orders->take(10)->toArray(),
            'message' => "Chiffre d'affaires {$periodStr} : " . number_format($total, 0, ',', ' ') . " FCFA sur " . $orders->count() . " commande(s).\n\nDernières ventes :\n" . $summary,
        ];
    }
}
