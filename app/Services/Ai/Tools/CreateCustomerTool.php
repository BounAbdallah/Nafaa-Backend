<?php

namespace App\Services\Ai\Tools;

use App\Models\Customer;

/**
 * Tool to create a new customer via AI.
 * Example: "Ajoute un client nommé Jean Paul, numéro 771234567"
 */
class CreateCustomerTool implements AiTool
{
    public function name(): string
    {
        return 'create_customer';
    }

    public function schema(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name'        => $this->name(),
                'description' => "Crée une nouvelle fiche client.",
                'parameters'  => [
                    'type' => 'object',
                    'properties' => [
                        'name' => [
                            'type'        => 'string',
                            'description' => 'Nom complet du client.',
                        ],
                        'phone' => [
                            'type'        => 'string',
                            'description' => 'Numéro de téléphone.',
                        ],
                        'company' => [
                            'type'        => 'string',
                            'description' => 'Nom de l\'entreprise (optionnel).',
                        ],
                        'email' => [
                            'type'        => 'string',
                            'format'      => 'email',
                            'description' => 'Adresse email (optionnel).',
                        ],
                        'address' => [
                            'type'        => 'string',
                            'description' => 'Adresse physique.',
                        ],
                    ],
                    'required' => ['name'],
                ],
            ],
        ];
    }

    public function execute(array $args): array
    {
        $tenantId = (int) auth()->user()?->tenant_id;
        $name     = trim((string) ($args['name'] ?? ''));

        if ($name === '') {
            return ['ok' => false, 'error' => 'Le nom du client est requis.'];
        }

        // Check if phone already exists for this tenant
        $phone = trim((string) ($args['phone'] ?? ''));
        if ($phone !== '') {
            $exists = Customer::where('tenant_id', $tenantId)->where('phone', $phone)->exists();
            if ($exists) {
                return ['ok' => false, 'error' => "Un client avec le numéro {$phone} existe déjà."];
            }
        }

        try {
            $customer = Customer::create([
                'tenant_id' => $tenantId,
                'name'      => $name,
                'phone'     => $phone ?: null,
                'email'     => (string) ($args['email'] ?? '') ?: null,
                'company'   => (string) ($args['company'] ?? '') ?: null,
                'address'   => (string) ($args['address'] ?? '') ?: null,
                'type'      => 'individual',
                'is_active' => true,
            ]);

            return [
                'ok'          => true,
                'customer_id' => $customer->id,
                'name'        => $customer->name,
                'message'     => "Le client « {$customer->name} » a été créé avec succès.",
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => "Échec de la création : " . $e->getMessage()];
        }
    }
}
