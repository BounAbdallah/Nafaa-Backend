<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

/**
 * Initialise le plan des comptes SYSCOHADA simplifié pour chaque tenant
 * qui a la fonctionnalité « accounting ».
 * Peut être relancé sans doublon (firstOrCreate).
 */
class AccountingSeeder extends Seeder
{
    /**
     * Comptes système SYSCOHADA simplifiés.
     * [number, label, class, nature]
     */
    public static function systemAccounts(): array
    {
        return [
            // Classe 1 — Ressources durables
            ['101', 'Capital',                         1, 'equity'],
            ['106', 'Réserves',                        1, 'equity'],
            ['12',  'Résultat de l\'exercice',         1, 'equity'],

            // Classe 2 — Actif immobilisé
            ['22',  'Terrains',                        2, 'asset'],
            ['24',  'Matériels et mobiliers',          2, 'asset'],
            ['25',  'Matériel de transport',           2, 'asset'],

            // Classe 3 — Stocks
            ['31',  'Marchandises',                    3, 'stock'],
            ['32',  'Matières premières',              3, 'stock'],

            // Classe 4 — Tiers
            ['401', 'Fournisseurs',                    4, 'liability'],
            ['411', 'Clients',                         4, 'asset'],
            ['421', 'Personnel — rémunérations dues',  4, 'liability'],
            ['441', 'État — TVA collectée',            4, 'liability'],
            ['4451','État — TVA déductible',           4, 'asset'],
            ['447', 'État — impôts et taxes',          4, 'liability'],

            // Classe 5 — Trésorerie
            ['52',  'Banque',                          5, 'treasury'],
            ['57',  'Caisse',                          5, 'treasury'],

            // Classe 6 — Charges
            ['601', 'Achats de marchandises',          6, 'expense'],
            ['602', 'Achats de matières premières',    6, 'expense'],
            ['604', 'Achats stockés — autres',         6, 'expense'],
            ['611', 'Transports sur achats',           6, 'expense'],
            ['621', 'Sous-traitance',                  6, 'expense'],
            ['625', 'Déplacements & transport',        6, 'expense'],
            ['626', 'Téléphone & Internet',            6, 'expense'],
            ['627', 'Publicité & Marketing',           6, 'expense'],
            ['631', 'Frais bancaires',                 6, 'expense'],
            ['641', 'Salaires & rémunérations',        6, 'expense'],
            ['645', 'Charges sociales',                6, 'expense'],
            ['651', 'Loyers',                          6, 'expense'],
            ['658', 'Charges diverses',                6, 'expense'],
            ['661', 'Intérêts des emprunts',           6, 'expense'],

            // Classe 7 — Produits
            ['701', 'Ventes de marchandises',          7, 'revenue'],
            ['706', 'Prestations de services',         7, 'revenue'],
            ['754', 'Autres produits',                 7, 'revenue'],
        ];
    }

    public function run(): void
    {
        $tenants = Tenant::all();

        foreach ($tenants as $tenant) {
            self::seedForTenant($tenant->id);
        }
    }

    public static function seedForTenant(int $tenantId): void
    {
        foreach (self::systemAccounts() as [$number, $label, $class, $nature]) {
            Account::firstOrCreate(
                ['tenant_id' => $tenantId, 'number' => $number],
                ['label' => $label, 'class' => $class, 'nature' => $nature, 'is_system' => true]
            );
        }
    }
}
