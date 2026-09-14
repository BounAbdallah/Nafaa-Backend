<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CashMovement;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/**
 * Tableau de bord financier (rôle comptable + admin).
 * Gated par la fonctionnalité « accounting ».
 */
class FinanceController extends Controller
{
    private function assertEnabled(): void
    {
        abort_unless(Auth::user()->tenant?->hasFeature('accounting'), 403,
            'La comptabilité n\'est pas activée sur votre abonnement.');
        abort_unless(Auth::user()->canDo('accounting', 'view'), 403, 'Permission refusée.');
    }

    public function summary(Request $request): JsonResponse
    {
        $this->assertEnabled();
        $data = $this->buildSummaryData($request);

        return response()->json(['success' => true, 'data' => $data]);
    }

    /** Export PDF du rapport financier. */
    public function exportPdf(Request $request): Response
    {
        $this->assertEnabled();
        $data   = $this->buildSummaryData($request);
        $tenant = Auth::user()->tenant;

        $pdf = Pdf::loadView('exports.finance-pdf', [
            'data'   => $data,
            'tenant' => $tenant,
        ])->setPaper('a4', 'portrait');

        $filename = 'rapport-financier-' . $data['period']['from'] . '-' . $data['period']['to'] . '.pdf';

        return $pdf->download($filename);
    }

