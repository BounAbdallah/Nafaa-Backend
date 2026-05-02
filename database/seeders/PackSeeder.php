<?php

namespace Database\Seeders;

use App\Models\Pack;
use Illuminate\Database\Seeder;

class PackSeeder extends Seeder
{
    public function run(): void
    {
        $packs = [
            [
                'name'        => 'Démarrage',
                'slug'        => 'demarrage',
                'description' => 'Idéal pour les micro-entreprises et auto-entrepreneurs.',
                'price'       => 0,
                'period'      => 'monthly',
                'features'    => ['dashboard', 'products', 'customers', 'orders', 'settings'],
                'limits'      => ['users' => 2, 'products' => 50, 'storage_gb' => 1],
                'order'       => 1,
            ],
            [
                'name'        => 'Pro',
                'slug'        => 'pro',
                'description' => 'Pour les entreprises en croissance nécessitant plus de contrôle.',
                'price'       => 15000,
                'period'      => 'monthly',
                'features'    => ['dashboard', 'products', 'customers', 'orders', 'expenses', 'reports', 'settings'],
                'limits'      => ['users' => 10, 'products' => 500, 'storage_gb' => 10],
                'order'       => 2,
            ],
            [
                'name'        => 'Business',
                'slug'        => 'business',
                'description' => 'Solution complète pour les PME avec gestion avancée.',
                'price'       => 35000,
                'period'      => 'monthly',
                'features'    => ['dashboard', 'products', 'customers', 'suppliers', 'purchase-orders', 'expenses', 'orders', 'reports', 'production', 'settings'],
                'limits'      => ['users' => 50, 'products' => 5000, 'storage_gb' => 50],
                'order'       => 3,
            ],
            [
                'name'        => 'Entreprise',
                'slug'        => 'entreprise',
                'description' => 'Puissance illimitée pour les grandes organisations.',
                'price'       => 75000,
                'period'      => 'monthly',
                'features'    => ['dashboard', 'products', 'customers', 'suppliers', 'purchase-orders', 'expenses', 'pos', 'orders', 'reports', 'production', 'settings'],
                'limits'      => ['users' => -1, 'products' => -1, 'storage_gb' => 500],
                'order'       => 4,
            ],
        ];

        foreach ($packs as $packData) {
            Pack::updateOrCreate(['slug' => $packData['slug']], $packData);
        }
    }
}
