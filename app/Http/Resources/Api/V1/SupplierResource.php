<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupplierResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'name'         => $this->name,
            'phone'        => $this->phone,
            'email'        => $this->email,
            'contact_name' => $this->contact_name,
            'address'      => $this->address,
            'city'         => $this->city,
            'country'      => $this->country,
            'country_label'=> \App\Models\Customer::countries()[$this->country] ?? $this->country,
            'notes'        => $this->notes,
            'total_ordered'=> $this->total_ordered,
            'orders_count' => $this->orders_count,
            'is_active'    => $this->is_active,
            'created_at'   => $this->created_at->toIso8601String(),
            'updated_at'   => $this->updated_at->toIso8601String(),
        ];
    }
}
