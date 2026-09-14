<?php

namespace App\Services\Ai;

/**
 * Light, dependency-free CSV parser tailored for product/material imports.
 *
 * Tolerates :
 *   - both `,` and `;` as separator (auto-detect on header line)
 *   - UTF-8 BOM
 *   - quoted values
 *   - common French column names (Nom / Matière, Catégorie, Prix d'achat, Stock, Unité)
 *   - "500 FCFA / kg" style cells (extracts the number + unit)
 */
class CsvParser
{
    private const VALID_UNITS = [
        'pièce', 'kg', 'g', 'litre', 'cl', 'ml', 'm', 'cm', 'm²', 'm³',
        'boîte', 'carton', 'sac', 'heure', 'jour', 'plat', 'forfait',
    ];

    /**
     * @return array<int, array<string, mixed>>  Items ready for BulkCreateProductsTool::execute
     */
    public function parse(string $rawCsv): array
    {
        // Strip BOM
        $rawCsv = preg_replace('/^\xEF\xBB\xBF/', '', $rawCsv);
        $lines  = preg_split('/\r\n|\r|\n/', trim($rawCsv));

        if (empty($lines)) return [];

        $headerLine = array_shift($lines);
        $separator  = $this->detectSeparator($headerLine);

        $headers = array_map(
            fn ($h) => $this->normalizeHeader($h),
            str_getcsv($headerLine, $separator)
        );

        $items = [];

        foreach ($lines as $line) {
            if (trim($line) === '') continue;
            $cols = str_getcsv($line, $separator);
            if (empty(array_filter($cols, fn ($c) => trim((string) $c) !== ''))) continue;

            $row = [];
            foreach ($headers as $i => $key) {
                $row[$key] = isset($cols[$i]) ? trim((string) $cols[$i]) : '';
            }

            $item = $this->mapRow($row);
            if ($item !== null) $items[] = $item;
        }

        return $items;
    }

    private function detectSeparator(string $headerLine): string
    {
        return substr_count($headerLine, ';') > substr_count($headerLine, ',') ? ';' : ',';
    }

    private function normalizeHeader(string $h): string
    {
        $h = trim(mb_strtolower($h));
        $h = strtr($h, ['é' => 'e', 'è' => 'e', 'ê' => 'e', 'à' => 'a', 'î' => 'i', 'ï' => 'i']);

        return match (true) {
            str_contains($h, 'matiere') || $h === 'nom' || str_contains($h, 'name')
                                                   => 'name',
            str_contains($h, 'categor')           => 'category',
            str_contains($h, 'prix') || str_contains($h, 'cost') || str_contains($h, 'achat')
                                                   => 'cost_price',
            str_contains($h, 'vente')              => 'selling_price',
            str_contains($h, 'montant') || $h === 'amount'
                                                   => 'amount',
            str_contains($h, 'stock') || str_contains($h, 'qty') || str_contains($h, 'quantite')
                                                   => 'stock_quantity',
            str_contains($h, 'unit')               => 'unit',
            str_contains($h, 'alert')              => 'stock_alert',
            str_contains($h, 'desc')               => 'description',
            str_contains($h, 'date')               => 'expense_date',
            str_contains($h, 'type')               => 'type',
            default                                => $h,
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    private function mapRow(array $row): ?array
    {
        $name        = trim((string) ($row['name'] ?? ''));
        $description = trim((string) ($row['description'] ?? ''));

        // For expenses, name might be empty, we use description as the fallback name
        if ($name === '' && $description !== '') {
            $name = $description;
        }

        if ($name === '') return null;

        $item = [
            'name'        => $name,
            'description' => $description,
        ];

        if (! empty($row['category']))    $item['category']    = $row['category'];
        if (! empty($row['type']))        $item['type']        = strtolower($row['type']);
        if (! empty($row['expense_date']))$item['expense_date']= $row['expense_date'];

        // Extraction of numbers + optional units
        [$costPrice, $costUnit] = $this->extractAmountAndUnit((string) ($row['cost_price'] ?? ''));
        if ($costPrice !== null) $item['cost_price'] = $costPrice;

        [$sellingPrice, $sellingUnit] = $this->extractAmountAndUnit((string) ($row['selling_price'] ?? ''));
        if ($sellingPrice !== null) $item['selling_price'] = $sellingPrice;

        [$amount, $amountUnit] = $this->extractAmountAndUnit((string) ($row['amount'] ?? ''));
        if ($amount !== null) {
            $item['amount'] = $amount;
        } elseif ($costPrice !== null) {
            // Fallback for tools expecting 'amount' but getting 'cost_price' in CSV
            $item['amount'] = $costPrice;
        }

        // Stock + unit similarly ("3 kg")
        [$stockQty, $stockUnit] = $this->extractAmountAndUnit((string) ($row['stock_quantity'] ?? ''));
        if ($stockQty !== null) $item['stock_quantity'] = $stockQty;

        // Unit precedence: explicit `unit` column > stock unit > cost unit
        $unit = $row['unit'] ?? $stockUnit ?? $costUnit ?? $sellingUnit ?? $amountUnit;
        if ($unit) {
            $unit = $this->normalizeUnit($unit);
            if (in_array($unit, self::VALID_UNITS, true)) $item['unit'] = $unit;
        }

        if (isset($row['stock_alert']) && $row['stock_alert'] !== '') {
            $item['stock_alert'] = (int) preg_replace('/[^\d]/', '', (string) $row['stock_alert']);
        }

        return $item;
    }

    /**
     * Extracts a number and an optional unit from a free-form cell.
     * Examples:
     *   "500 FCFA / kg"   → [500.0, 'kg']
     *   "1200 FCFA/litre" → [1200.0, 'litre']
     *   "3 kg"            → [3.0, 'kg']
     *   "0,5 kg"          → [0.5, 'kg']
     *   "200"             → [200.0, null]
     *
     * @return array{0: float|null, 1: string|null}
     */
    private function extractAmountAndUnit(string $cell): array
    {
        $cell = trim($cell);
        if ($cell === '') return [null, null];

        // Number: handle French commas (0,5 → 0.5)
        $numericPart = (string) preg_replace('/[^\d,.\-]/', '', $cell);
        $numericPart = str_replace(',', '.', $numericPart);
        // If there's more than one dot, keep the last one as decimal
        if (substr_count($numericPart, '.') > 1) {
            $parts = explode('.', $numericPart);
            $numericPart = implode('', array_slice($parts, 0, -1)).'.'.end($parts);
        }
        $amount = $numericPart === '' ? null : (float) $numericPart;

        // Unit detection
        $lower = mb_strtolower($cell);
        $unit  = null;
        foreach (self::VALID_UNITS as $u) {
            // Match the unit as a whole word
            if (preg_match('/(?:^|[\/\s])' . preg_quote($u, '/') . '(?:$|[\s\d\)])/u', $lower)) {
                $unit = $u;
                break;
            }
        }
        // Common alias: "L" → "litre"
        if ($unit === null && preg_match('/\b(L|l)\b/', $cell)) $unit = 'litre';

        return [$amount, $unit];
    }

    private function normalizeUnit(string $u): string
    {
        $u = trim(mb_strtolower($u));
        return match ($u) {
            'l', 'litres'           => 'litre',
            'pieces', 'pièces', 'p' => 'pièce',
            'grammes', 'gr'         => 'g',
            default                 => $u,
        };
    }
}
