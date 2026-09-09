<?php

namespace App\Http\Resources\Api\V1\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PackResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Localisation du prix : ?country=GN (ou pays de l'admin connecté)
        $country = strtoupper((string) $request->get('country', '')) ?: $request->user()?->country_code;
        $local   = $this->relationLoaded('countryPrices') || $this->exists
            ? $this->priceFor($country)
            : ['price' => (float) $this->price, 'currency' => 'XOF', 'is_override' => false];

        return [
            'id'          => $this->id,
            'name'        => $this->name,
            'slug'        => $this->slug,
            'description' => $this->description,
            'price'       => $local['price'],
            'base_price'  => (float) $this->price,
            'currency'    => $local['currency'],
            'is_country_price' => $local['is_override'],
            'country_prices'   => $this->whenLoaded('countryPrices', fn () => $this->countryPrices->map(fn ($cp) => [
                'country_code' => $cp->country_code,
                'price'        => (float) $cp->price,
                'currency'     => $cp->currency,
            ])),
            'profile_types' => $this->profile_types ?? [],
            'addons'        => $this->addons ?? [],
            'period'      => $this->period,
            'features'    => $this->features ?? [],
            'limits'      => $this->limits ?? ['users' => 2, 'products' => 50, 'storage_gb' => 1],
            'is_active'   => $this->is_active,
            'order'       => $this->order,
            'created_at'  => $this->created_at->toIso8601String(),
        ];
    }
}
