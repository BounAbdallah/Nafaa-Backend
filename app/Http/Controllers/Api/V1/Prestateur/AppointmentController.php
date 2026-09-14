<?php
namespace App\Http\Controllers\Api\V1\Prestateur;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use Illuminate\Http\{JsonResponse, Request};

class AppointmentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tid = $request->user()->tenant_id;
        $q   = Appointment::where('tenant_id', $tid)->with('customer:id,name,phone');

        if ($request->filled('month')) {
            $q->whereYear('start_at',  substr($request->month, 0, 4))
              ->whereMonth('start_at', substr($request->month, 5, 2));
        }
        if ($request->filled('from')) $q->whereDate('start_at', '>=', $request->from);
        if ($request->filled('to'))   $q->whereDate('start_at', '<=', $request->to);
        if ($request->filled('status')) $q->where('status', $request->status);

        return response()->json($q->orderBy('start_at')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title'       => 'required|string|max:255',
            'customer_id' => 'nullable|integer',
            'description' => 'nullable|string',
            'location'    => 'nullable|string|max:255',
            'start_at'    => 'required|date',
            'end_at'      => 'required|date|after:start_at',
            'status'      => 'in:scheduled,confirmed,completed,cancelled',
            'color'       => 'nullable|string|max:7',
            'notes'       => 'nullable|string',
        ]);
        $data['tenant_id'] = $request->user()->tenant_id;
        $appt = Appointment::create($data);
        return response()->json($appt->load('customer:id,name'), 201);
    }

    public function show(Request $request, Appointment $appointment): JsonResponse
    {
        $this->authorize($request, $appointment);
        return response()->json($appointment->load('customer'));
    }

    public function update(Request $request, Appointment $appointment): JsonResponse
    {
        $this->authorize($request, $appointment);
        $data = $request->validate([
            'title'       => 'sometimes|string|max:255',
            'customer_id' => 'nullable|integer',
            'description' => 'nullable|string',
            'location'    => 'nullable|string',
            'start_at'    => 'sometimes|date',
            'end_at'      => 'sometimes|date',
            'status'      => 'sometimes|in:scheduled,confirmed,completed,cancelled',
            'color'       => 'nullable|string|max:7',
            'notes'       => 'nullable|string',
        ]);
        $appointment->update($data);
        return response()->json($appointment->load('customer'));
    }

    public function destroy(Request $request, Appointment $appointment): JsonResponse
    {
        $this->authorize($request, $appointment);
        $appointment->delete();
        return response()->json(null, 204);
    }

    private function authorize(Request $request, Appointment $appt): void
    {
        abort_if($appt->tenant_id !== $request->user()->tenant_id, 403);
    }
}