    /** Export Excel du rapport financier. */
    public function exportExcel(Request $request)
    {
        $this->assertEnabled();
        $data   = $this->buildSummaryData($request);
        $tenant = Auth::user()->tenant;

        $spreadsheet = new Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Rapport Financier');

        $currency = $tenant->settings['currency'] ?? 'FCFA';

        // ── Titre ──
        $sheet->mergeCells('A1:D1');
        $sheet->setCellValue('A1', 'Rapport Financier — ' . ($tenant->name ?? 'Boutique'));
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $sheet->mergeCells('A2:D2');
        $sheet->setCellValue('A2', 'Période : ' . $data['period']['from'] . ' → ' . $data['period']['to']);
        $sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        // ── Résumé ──
        $row = 4;
        $headers = [
            ['Indicateur', 'Montant (' . $currency . ')'],
        ];
        $rows = [
            ['Ventes encaissées',          $data['sales_paid']],
            ['Remboursements clients',      $data['repayments']],
            ['Dépôts d\'avance',           $data['deposits']],
            ['Autres recettes manuelles',   $data['manual_income']],
            ['TOTAL ENTRÉES',               $data['money_in']],
            ['',                            ''],
            ['Dépenses',                    $data['expenses']],
            ['Retraits patron',             $data['manual_outflow']],
            ['TOTAL SORTIES',               $data['total_out']],
            ['',                            ''],
            ['SOLDE NET',                   $data['net']],
            ['Créances clients en cours',   $data['outstanding_debt']],
        ];

        $sheet->setCellValue('A' . $row, 'Indicateur');
        $sheet->setCellValue('B' . $row, 'Montant (' . $currency . ')');
        $sheet->getStyle('A' . $row . ':B' . $row)->getFont()->setBold(true);
        $sheet->getStyle('A' . $row . ':B' . $row)->getFill()
            ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('D1E8F0');
        $row++;

        foreach ($rows as [$label, $value]) {
            $sheet->setCellValue('A' . $row, $label);
            $sheet->setCellValue('B' . $row, $value === '' ? '' : number_format((float)$value, 0, ',', ' '));
            if (in_array($label, ['TOTAL ENTRÉES', 'TOTAL SORTIES', 'SOLDE NET'])) {
                $sheet->getStyle('A' . $row . ':B' . $row)->getFont()->setBold(true);
            }
            $row++;
        }

        // ── Dépenses par catégorie ──
        $row += 2;
        $sheet->setCellValue('A' . $row, 'Dépenses par catégorie');
        $sheet->getStyle('A' . $row)->getFont()->setBold(true)->setSize(12);
        $row++;
        $sheet->setCellValue('A' . $row, 'Catégorie');
        $sheet->setCellValue('B' . $row, 'Montant (' . $currency . ')');
        $sheet->getStyle('A' . $row . ':B' . $row)->getFont()->setBold(true);
        $sheet->getStyle('A' . $row . ':B' . $row)->getFill()
            ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('D1E8F0');
        $row++;
        foreach ($data['by_category'] as $cat) {
            $sheet->setCellValue('A' . $row, $cat['category']);
            $sheet->setCellValue('B' . $row, number_format((float)$cat['total'], 0, ',', ' '));
            $row++;
        }

        // ── Mouvements manuels ──
        if (!empty($data['cash_movements'])) {
            $row += 2;
            $sheet->setCellValue('A' . $row, 'Mouvements de trésorerie manuels');
            $sheet->getStyle('A' . $row)->getFont()->setBold(true)->setSize(12);
            $row++;
            foreach (['A' => 'Date', 'B' => 'Type', 'C' => 'Libellé', 'D' => 'Montant'] as $col => $title) {
                $sheet->setCellValue($col . $row, $title);
            }
            $sheet->getStyle('A' . $row . ':D' . $row)->getFont()->setBold(true);
            $sheet->getStyle('A' . $row . ':D' . $row)->getFill()
                ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('D1E8F0');
            $row++;
            foreach ($data['cash_movements'] as $mv) {
                $sheet->setCellValue('A' . $row, $mv['movement_date']);
                $sheet->setCellValue('B' . $row, $mv['type_label']);
                $sheet->setCellValue('C' . $row, $mv['label']);
                $sheet->setCellValue('D' . $row, number_format((float)$mv['amount'], 0, ',', ' '));
                $row++;
            }
        }

        foreach (range('A', 'D') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $filename = 'rapport-financier-' . $data['period']['from'] . '-' . $data['period']['to'] . '.xlsx';

        $writer = new Xlsx($spreadsheet);
        ob_start();
        $writer->save('php://output');
        $content = ob_get_clean();

        return response($content, 200, [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    // ── Logique partagée summary/export ──────────────────────────────────────

    private function buildSummaryData(Request $request): array
    {
        $tenantId = Auth::user()->tenant_id;

        $from = $request->filled('from')
            ? Carbon::parse($request->from)->startOfDay()
            : now()->startOfMonth();
        $to = $request->filled('to')
            ? Carbon::parse($request->to)->endOfDay()
            : now()->endOfDay();

        $salesPaid = (float) DB::table('orders')
            ->where('tenant_id', $tenantId)
            ->where('status', '!=', 'cancelled')
            ->whereNull('deleted_at')
            ->whereBetween('created_at', [$from, $to])
            ->sum('paid_amount');

        $repayments = (float) DB::table('customer_account_entries')
            ->where('tenant_id', $tenantId)->where('type', 'repayment')
            ->whereBetween('created_at', [$from, $to])->sum('amount');

        $deposits = (float) DB::table('customer_account_entries')
            ->where('tenant_id', $tenantId)->where('type', 'deposit')
            ->whereBetween('created_at', [$from, $to])->sum('amount');

        $expenses = (float) DB::table('expenses')
            ->where('tenant_id', $tenantId)
            ->whereBetween('created_at', [$from, $to])
            ->sum('amount');

        // Mouvements manuels sur la période
        $cashMovements = CashMovement::where('tenant_id', $tenantId)
            ->whereDate('movement_date', '>=', $from->toDateString())
            ->whereDate('movement_date', '<=', $to->toDateString())
            ->with('user:id,name')
            ->orderBy('movement_date')
            ->get();

        $manualIncome  = $cashMovements->whereIn('type', CashMovement::incomeTypes())->sum('amount');
        $manualOutflow = $cashMovements->whereIn('type', CashMovement::outflowTypes())->sum('amount');

        $moneyIn  = $salesPaid + $repayments + $deposits + $manualIncome;
        $totalOut = $expenses + $manualOutflow;
        $net      = $moneyIn - $totalOut;

        $outstandingDebt = abs((float) DB::table('customers')
            ->where('tenant_id', $tenantId)
            ->where('account_balance', '<', 0)
            ->sum('account_balance'));

        $byCategory = DB::table('expenses')
            ->where('tenant_id', $tenantId)
            ->whereBetween('created_at', [$from, $to])
            ->select('category', DB::raw('SUM(amount) as total'))
            ->groupBy('category')->orderByDesc('total')->get()
            ->map(fn($r) => ['category' => $r->category ?: 'Autre', 'total' => (float)$r->total]);

        $months = collect(range(5, 0))->map(function ($i) use ($tenantId) {
            $start = now()->startOfMonth()->subMonths($i);
            $end   = $start->copy()->endOfMonth();
            $in    = (float) DB::table('orders')->where('tenant_id', $tenantId)
                ->where('status', '!=', 'cancelled')->whereNull('deleted_at')
                ->whereBetween('created_at', [$start, $end])->sum('paid_amount');
            $out   = (float) DB::table('expenses')->where('tenant_id', $tenantId)
                ->whereBetween('created_at', [$start, $end])->sum('amount');
            return ['label' => $start->translatedFormat('M'), 'entrees' => $in, 'sorties' => $out];
        });

        return [
            'period'           => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'sales_paid'       => $salesPaid,
            'repayments'       => $repayments,
            'deposits'         => $deposits,
            'manual_income'    => (float) $manualIncome,
            'manual_outflow'   => (float) $manualOutflow,
            'money_in'         => $moneyIn,
            'expenses'         => $expenses,
            'total_out'        => $totalOut,
            'net'              => $net,
            'outstanding_debt' => $outstandingDebt,
            'by_category'      => $byCategory,
            'monthly'          => $months,
            'cash_movements'   => $cashMovements->map(fn($m) => [
                'type'          => $m->type,
                'type_label'    => \App\Models\CashMovement::types()[$m->type] ?? $m->type,
                'label'         => $m->label,
                'amount'        => $m->amount,
                'movement_date' => $m->movement_date?->toDateString(),
            ])->all(),
        ];
    }
}
