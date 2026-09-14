<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Services\AccountingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class JournalController extends Controller
{
    public function __construct(private readonly AccountingService $accounting) {}

    private function assertEnabled(): void
    {
        abort_unless(Auth::user()->tenant?->hasFeature('accounting'), 403,
            'La comptabilité n\'est pas activée sur votre abonnement.');
        abort_unless(Auth::user()->canDo('accounting', 'view'), 403, 'Permission refusée.');
    }

    // ── Journal ───────────────────────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $this->assertEnabled();
        $tenantId = Auth::user()->tenant_id;

        $query = JournalEntry::where('tenant_id', $tenantId)
            ->with(['lines.account', 'user:id,name'])
            ->orderByDesc('entry_date')
            ->orderByDesc('id');

        if ($request->filled('period'))  $query->where('period', $request->period);
        if ($request->filled('from'))    $query->whereDate('entry_date', '>=', $request->from);
        if ($request->filled('to'))      $query->whereDate('entry_date', '<=', $request->to);
        if ($request->filled('source'))  $query->where('source', $request->source);
        if ($search = $request->get('search')) {
            $query->where(fn($q) => $q->where('description', 'like', "%$search%")
                                      ->orWhere('reference', 'like', "%$search%"));
        }

        $entries = $query->paginate($request->get('per_page', 25));

        return response()->json([
            'success' => true,
            'data'    => [
                'entries' => $entries->map(fn($e) => $this->formatEntry($e)),
                'meta'    => [
                    'total'        => $entries->total(),
                    'current_page' => $entries->currentPage(),
                    'last_page'    => $entries->lastPage(),
                ],
            ],
        ]);
    }

    /** Saisie manuelle d'une écriture comptable. */
    public function store(Request $request): JsonResponse
    {
        $this->assertEnabled();
        abort_unless(Auth::user()->canDo('accounting', 'create'), 403, 'Permission refusée.');

        $data = $request->validate([
            'description'   => 'required|string|max:255',
            'entry_date'    => 'required|date',
            'lines'         => 'required|array|min:2',
            'lines.*.account_number' => 'required|string',
            'lines.*.debit'          => 'required|numeric|min:0',
            'lines.*.credit'         => 'required|numeric|min:0',
            'lines.*.label'          => 'nullable|string|max:255',
        ]);

        $tenantId = Auth::user()->tenant_id;

        // Vérifier équilibre débit/crédit
        $totalDebit  = array_sum(array_column($data['lines'], 'debit'));
        $totalCredit = array_sum(array_column($data['lines'], 'credit'));
        if (round($totalDebit, 2) !== round($totalCredit, 2)) {
            return response()->json([
                'success' => false,
                'message' => "L'écriture n'est pas équilibrée (débits ≠ crédits).",
            ], 422);
        }

        // Résoudre les comptes
        $lines = [];
        foreach ($data['lines'] as $l) {
            $account = Account::where('tenant_id', $tenantId)
                ->where('number', $l['account_number'])
                ->first();
            if (!$account) {
                return response()->json(['success' => false, 'message' => "Compte {$l['account_number']} introuvable."], 422);
            }
            $lines[] = [
                'account_id' => $account->id,
                'debit'      => (float)$l['debit'],
                'credit'     => (float)$l['credit'],
                'label'      => $l['label'] ?? null,
            ];
        }

        $period = substr($data['entry_date'], 0, 7);
        $entry  = DB::transaction(function () use ($data, $lines, $tenantId, $period) {
            $entry = JournalEntry::create([
                'tenant_id'   => $tenantId,
                'user_id'     => Auth::id(),
                'description' => $data['description'],
                'entry_date'  => $data['entry_date'],
                'period'      => $period,
                'source'      => 'manual',
            ]);
            $entry->lines()->createMany($lines);
            return $entry->load('lines.account', 'user:id,name');
        });

        return response()->json([
            'success' => true,
            'message' => 'Écriture enregistrée.',
            'data'    => ['entry' => $this->formatEntry($entry)],
        ], 201);
    }

    public function destroy(JournalEntry $journalEntry): JsonResponse
    {
        $this->assertEnabled();
        abort_unless(Auth::user()->canDo('accounting', 'delete'), 403, 'Permission refusée.');
        abort_if($journalEntry->tenant_id !== Auth::user()->tenant_id, 403);
        abort_if($journalEntry->is_locked, 422, 'Période clôturée — écriture non modifiable.');
        abort_if($journalEntry->source !== 'manual', 422, 'Seules les écritures manuelles peuvent être supprimées.');

        $journalEntry->delete();
        return response()->json(['success' => true, 'message' => 'Écriture supprimée.']);
    }

    // ── Plan des comptes ──────────────────────────────────────────────────────

    public function accounts(Request $request): JsonResponse
    {
        $this->assertEnabled();
        $tenantId = Auth::user()->tenant_id;

        // Initialise le plan si le tenant n'en a pas encore
        if (!Account::where('tenant_id', $tenantId)->exists()) {
            $this->accounting->ensureChartOfAccounts($tenantId);
        }

        $query = Account::where('tenant_id', $tenantId)->where('is_active', true)->orderBy('number');
        if ($request->filled('class')) $query->where('class', $request->class);
        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(fn($q) => $q->where('number', 'like', "%$s%")->orWhere('label', 'like', "%$s%"));
        }

        $accounts = $query->get()->map(fn($a) => [
            'id'        => $a->id,
            'number'    => $a->number,
            'label'     => $a->label,
            'class'     => $a->class,
            'class_label' => Account::classLabel($a->class),
            'nature'    => $a->nature,
            'is_system' => $a->is_system,
        ]);

        return response()->json(['success' => true, 'data' => ['accounts' => $accounts]]);
    }

    public function storeAccount(Request $request): JsonResponse
    {
        $this->assertEnabled();
        abort_unless(Auth::user()->canDo('accounting', 'create'), 403, 'Permission refusée.');

        $data = $request->validate([
            'number' => 'required|string|max:20',
            'label'  => 'required|string|max:255',
            'class'  => 'required|integer|min:1|max:7',
            'nature' => 'required|in:asset,liability,equity,revenue,expense,stock,treasury',
        ]);

        $tenantId = Auth::user()->tenant_id;

        if (Account::where('tenant_id', $tenantId)->where('number', $data['number'])->exists()) {
            return response()->json(['success' => false, 'message' => 'Ce numéro de compte existe déjà.'], 422);
        }

        $account = Account::create([...$data, 'tenant_id' => $tenantId, 'is_system' => false]);

        return response()->json(['success' => true, 'message' => 'Compte créé.', 'data' => ['account' => $account]], 201);
    }

    // ── Grand livre ───────────────────────────────────────────────────────────

    public function ledger(Request $request): JsonResponse
    {
        $this->assertEnabled();
        $request->validate(['account' => 'required|string', 'from' => 'required|date', 'to' => 'required|date']);

        $tenantId = Auth::user()->tenant_id;
        $data = $this->accounting->ledger($tenantId, $request->account, $request->from, $request->to);

        return response()->json(['success' => true, 'data' => $data]);
    }

    // ── Balance ───────────────────────────────────────────────────────────────

    public function balance(Request $request): JsonResponse
    {
        $this->assertEnabled();
        $request->validate(['from' => 'required|date', 'to' => 'required|date']);

        $tenantId = Auth::user()->tenant_id;
        $rows = $this->accounting->balance($tenantId, $request->from, $request->to);

        $totalDebit  = array_sum(array_column($rows, 'total_debit'));
        $totalCredit = array_sum(array_column($rows, 'total_credit'));

        return response()->json([
            'success' => true,
            'data'    => [
                'rows'         => $rows,
                'total_debit'  => $totalDebit,
                'total_credit' => $totalCredit,
                'is_balanced'  => round($totalDebit, 2) === round($totalCredit, 2),
            ],
        ]);
    }

    // ── Bilan & Résultat ──────────────────────────────────────────────────────

    public function bilan(Request $request): JsonResponse
    {
        $this->assertEnabled();
        $request->validate(['to' => 'required|date']);

        $tenantId = Auth::user()->tenant_id;
        $data = $this->accounting->bilan($tenantId, $request->to);

        return response()->json(['success' => true, 'data' => $data]);
    }

    public function resultat(Request $request): JsonResponse
    {
        $this->assertEnabled();
        $request->validate(['from' => 'required|date', 'to' => 'required|date']);

        $tenantId = Auth::user()->tenant_id;
        $data = $this->accounting->resultat($tenantId, $request->from, $request->to);

        return response()->json(['success' => true, 'data' => $data]);
    }

    // ── Init plan des comptes ──────────────────────────────────────────────────

    public function initChart(): JsonResponse
    {
        $this->assertEnabled();
        $tenantId = Auth::user()->tenant_id;
        $this->accounting->ensureChartOfAccounts($tenantId);
        $count = Account::where('tenant_id', $tenantId)->count();
        return response()->json(['success' => true, 'message' => "$count comptes initialisés."]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function formatEntry(JournalEntry $e): array
    {
        return [
            'id'          => $e->id,
            'reference'   => $e->reference,
            'description' => $e->description,
            'entry_date'  => $e->entry_date?->toDateString(),
            'period'      => $e->period,
            'source'      => $e->source,
            'source_id'   => $e->source_id,
            'is_locked'   => $e->is_locked,
            'by'          => $e->user?->name,
            'lines'       => $e->lines->map(fn($l) => [
                'account_number' => $l->account?->number,
                'account_label'  => $l->account?->label,
                'debit'          => $l->debit,
                'credit'         => $l->credit,
                'label'          => $l->label,
            ]),
            'total_debit'  => $e->lines->sum('debit'),
            'total_credit' => $e->lines->sum('credit'),
            'created_at'   => $e->created_at->toIso8601String(),
        ];
    }
}
