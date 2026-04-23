<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseOrderItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                => $this->id,
            'product_id'        => $this->product_id,
            'product_name'      => $this->whenLoaded('product', fn() => $this->product?->name),
            'description'       => $this->description,
            'unit'              => $this->unit,
            'quantity'          => $this->quantity,
            'unit_price'        => $this->unit_price,
            'subtotal'          => $this->subtotal,
            'received_quantity' => $this->received_quantity,
        ];
    }
}
