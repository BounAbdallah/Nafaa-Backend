<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'name'           => $this->name,
            'sku'            => $this->sku,
            'description'    => $this->description,
            'type'           => $this->type,
            'type_label'     => $this->type === 'service' ? 'Service' : 'Produit',
            'category'       => $this->category,
            'category_label' => \App\Models\Product::categories()[$this->category] ?? $this->category,
            'unit'           => $this->unit,
            'selling_price'  => $this->selling_price,
            'cost_price'     => $this->cost_price,
            'margin'         => $this->margin,
            'stock_quantity' => $this->stock_quantity,
            'stock_alert'    => $this->stock_alert,
            'is_low_stock'   => $this->isLowStock(),
            'image'          => $this->image ? asset('storage/' . $this->image) : null,
            'is_active'      => $this->is_active,
            'created_at'     => $this->created_at->toIso8601String(),
            'updated_at'     => $this->updated_at->toIso8601String(),
        ];
    }
}
