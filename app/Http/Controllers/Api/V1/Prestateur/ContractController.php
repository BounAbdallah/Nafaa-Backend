<?php
namespace App\Http\Controllers\Api\V1\Prestateur;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use Illuminate\Http\{JsonResponse, Request};

class ContractController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tid = $request->user()->tenant_id;
        $q   = Contract::where('tenant_id', $tid)->with('customer:id,name,email');

        if ($request->filled('status'))   $q->where('status', $request->status);
        if ($request->filled('search'))   $q->where(fn($s) => $s->where('reference','like',"%{$request->search}%")->orWhere('title','like',"%{$request->search}%"));

        return response()->json($q->orderByDesc('created_at')->paginate(20));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title'       => 'required|string|max:255',
            'customer_id' => 'nullable|integer',
            'quote_id'    => 'nullable|integer',
            'content'     => 'required|string',
            'signed_at'   => 'nullable|date',
            'starts_at'   => 'nullable|date',
            'ends_at'     => 'nullable|date',
            'status'      => 'in:draft,sent,signed,expired,cancelled',
            'value'       => 'nullable|numeric|min:0',
            'currency'    => 'string|max:10',
            'notes'       => 'nullable|string',
        ]);
        $data['tenant_id'] = $request->user()->tenant_id;
        $data['reference'] = $this->generateRef($request->user()->tenant_id);

        return response()->json(Contract::create($data)->load('customer'), 201);
    }

    public function show(Request $request, Contract $contract): JsonResponse
    {
        $this->authorize($request, $contract);
        return response()->json($contract->load(['customer','quote:id,reference,title']));
    }

    public function update(Request $request, Contract $contract): JsonResponse
    {
        $this->authorize($request, $contract);
        $data = $request->validate([
            'title'       => 'sometimes|string|max:255',
            'customer_id' => 'nullable|integer',
            'content'     => 'sometimes|string',
            'signed_at'   => 'nullable|date',
            'starts_at'   => 'nullable|date',
            'ends_at'     => 'nullable|date',
            'status'      => 'sometimes|in:draft,sent,signed,expired,cancelled',
            'value'       => 'nullable|numeric|min:0',
            'currency'    => 'string|max:10',
            'notes'       => 'nullable|string',
        ]);
        $contract->update($data);
        return response()->json($contract->load('customer'));
    }

    public function destroy(Request $request, Contract $contract): JsonResponse
    {
        $this->authorize($request, $contract);
        $contract->delete();
        return response()->json(null, 204);
    }

    private function generateRef(int $tenantId): string
    {
        $year  = date('Y');
        $count = Contract::where('tenant_id', $tenantId)->whereYear('created_at', $year)->count() + 1;
        return 'CTR-' . $year . '-' . str_pad($count, 4, '0', STR_PAD_LEFT);
    }

    private function authorize(Request $request, Contract $contract): void
    {
        abort_if($contract->tenant_id !== $request->user()->tenant_id, 403);
    }
}
