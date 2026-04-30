<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Models\PurchaseOrder;

class PurchaseOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'reference'      => $this->reference,
            'status'         => $this->status,
            'status_label'   => PurchaseOrder::$statuses[$this->status] ?? $this->status,
            'order_date'     => $this->order_date?->toDateString(),
            'expected_date'  => $this->expected_date?->toDateString(),
            'received_date'  => $this->received_date?->toDateString(),
            'total_amount'   => $this->total_amount,
            'notes'          => $this->notes,
            'supplier'       => new SupplierResource($this->whenLoaded('supplier')),
            'user'           => $this->whenLoaded('user', fn() => ['id' => $this->user->id, 'name' => $this->user->name]),
            'items'          => PurchaseOrderItemResource::collection($this->whenLoaded('items')),
            'items_count'    => $this->whenLoaded('items', fn() => $this->items->count(), 0),
            'created_at'     => $this->created_at->toIso8601String(),
            'updated_at'     => $this->updated_at->toIso8601String(),
        ];
    }
}
