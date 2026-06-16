<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Admin\PackResource;
use App\Models\Pack;
use App\Models\PackCountryPrice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PackController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Pack::with('countryPrices')->orderBy('order');

        // Filtre par profil : packs ciblant ce profil OU packs « tous profils » (null/vide)
        if ($profile = $request->get('profile_type')) {
            $query->where(function ($q) use ($profile) {
                $q->whereNull('profile_types')
                  ->orWhereJsonLength('profile_types', 0)
                  ->orWhereJsonContains('profile_types', $profile);
            });
        }

        $packs = $query->get();

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
            'profile_types'   => 'nullable|array',
            'profile_types.*' => 'in:manufacturer,reseller,wholesaler,service_provider',
            'addons'      => 'nullable|array',
            'addons.*'    => 'in:credit',
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
        $pack->load('countryPrices');

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
            'profile_types'   => 'nullable|array',
            'profile_types.*' => 'in:manufacturer,reseller,wholesaler,service_provider',
            'addons'      => 'nullable|array',
            'addons.*'    => 'in:credit',
            'limits'      => 'nullable',
            'is_active'   => 'nullable|boolean',
            'order'       => 'nullable|integer',
        ]);

        if (!empty($data['name'])) {
            $data['slug'] = Str::slug($data['name']);
        }

        // Remove nulls but keep false and 0
        $updateData = array_filter($data, fn($v) => $v !== null);
        // profile_types : un tableau vide signifie « tous les profils » → on le persiste
        if ($request->exists('profile_types')) {
            $updateData['profile_types'] = $data['profile_types'] ?: null;
        }
        if ($request->exists('addons')) {
            $updateData['addons'] = $data['addons'] ?: null;
        }
        $pack->update($updateData);

        return response()->json([
            'success' => true,
            'message' => 'Pack mis à jour avec succès.',
            'data'    => ['pack' => new PackResource($pack->fresh())],
        ]);
    }

    /**
     * Définir (ou mettre à jour) le prix d'un pack pour un pays.
     * Un country_admin est forcé sur son propre pays ; le super admin choisit.
     * Body : { country_code?: 'GN', price: 350000, currency: 'GNF' }
     */
    public function setCountryPrice(Request $request, Pack $pack): JsonResponse
    {
        $user = $request->user();
        $isCountryAdmin = $user->hasRole('country_admin') && !$user->hasRole('super_admin');

        $data = $request->validate([
            'country_code' => ($isCountryAdmin ? 'nullable' : 'required') . '|string|max:5',
            'price'        => 'required|numeric|min:0',
            'currency'     => 'required|string|max:5',
        ]);

        $country = $isCountryAdmin
            ? $user->country_code
            : strtoupper($data['country_code']);

        abort_if(!$country, 422, 'Pays introuvable.');

        $cp = PackCountryPrice::updateOrCreate(
            ['pack_id' => $pack->id, 'country_code' => $country],
            ['price' => $data['price'], 'currency' => strtoupper($data['currency'])]
        );

        return response()->json([
            'success' => true,
            'message' => "Prix du pack « {$pack->name} » défini pour {$country} : {$cp->price} {$cp->currency}.",
            'data'    => ['pack' => new PackResource($pack->fresh()->load('countryPrices'))],
        ]);
    }

    /**
     * Supprimer le prix personnalisé d'un pays (retour au prix de base).
     */
    public function removeCountryPrice(Request $request, Pack $pack, string $country): JsonResponse
    {
        $user = $request->user();
        $isCountryAdmin = $user->hasRole('country_admin') && !$user->hasRole('super_admin');

        $country = strtoupper($country);

        abort_if($isCountryAdmin && $country !== $user->country_code, 403, 'Vous ne pouvez gérer que votre pays.');

        PackCountryPrice::where('pack_id', $pack->id)
            ->where('country_code', $country)
            ->delete();

        return response()->json([
            'success' => true,
            'message' => "Prix personnalisé supprimé pour {$country} — retour au prix de base.",
            'data'    => ['pack' => new PackResource($pack->fresh()->load('countryPrices'))],
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
