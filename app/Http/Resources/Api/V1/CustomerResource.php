<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'name'         => $this->name,
            'display_name' => $this->display_name,
            'email'        => $this->email,
            'phone'        => $this->phone,
            'company'      => $this->company,
            'type'         => $this->type,
            'type_label'   => $this->type === 'company' ? 'Entreprise' : 'Particulier',
            'address'      => $this->address,
            'city'         => $this->city,
            'country'      => $this->country,
            'country_label'=> \App\Models\Customer::countries()[$this->country] ?? $this->country,
            'notes'        => $this->notes,
            'total_spent'  => $this->total_spent,
            'orders_count' => $this->orders_count,
            'recent_orders' => $this->relationLoaded('orders') 
                ? $this->orders->take(5)->map(fn($o) => [
                    'id' => $o->id,
                    'reference' => $o->reference,
                    'total_amount' => $o->total_amount,
                    'status' => $o->status,
                    'created_at' => $o->created_at->toIso8601String(),
                ])
                : [],
            'is_active'    => $this->is_active,
            'created_at'   => $this->created_at->toIso8601String(),
            'updated_at'   => $this->updated_at->toIso8601String(),
        ];
    }
}
