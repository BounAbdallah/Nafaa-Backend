<?php

namespace App\Services\Ai;

use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Parser spécialisé pour les imports de recettes (BOM) en CSV / XLSX.
 *
 * Format attendu — une ligne par ingrédient :
 *   product_name | bom_quantity | waste_percentage | ingredient_name | ingredient_quantity | ingredient_unit
 *
 * Colonnes acceptées (FR / EN, insensible accents) :
 *   product_name     : produit, product, recette, recipe, plat, dish, fini, finished
 *   bom_quantity     : qte_bom, bom_qty, quantite_base, base_qty, portions
 *   waste_percentage : perte, waste, dechet, loss
 *   ingredient_name  : ingredient, matiere, material, composant
 *   ingredient_qty   : quantite, qty, qte
 *   ingredient_unit  : unite, unit
 *
 * Résultat :
 *   [
 *     [ 'product_name' => 'Thiéboudienne', 'bom_quantity' => 1, 'waste_percentage' => 5,
 *       'ingredients' => [ ['name' => 'Riz', 'quantity' => 2, 'unit' => 'kg'], ... ] ],
 *     ...
 *   ]
 */
class BomSpreadsheetParser
{
    private const VALID_UNITS = [
        'pièce', 'kg', 'g', 'litre', 'cl', 'ml', 'm', 'cm', 'm²', 'm³',
        'boîte', 'carton', 'sac', 'heure', 'jour', 'forfait',
    ];

    // ── Public API ────────────────────────────────────────────────────────

    /** @return array<int, array<string, mixed>> */
    public function parseCsv(string $rawCsv): array
    {
        $rawCsv = preg_replace('/^\xEF\xBB\xBF/', '', $rawCsv);
        $lines  = preg_split('/\r\n|\r|\n/', trim($rawCsv));
        if (empty($lines)) return [];

        $headerLine = array_shift($lines);
        $sep        = substr_count($headerLine, ';') > substr_count($headerLine, ',') ? ';' : ',';
        $headers    = str_getcsv($headerLine, $sep);

        $rows = [];
        foreach ($lines as $line) {
            if (trim($line) === '') continue;
            $cols = str_getcsv($line, $sep);
            if (empty(array_filter($cols, fn ($c) => trim((string) $c) !== ''))) continue;
            $rows[] = $cols;
        }

        return $this->groupRows($headers, $rows);
    }

    /** @return array<int, array<string, mixed>> */
    public function parseXlsx(string $path): array
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $sheet  = $reader->load($path)->getActiveSheet();
        $raw    = $sheet->toArray(null, true, true, false);

        $headers  = null;
        $dataRows = [];
        foreach ($raw as $row) {
            if (empty(array_filter($row, fn ($c) => trim((string) $c) !== ''))) continue;
            if ($headers === null) {
                $headers = array_map(fn ($c) => (string) $c, $row);
                continue;
            }
            $dataRows[] = array_map(fn ($c) => $c === null ? '' : (string) $c, $row);
        }

