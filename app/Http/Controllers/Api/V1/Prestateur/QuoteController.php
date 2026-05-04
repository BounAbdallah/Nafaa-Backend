<?php
namespace App\Http\Controllers\Api\V1\Prestateur;

use App\Http\Controllers\Controller;
use App\Models\{Quote, QuoteItem};
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Str;

class QuoteController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tid = $request->user()->tenant_id;
        $q   = Quote::where('tenant_id', $tid)->with('customer:id,name,email');

        if ($request->filled('status'))   $q->where('status', $request->status);
        if ($request->filled('search'))   $q->where(fn($s) => $s->where('reference','like',"%{$request->search}%")->orWhere('title','like',"%{$request->search}%"));
        if ($request->filled('customer')) $q->where('customer_id', $request->customer);

        $quotes = $q->orderByDesc('issued_at')->paginate(20);
        return response()->json($quotes);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title'       => 'required|string|max:255',
            'customer_id' => 'nullable|integer',
            'content'     => 'nullable|string',
            'issued_at'   => 'required|date',
            'expires_at'  => 'nullable|date',
            'tax_rate'    => 'numeric|min:0|max:100',
            'discount'    => 'numeric|min:0',
            'currency'    => 'string|max:10',
            'notes'       => 'nullable|string',
            'terms'       => 'nullable|string',
            'items'       => 'array',
            'items.*.description' => 'required|string',
            'items.*.quantity'    => 'required|numeric|min:0',
            'items.*.unit_price'  => 'required|numeric|min:0',
        ]);

        $data['tenant_id'] = $request->user()->tenant_id;
        $data['reference'] = $this->generateRef('DEV', $request->user()->tenant_id);
        $data['status']    = 'draft';

        $items = $data['items'] ?? [];
        unset($data['items']);

        $quote = Quote::create($data);
        $this->syncItems($quote, $items);
        $quote->recalculate();

        return response()->json($quote->load(['customer', 'items']), 201);
    }

    public function show(Request $request, Quote $quote): JsonResponse
    {
        $this->authorize($request, $quote);
        return response()->json($quote->load(['customer', 'items']));
    }

    public function update(Request $request, Quote $quote): JsonResponse
    {
        $this->authorize($request, $quote);
        $data = $request->validate([
            'title'      => 'sometimes|string|max:255',
            'customer_id'=> 'nullable|integer',
            'content'    => 'nullable|string',
            'issued_at'  => 'sometimes|date',
            'expires_at' => 'nullable|date',
            'status'     => 'sometimes|in:draft,sent,accepted,rejected,expired',
            'tax_rate'   => 'numeric|min:0|max:100',
            'discount'   => 'numeric|min:0',
            'currency'   => 'string|max:10',
            'notes'      => 'nullable|string',
            'terms'      => 'nullable|string',
            'items'      => 'sometimes|array',
            'items.*.description' => 'required_with:items|string',
            'items.*.quantity'    => 'required_with:items|numeric|min:0',
            'items.*.unit_price'  => 'required_with:items|numeric|min:0',
        ]);

        $items = $data['items'] ?? null;
        unset($data['items']);
        $quote->update($data);
        if ($items !== null) $this->syncItems($quote, $items);
        $quote->recalculate();

        return response()->json($quote->load(['customer', 'items']));
    }

    public function destroy(Request $request, Quote $quote): JsonResponse
    {
        $this->authorize($request, $quote);
        $quote->delete();
        return response()->json(null, 204);
    }

    /** Télécharge le devis en PDF */
    public function downloadPdf(Request $request, Quote $quote)
    {
        $this->authorize($request, $quote);
        $quote->load(['customer', 'items', 'tenant']);

        $pdf = Pdf::loadView('pdf.quote', compact('quote'));
        $pdf->setPaper('a4');

        return $pdf->download("devis-{$quote->reference}.pdf");
    }

    /** Convertit un devis en facture */
    public function convertToInvoice(Request $request, Quote $quote): JsonResponse
    {
        $this->authorize($request, $quote);
        $invoice = \App\Models\Invoice::create([
            'tenant_id'   => $quote->tenant_id,
            'customer_id' => $quote->customer_id,
            'quote_id'    => $quote->id,
            'reference'   => $this->generateRef('FAC', $quote->tenant_id),
            'title'       => $quote->title,
            'content'     => $quote->content,
            'issued_at'   => now()->toDateString(),
            'due_at'      => now()->addDays(30)->toDateString(),
            'status'      => 'draft',
            'tax_rate'    => $quote->tax_rate,
            'discount'    => $quote->discount,
            'currency'    => $quote->currency,
            'notes'       => $quote->notes,
            'terms'       => $quote->terms,
        ]);
        foreach ($quote->items as $item) {
            $invoice->items()->create($item->only(['description','quantity','unit_price','total','sort_order']));
        }
        $invoice->recalculate();
        $quote->update(['status' => 'accepted']);
        return response()->json($invoice->load(['customer','items']), 201);
    }

    private function syncItems(Quote $quote, array $items): void
    {
        $quote->items()->delete();
        foreach ($items as $i => $item) {
            $total = round(($item['quantity'] ?? 1) * ($item['unit_price'] ?? 0), 2);
            $quote->items()->create([
                'description' => $item['description'],
                'quantity'    => $item['quantity'],
                'unit_price'  => $item['unit_price'],
                'total'       => $total,
                'sort_order'  => $i,
            ]);
        }
    }

    private function generateRef(string $prefix, int $tenantId): string
    {
        $year  = date('Y');
        $count = match($prefix) {
            'DEV' => Quote::where('tenant_id', $tenantId)->whereYear('created_at', $year)->count() + 1,
            'FAC' => \App\Models\Invoice::where('tenant_id', $tenantId)->whereYear('created_at', $year)->count() + 1,
            default => 1,
        };
        return "{$prefix}-{$year}-" . str_pad($count, 4, '0', STR_PAD_LEFT);
    }

    private function authorize(Request $request, Quote $quote): void
    {
        abort_if($quote->tenant_id !== $request->user()->tenant_id, 403);
    }
}
