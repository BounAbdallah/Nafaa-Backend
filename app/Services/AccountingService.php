<?php

namespace App\Services;

use App\Models\Account;
use App\Models\CashMovement;
use App\Models\Expense;
use App\Models\JournalEntry;
use App\Models\Order;
use App\Repositories\AccountRepository;
use Database\Seeders\AccountingSeeder;
use Illuminate\Support\Facades\DB;

/**
 * Comptabilisation en partie double SYSCOHADA.
 *
 * Chaque méthode post*() crée ou remplace une écriture liée à la source.
 * L'appel est idempotent : si l'écriture existe déjà, elle est supprimée
 * puis recréée (sauf si la période est clôturée).
 */
class AccountingService
{
    public function __construct(private readonly AccountRepository $repo) {}

    // ── Initialisation ────────────────────────────────────────────────────────

    /** Crée les comptes SYSCOHADA système si absents. */
    public function ensureChartOfAccounts(int $tenantId): void
    {
        AccountingSeeder::seedForTenant($tenantId);
    }

    // ── Comptabilisation automatique ─────────────────────────────────────────

    /** Vente encaissée → 57 Caisse / 411 Clients | 701 Ventes */
    public function postOrder(Order $order): void
    {
        if ($order->status === 'cancelled') {
            $this->deleteSource('sale', $order->id);
            return;
        }

        $total    = (float) $order->total_amount;
        $paid     = (float) $order->paid_amount;
        $credit   = $total - $paid; // partie à crédit
        $tenantId = $order->tenant_id;

        DB::transaction(function () use ($order, $total, $paid, $credit, $tenantId) {
            $this->deleteSource('sale', $order->id, $tenantId);

            $caisse  = $this->account($tenantId, '57');
            $clients = $this->account($tenantId, '411');
            $ventes  = $this->account($tenantId, '701');

            $lines = [];
            if ($paid > 0) {
                $lines[] = ['account_id' => $caisse->id,  'debit' => $paid,   'credit' => 0,      'label' => 'Encaissement'];
            }
            if ($credit > 0) {
                $lines[] = ['account_id' => $clients->id, 'debit' => $credit, 'credit' => 0,      'label' => 'Vente à crédit'];
            }
            $lines[] = ['account_id' => $ventes->id,  'debit' => 0,       'credit' => $total, 'label' => 'Produit de la vente'];

            $this->createEntry($tenantId, [
                'description' => 'Vente ' . ($order->reference ?? '#' . $order->id),
                'entry_date'  => $order->created_at->toDateString(),
                'source'      => 'sale',
                'source_id'   => $order->id,
                'user_id'     => $order->user_id ?? null,
            ], $lines);
        });
    }

    /** Dépense → 6xx Charges / 57 Caisse */
    public function postExpense(Expense $expense): void
    {
        $tenantId = $expense->tenant_id;

        DB::transaction(function () use ($expense, $tenantId) {
            $this->deleteSource('expense', $expense->id, $tenantId);

            $chargeAccount = $this->expenseAccount($tenantId, $expense->category);
            $caisse        = $this->account($tenantId, '57');

            $lines = [
                ['account_id' => $chargeAccount->id, 'debit' => $expense->amount, 'credit' => 0,                'label' => $expense->description],
                ['account_id' => $caisse->id,         'debit' => 0,                'credit' => $expense->amount, 'label' => $expense->description],
            ];

            $this->createEntry($tenantId, [
                'description' => 'Dépense : ' . $expense->description,
                'entry_date'  => $expense->expense_date->toDateString(),
                'source'      => 'expense',
                'source_id'   => $expense->id,
                'user_id'     => $expense->user_id,
            ], $lines);
        });
    }