        if ($headers === null) return [];
        return $this->groupRows($headers, $dataRows);
    }

    // ── Internal ──────────────────────────────────────────────────────────

    /** Maps raw rows → groups by product */
    private function groupRows(array $headers, array $rows): array
    {
        $keys = array_map(fn ($h) => $this->normalizeHeader((string) $h), $headers);

        // Parse each flat row
        $flat = [];
        foreach ($rows as $cols) {
            $row = [];
            foreach ($keys as $i => $key) {
                $row[$key] = isset($cols[$i]) ? trim((string) $cols[$i]) : '';
            }
            $parsed = $this->mapRow($row);
            if ($parsed) $flat[] = $parsed;
        }

        // Group by product_name
        $groups = [];
        foreach ($flat as $row) {
            $pname = $row['product_name'];
            if (! isset($groups[$pname])) {
                $groups[$pname] = [
                    'product_name'     => $pname,
                    'bom_quantity'     => $row['bom_quantity'],
                    'waste_percentage' => $row['waste_percentage'],
                    'ingredients'      => [],
                ];
            }
            if ($row['ingredient_name'] !== '') {
                $groups[$pname]['ingredients'][] = [
                    'name'     => $row['ingredient_name'],
                    'quantity' => $row['ingredient_quantity'],
                    'unit'     => $row['ingredient_unit'],
                ];
            }
        }

        return array_values($groups);
    }

    private function normalizeHeader(string $h): string
    {
        $h = trim(mb_strtolower($h));
        $h = strtr($h, [
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'à' => 'a',
            'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ù' => 'u', 'ç' => 'c',
        ]);

        return match (true) {
            in_array($h, ['produit', 'product', 'product_name', 'recette', 'recipe', 'plat', 'dish', 'fini', 'finished', 'nom_produit'], true)
                => 'product_name',

            in_array($h, ['bom_qty', 'qte_bom', 'quantite_base', 'base_qty', 'portions', 'bom_quantity'], true)
                || str_contains($h, 'bom')
                => 'bom_quantity',

            in_array($h, ['perte', 'waste', 'dechet', 'loss', 'waste_percentage', 'taux_perte'], true)
                => 'waste_percentage',

            in_array($h, ['ingredient', 'ingredient_name', 'matiere', 'material', 'composant', 'nom_ingredient'], true)
                || str_contains($h, 'ingredient') || str_contains($h, 'matiere')
                => 'ingredient_name',

            in_array($h, ['quantite', 'qty', 'qte', 'quantity', 'ingredient_quantity', 'ingredient_qty', 'qte_ingredient'], true)
                => 'ingredient_quantity',

            in_array($h, ['unite', 'unit', 'ingredient_unit'], true)
                => 'ingredient_unit',

            default => $h,
        };
    }

    private function mapRow(array $row): ?array
    {
        $productName    = trim((string) ($row['product_name']    ?? ''));
        $ingredientName = trim((string) ($row['ingredient_name'] ?? ''));

        if ($productName === '') return null;

        $bomQty  = $this->toFloat($row['bom_quantity']     ?? '', 1.0);
        $waste   = $this->toFloat($row['waste_percentage'] ?? '', 0.0);
        $ingQty  = $this->toFloat($row['ingredient_quantity'] ?? '', 1.0);
        $ingUnit = $this->normalizeUnit($row['ingredient_unit'] ?? '');

        return [
            'product_name'      => $productName,
            'bom_quantity'      => max(0.001, $bomQty),
            'waste_percentage'  => max(0.0, min(100.0, $waste)),
            'ingredient_name'   => $ingredientName,
            'ingredient_quantity' => max(0.001, $ingQty),
            'ingredient_unit'   => $ingUnit,
        ];
    }

    private function toFloat(string $val, float $default): float
    {
        $val = trim($val);
        if ($val === '') return $default;
        $val = str_replace([' ', ','], ['', '.'], $val);
        $val = preg_replace('/[^\d.\-]/', '', $val) ?? '';
        return $val !== '' ? (float) $val : $default;
    }

    private function normalizeUnit(string $u): string
    {
        $u = trim(mb_strtolower($u));
        $u = strtr($u, ['é' => 'e', 'è' => 'e', 'ê' => 'e', 'à' => 'a', 'î' => 'i', 'ç' => 'c']);
        $u = match ($u) {
            'l', 'litres', 'liter', 'liters' => 'litre',
            'pieces', 'piece', 'pce', 'pcs', 'p', 'unite', 'u' => 'pièce',
            'grammes', 'gramme', 'gr', 'grams', 'gram' => 'g',
            'kilos', 'kilo', 'kilogramme', 'kilogrammes' => 'kg',
            '' => 'pièce',
            default => $u,
        };
        return in_array($u, self::VALID_UNITS, true) ? $u : 'pièce';
    }
}
