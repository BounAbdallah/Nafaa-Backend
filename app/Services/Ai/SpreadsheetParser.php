<?php

namespace App\Services\Ai;

use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Unified parser for product/material imports.
 *
 * Two entry points:
 *   - parseCsv(string $rawCsv) — CSV text (also handles UTF-8 BOM, ; / , separator)
 *   - parseXlsx(string $path)  — .xlsx file (first sheet only)
 *
 * Both produce the same item shape consumed by BulkCreateProductsTool:
 *   [ ['name' => 'Tomate', 'category' => 'Légumes', 'cost_price' => 500, 'unit' => 'kg', 'stock_quantity' => 3], ... ]
 *
 * Tolerates:
 *   - common FR / EN column names (Nom / Matière, Catégorie, Prix d'achat, Stock, Unité)
 *   - quoted values, UTF-8 BOM
 *   - combined cells like "500 FCFA / kg" (auto-extracts price + unit)
 *   - French decimal separator ("0,5 kg")
 */
class SpreadsheetParser
{
    private const VALID_UNITS = [
        'pièce', 'kg', 'g', 'litre', 'cl', 'ml', 'm', 'cm', 'm²', 'm³',
        'boîte', 'carton', 'sac', 'heure', 'jour', 'forfait',
    ];

    // ─── PUBLIC API ───────────────────────────────────────────────────────

    /**
     * @return array<int, array<string, mixed>>
     */
    public function parseCsv(string $rawCsv): array
    {
        $rawCsv = preg_replace('/^\xEF\xBB\xBF/', '', $rawCsv);
        $lines  = preg_split('/\r\n|\r|\n/', trim($rawCsv));
        if (empty($lines)) return [];

        $headerLine = array_shift($lines);
        $separator  = substr_count($headerLine, ';') > substr_count($headerLine, ',') ? ';' : ',';
        $headers    = str_getcsv($headerLine, $separator);

        $rows = [];
        foreach ($lines as $line) {
            if (trim($line) === '') continue;
            $cols = str_getcsv($line, $separator);
            if (empty(array_filter($cols, fn ($c) => trim((string) $c) !== ''))) continue;
            $rows[] = $cols;
        }

        return $this->mapHeadersAndRows($headers, $rows);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function parseXlsx(string $path): array
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($path);
        $sheet       = $spreadsheet->getActiveSheet();
        $rowsRaw     = $sheet->toArray(null, true, true, false);

        if (empty($rowsRaw)) return [];

        // First non-empty row = headers
        $headers = null;
        $dataRows = [];
        foreach ($rowsRaw as $row) {
            $isEmpty = empty(array_filter($row, fn ($c) => trim((string) $c) !== ''));
            if ($isEmpty) continue;

            if ($headers === null) {
                $headers = array_map(fn ($c) => (string) $c, $row);
                continue;
            }
            $dataRows[] = array_map(fn ($c) => $c === null ? '' : (string) $c, $row);
        }

        if ($headers === null) return [];

        return $this->mapHeadersAndRows($headers, $dataRows);
    }

    // ─── INTERNAL ─────────────────────────────────────────────────────────

    /**
     * @param array<int, string>      $headers
     * @param array<int, array<int, string>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function mapHeadersAndRows(array $headers, array $rows): array
    {
        $normalizedHeaders = array_map(fn ($h) => $this->normalizeHeader((string) $h), $headers);

        $items = [];
        foreach ($rows as $cols) {
            $row = [];
            foreach ($normalizedHeaders as $i => $key) {
                $row[$key] = isset($cols[$i]) ? trim((string) $cols[$i]) : '';
            }
            $item = $this->mapRow($row);
            if ($item !== null) $items[] = $item;
        }

        return $items;
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
            str_contains($h, 'stock') || str_contains($h, 'qty') || str_contains($h, 'quantite')
                                                   => 'stock_quantity',
            str_contains($h, 'unit')               => 'unit',
            str_contains($h, 'alert')              => 'stock_alert',
            str_contains($h, 'desc')               => 'description',
            str_contains($h, 'type')               => 'type',
            default                                => $h,
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    private function mapRow(array $row): ?array
    {
        $name = trim((string) ($row['name'] ?? ''));
        if ($name === '') return null;

        $item = ['name' => $name];

        if (! empty($row['category']))    $item['category']    = $row['category'];
        if (! empty($row['description'])) $item['description'] = $row['description'];
        if (! empty($row['type']))        $item['type']        = strtolower($row['type']);

        [$costPrice, $costUnit] = $this->extractAmountAndUnit((string) ($row['cost_price'] ?? ''));
        if ($costPrice !== null) $item['cost_price'] = $costPrice;

        [$sellingPrice, $sellingUnit] = $this->extractAmountAndUnit((string) ($row['selling_price'] ?? ''));
        if ($sellingPrice !== null) $item['selling_price'] = $sellingPrice;

        [$stockQty, $stockUnit] = $this->extractAmountAndUnit((string) ($row['stock_quantity'] ?? ''));
        if ($stockQty !== null) $item['stock_quantity'] = $stockQty;

        $unit = $row['unit'] ?? $stockUnit ?? $costUnit ?? $sellingUnit;
        if ($unit) {
            $unit = $this->normalizeUnit((string) $unit);
            if (in_array($unit, self::VALID_UNITS, true)) $item['unit'] = $unit;
        }

        if (isset($row['stock_alert']) && $row['stock_alert'] !== '') {
            $item['stock_alert'] = (int) preg_replace('/[^\d]/', '', (string) $row['stock_alert']);
        }

        return $item;
    }

    /**
     * @return array{0: float|null, 1: string|null}
     */
    private function extractAmountAndUnit(string $cell): array
    {
        $cell = trim($cell);
        if ($cell === '') return [null, null];

        $numericPart = (string) preg_replace('/[^\d,.\-]/', '', $cell);
        $numericPart = str_replace(',', '.', $numericPart);
        if (substr_count($numericPart, '.') > 1) {
            $parts = explode('.', $numericPart);
            $numericPart = implode('', array_slice($parts, 0, -1)).'.'.end($parts);
        }
        $amount = $numericPart === '' ? null : (float) $numericPart;

        $lower = mb_strtolower($cell);
        $unit  = null;
        foreach (self::VALID_UNITS as $u) {
            if (preg_match('/(?:^|[\/\s])' . preg_quote($u, '/') . '(?:$|[\s\d\)])/u', $lower)) {
                $unit = $u;
                break;
            }
        }
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