    /** Mouvement manuel → Caisse / Compte approprié */
    public function postCashMovement(CashMovement $movement): void
    {
        $tenantId = $movement->tenant_id;

        DB::transaction(function () use ($movement, $tenantId) {
            $this->deleteSource('cash_movement', $movement->id, $tenantId);

            $caisse = $this->account($tenantId, '57');

            [$debitAcc, $creditAcc, $debit, $credit] = match ($movement->type) {
                'income'       => [$caisse,                               $this->account($tenantId, '754'), $movement->amount, $movement->amount],
                'contribution' => [$caisse,                               $this->account($tenantId, '101'), $movement->amount, $movement->amount],
                'withdrawal'   => [$this->account($tenantId, '106'),      $caisse,                          $movement->amount, $movement->amount],
            };

            $lines = [
                ['account_id' => $debitAcc->id,  'debit' => $debit,  'credit' => 0,       'label' => $movement->label],
                ['account_id' => $creditAcc->id, 'debit' => 0,       'credit' => $credit, 'label' => $movement->label],
            ];

            $this->createEntry($tenantId, [
                'description' => $movement->label,
                'entry_date'  => $movement->movement_date->toDateString(),
                'source'      => 'cash_movement',
                'source_id'   => $movement->id,
                'user_id'     => $movement->user_id,
            ], $lines);
        });
    }

    // ── Grand livre / Balance ─────────────────────────────────────────────────

