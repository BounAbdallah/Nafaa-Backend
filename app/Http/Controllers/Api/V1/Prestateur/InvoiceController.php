<?php
namespace App\Http\Controllers\Api\V1\Prestateur;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\{JsonResponse, Request};

class InvoiceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tid = $request->user()->tenant_id;
        $q   = Invoice::where('tenant_id', $tid)->with('customer:id,name,email');

        if ($request->filled('status'))   $q->where('status', $request->status);
        if ($request->filled('search'))   $q->where(fn($s) => $s->where('reference','like',"%{$request->search}%")->orWhere('title','like',"%{$request->search}%"));
        if ($request->filled('customer')) $q->where('customer_id', $request->customer);
        if ($request->filled('overdue'))  $q->where('status','!=','paid')->where('due_at','<',now());

        // Auto-passer en overdue
        Invoice::where('tenant_id', $tid)
            ->where('status', 'sent')
            ->where('due_at', '<', now()->toDateString())
            ->update(['status' => 'overdue']);

        return response()->json($q->orderByDesc('issued_at')->paginate(20));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title'       => 'required|string|max:255',
            'customer_id' => 'nullable|integer',
            'quote_id'    => 'nullable|integer',
            'content'     => 'nullable|string',
            'issued_at'   => 'required|date',
            'due_at'      => 'nullable|date',
            'tax_rate'    => 'numeric|min:0|max:100',
            'discount'    => 'numeric|min:0',
            'currency'    => 'string|max:10',
            'notes'       => 'nullable|string',
            'terms'       => 'nullable|string',
            'items'       => 'array',
            'items.*.description' => 'nullable|string',
            'items.*.quantity'    => 'required|numeric|min:0',
            'items.*.unit_price'  => 'required|numeric|min:0',
        ]);

        $data['tenant_id'] = $request->user()->tenant_id;
        $data['reference'] = $this->generateRef($request->user()->tenant_id);
        $data['status']    = 'draft';

        $items = $data['items'] ?? [];
        unset($data['items']);

        $invoice = Invoice::create($data);
        $this->syncItems($invoice, $items);
        $invoice->recalculate();

        return response()->json($invoice->load(['customer','items']), 201);
    }

    public function show(Request $request, Invoice $invoice): JsonResponse
    {
        $this->authorize($request, $invoice);
        return response()->json($invoice->load(['customer','items','quote:id,reference,title']));
    }

    public function update(Request $request, Invoice $invoice): JsonResponse
    {
        $this->authorize($request, $invoice);
        $data = $request->validate([
            'title'      => 'sometimes|string|max:255',
            'customer_id'=> 'nullable|integer',
            'content'    => 'nullable|string',
            'issued_at'  => 'sometimes|date',
            'due_at'     => 'nullable|date',
            'status'     => 'sometimes|in:draft,sent,paid,overdue,cancelled',
            'tax_rate'   => 'numeric|min:0|max:100',
            'discount'   => 'numeric|min:0',
            'currency'   => 'string|max:10',
            'paid_at'    => 'nullable|date',
            'notes'      => 'nullable|string',
            'terms'      => 'nullable|string',
            'items'      => 'sometimes|array',
            'items.*.description' => 'nullable|string',
            'items.*.quantity'    => 'required_with:items|numeric|min:0',
            'items.*.unit_price'  => 'required_with:items|numeric|min:0',
        ]);

        // Marquer paid_at automatiquement
        if (isset($data['status']) && $data['status'] === 'paid' && !$invoice->paid_at) {
            $data['paid_at'] = now()->toDateString();
        }

        $items = $data['items'] ?? null;
        unset($data['items']);
        $invoice->update($data);
        if ($items !== null) $this->syncItems($invoice, $items);
        $invoice->recalculate();

        return response()->json($invoice->load(['customer','items']));
    }

    public function destroy(Request $request, Invoice $invoice): JsonResponse
    {
        $this->authorize($request, $invoice);
        $invoice->delete();
        return response()->json(null, 204);
    }

    /** Télécharge la facture en PDF */
    public function downloadPdf(Request $request, Invoice $invoice)
    {
        $this->authorize($request, $invoice);
        $invoice->load(['customer', 'items', 'tenant']);

        $pdf = Pdf::loadView('pdf.prestateur_invoice', compact('invoice'));
        $pdf->setPaper('a4');

        return $pdf->download("facture-{$invoice->reference}.pdf");
    }

    private function syncItems(Invoice $invoice, array $items): void
    {
        $invoice->items()->delete();
        // Ignorer les lignes sans description
        $items = array_values(array_filter($items, fn($it) => !empty(trim($it['description'] ?? ''))));
        foreach ($items as $i => $item) {
            $total = round(($item['quantity'] ?? 1) * ($item['unit_price'] ?? 0), 2);
            $invoice->items()->create([
                'description' => $item['description'],
                'quantity'    => $item['quantity'],
                'unit_price'  => $item['unit_price'],
                'total'       => $total,
                'sort_order'  => $i,
            ]);
        }
    }

    private function generateRef(int $tenantId): string
    {
        $year  = date('Y');
        $count = Invoice::where('tenant_id', $tenantId)->whereYear('created_at', $year)->count() + 1;
        return 'FAC-' . $year . '-' . str_pad($count, 4, '0', STR_PAD_LEFT);
    }

    private function authorize(Request $request, Invoice $invoice): void
    {
        abort_if($invoice->tenant_id !== $request->user()->tenant_id, 403);
    }
}
