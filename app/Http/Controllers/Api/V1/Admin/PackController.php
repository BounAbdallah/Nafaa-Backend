<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Admin\PackResource;
use App\Models\Pack;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PackController extends Controller
{
    public function index(): JsonResponse
    {
        $packs = Pack::orderBy('order')->get();

        return response()->json([
            'success' => true,
            'data'    => ['packs' => PackResource::collection($packs)],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'        => 'required|string|max:255',
            'description' => 'nullable|string',
            'price'       => 'required|numeric|min:0',
            'period'      => 'required|string|in:monthly,yearly',
            'features'    => 'nullable|array',
            'features.*'  => 'string',
            'limits'      => 'nullable',
            'is_active'   => 'nullable|boolean',
            'order'       => 'nullable|integer',
        ]);

        $data['slug'] = Str::slug($data['name']);

        $pack = Pack::create($data);

        return response()->json([
            'success' => true,
            'message' => 'Pack créé avec succès.',
            'data'    => ['pack' => new PackResource($pack)],
        ]);
    }

    public function show(Pack $pack): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => ['pack' => new PackResource($pack)],
        ]);
    }

    public function update(Request $request, Pack $pack): JsonResponse
    {
        $data = $request->validate([
            'name'        => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'price'       => 'nullable|numeric|min:0',
            'period'      => 'nullable|string|in:monthly,yearly',
            'features'    => 'nullable|array',
            'features.*'  => 'string',
            'limits'      => 'nullable',
            'is_active'   => 'nullable|boolean',
            'order'       => 'nullable|integer',
        ]);

        if (!empty($data['name'])) {
            $data['slug'] = Str::slug($data['name']);
        }

        // Remove nulls but keep false and 0
        $updateData = array_filter($data, fn($v) => $v !== null);
        $pack->update($updateData);

        return response()->json([
            'success' => true,
            'message' => 'Pack mis à jour avec succès.',
            'data'    => ['pack' => new PackResource($pack->fresh())],
        ]);
    }

    public function destroy(Pack $pack): JsonResponse
    {
        $pack->delete();

        return response()->json([
            'success' => true,
            'message' => 'Pack supprimé avec succès.',
        ]);
    }
}