    /**
     * Balance des comptes sur une période.
     * Retourne pour chaque compte utilisé : total débits, crédits, solde.
     */
    public function balance(int $tenantId, string $from, string $to): array
    {
        $rows = DB::table('journal_entry_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->where('e.tenant_id', $tenantId)
            ->whereNull('e.deleted_at')
            ->whereBetween('e.entry_date', [$from, $to])
            ->select(
                'a.id', 'a.number', 'a.label', 'a.class', 'a.nature',
                DB::raw('SUM(l.debit) as total_debit'),
                DB::raw('SUM(l.credit) as total_credit')
            )
            ->groupBy('a.id', 'a.number', 'a.label', 'a.class', 'a.nature')
            ->orderBy('a.number')
            ->get();

        return $rows->map(function ($r) {
            $debit   = (float) $r->total_debit;
            $credit  = (float) $r->total_credit;
            $isDebit = in_array($r->nature, ['asset', 'expense', 'stock', 'treasury']);
            $solde   = $isDebit ? ($debit - $credit) : ($credit - $debit);
            return [
                'number'       => $r->number,
                'label'        => $r->label,
                'class'        => $r->class,
                'class_label'  => Account::classLabel($r->class),
                'nature'       => $r->nature,
                'total_debit'  => $debit,
                'total_credit' => $credit,
                'solde'        => $solde,
                'solde_debit'  => $isDebit && $solde > 0 ? $solde : 0,
                'solde_credit' => !$isDebit && $solde > 0 ? $solde : 0,
            ];
        })->all();
    }

    /**
     * Grand livre d'un compte : liste chronologique des mouvements.
     */
    public function ledger(int $tenantId, string $accountNumber, string $from, string $to): array
    {
        $account = Account::where('tenant_id', $tenantId)->where('number', $accountNumber)->firstOrFail();

        $lines = DB::table('journal_entry_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('e.tenant_id', $tenantId)
            ->where('l.account_id', $account->id)
            ->whereNull('e.deleted_at')
            ->whereBetween('e.entry_date', [$from, $to])
            ->select('e.entry_date', 'e.description', 'e.reference', 'l.debit', 'l.credit', 'l.label')
            ->orderBy('e.entry_date')
            ->orderBy('e.id')
            ->get();

        $running = 0;
        $rows    = [];
        foreach ($lines as $l) {
            $running += (float)$l->debit - (float)$l->credit;
            $rows[] = [
                'date'        => $l->entry_date,
                'reference'   => $l->reference,
                'description' => $l->label ?: $l->description,
                'debit'       => (float)$l->debit,
                'credit'      => (float)$l->credit,
                'solde'       => $running,
            ];
        }

        return [
            'account' => ['number' => $account->number, 'label' => $account->label, 'nature' => $account->nature],
            'lines'   => $rows,
            'total_debit'  => array_sum(array_column($rows, 'debit')),
            'total_credit' => array_sum(array_column($rows, 'credit')),
        ];
    }

    /**
     * Bilan simplifié SYSCOHADA.
     * Actif = classes 2, 3, 4 (débit), 5 | Passif = classe 1, 4 (crédit), TVA
     */
    public function bilan(int $tenantId, string $to): array
    {
        $from = '1900-01-01'; // cumul depuis l'origine
        $balance = collect($this->balance($tenantId, $from, $to));

        $actif = [
            'Actif immobilisé'  => $balance->where('class', 2)->sum('solde'),
            'Stocks'            => $balance->where('class', 3)->sum('solde'),
            'Créances clients'  => $balance->whereIn('number', ['411'])->sum('solde'),
            'Trésorerie'        => $balance->where('class', 5)->sum('solde'),
        ];

        $passif = [
            'Capital & Réserves'   => $balance->whereIn('nature', ['equity'])->sum('solde'),
            'Dettes fournisseurs'  => $balance->where('number', '401')->sum('solde'),
            'Personnel'            => $balance->where('number', '421')->sum('solde'),
            'État & Impôts'        => $balance->whereIn('number', ['441', '447'])->sum('solde'),
            'Autres dettes'        => 0,
        ];

        // Résultat de l'exercice = Produits - Charges
        $produits = $balance->where('class', 7)->sum('solde');
        $charges  = $balance->where('class', 6)->sum('solde');
        $resultat = $produits - $charges;
        $passif['Résultat de l\'exercice'] = $resultat;

        return [
            'actif'          => $actif,
            'passif'         => $passif,
            'total_actif'    => array_sum($actif),
            'total_passif'   => array_sum($passif),
        ];
    }

    /**
     * Compte de résultat sur une période.
     */
    public function resultat(int $tenantId, string $from, string $to): array
    {
        $balance = collect($this->balance($tenantId, $from, $to));

        $produits = $balance->where('class', 7)->groupBy('number')->map(fn($g) => [
            'number' => $g->first()['number'],
            'label'  => $g->first()['label'],
            'total'  => $g->sum('solde'),
        ])->values()->all();

        $charges = $balance->where('class', 6)->groupBy('number')->map(fn($g) => [
            'number' => $g->first()['number'],
            'label'  => $g->first()['label'],
            'total'  => $g->sum('solde'),
        ])->values()->all();

        $totalProduits = array_sum(array_column($produits, 'total'));
        $totalCharges  = array_sum(array_column($charges, 'total'));

        return [
            'produits'       => $produits,
            'charges'        => $charges,
            'total_produits' => $totalProduits,
            'total_charges'  => $totalCharges,
            'resultat_net'   => $totalProduits - $totalCharges,
        ];
    }

    // ── Helpers privés ────────────────────────────────────────────────────────

    private function createEntry(int $tenantId, array $entryData, array $lines): JournalEntry
    {
        $period = substr($entryData['entry_date'], 0, 7);

        $entry = JournalEntry::create([
            ...$entryData,
            'tenant_id' => $tenantId,
            'period'    => $period,
            'reference' => $this->nextReference($tenantId, $period),
        ]);

        foreach ($lines as $line) {
            $entry->lines()->create($line);
        }

        return $entry;
    }

    private function deleteSource(string $source, int $sourceId, int $tenantId = 0): void
    {
        JournalEntry::where('source', $source)
            ->where('source_id', $sourceId)
            ->when($tenantId, fn($q) => $q->where('tenant_id', $tenantId))
            ->where('is_locked', false)
            ->each(fn($e) => $e->delete());
    }

    private function account(int $tenantId, string $number): Account
    {
        return $this->repo->findByNumber($tenantId, $number);
    }

    private function expenseAccount(int $tenantId, string $category): Account
    {
        // Mapping catégorie dépense → numéro de compte
        $map = [
            'loyer'         => '651',
            'electricite'   => '658',
            'eau'           => '658',
            'salaires'      => '641',
            'transport'     => '625',
            'marketing'     => '627',
            'fournitures'   => '604',
            'maintenance'   => '658',
            'communication' => '626',
            'taxes'         => '447',
            'autre'         => '658',
        ];

        $number = $map[$category] ?? '658';
        return $this->account($tenantId, $number);
    }

    private function nextReference(int $tenantId, string $period): string
    {
        $count = JournalEntry::where('tenant_id', $tenantId)
            ->where('period', $period)
            ->withTrashed()
            ->count();

        return 'JNL-' . str_replace('-', '', $period) . '-' . str_pad($count + 1, 4, '0', STR_PAD_LEFT);
    }
}
