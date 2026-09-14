<?php

namespace App\Services\Ai\Tools;

use App\Models\Order;

/**
 * Tool to get detailed info about a specific order by reference or ID.
 */
class QueryOrderTool implements AiTool
{
    public function name(): string
    {
        return 'query_order';
    }

    public function schema(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name'        => $this->name(),
                'description' => "Récupère les détails complets d'une commande spécifique (articles, paiement, statut).",
                'parameters'  => [
                    'type' => 'object',
                    'properties' => [
                        'reference' => [
                            'type'        => 'string',
                            'description' => 'Référence de la commande (ex: CMD-20240101-001).',
                        ],
                    ],
                    'required' => ['reference'],
                ],
            ],
        ];
    }

    public function execute(array $args): array
    {
        $tenantId = (int) auth()->user()?->tenant_id;
        $ref      = trim((string) ($args['reference'] ?? ''));

        if ($ref === '') {
            return ['ok' => false, 'error' => 'Une référence est requise.'];
        }

        $order = Order::query()
            ->where('tenant_id', $tenantId)
            ->where('reference', $ref)
            ->with(['customer', 'items.product', 'user'])
            ->first();

        if (! $order) {
            // Try to search by partial reference if exact match fails
            $order = Order::query()
                ->where('tenant_id', $tenantId)
                ->where('reference', 'LIKE', "%{$ref}%")
                ->with(['customer', 'items.product', 'user'])
                ->first();
        }

        if (! $order) {
            return ['ok' => false, 'error' => "Commande « {$ref} » introuvable."];
        }

        $itemsSummary = $order->items->map(fn($i) => "- " . ($i->product->name ?? 'Produit') . " x" . $i->quantity . " (" . number_format($i->total_amount, 0, ',', ' ') . " FCFA)")->implode("\n");

        return [
            'ok'      => true,
            'order'   => $order->toArray(),
            'message' => "Détails de la commande #{$order->reference} :\n"
                       . "• Client : " . ($order->customer->name ?? 'Anonyme') . "\n"
                       . "• Statut : " . __($order->status) . " / " . __($order->payment_status) . "\n"
                       . "• Total : " . number_format($order->total_amount, 0, ',', ' ') . " FCFA\n"
                       . "• Articles :\n" . $itemsSummary,
        ];
    }
}
