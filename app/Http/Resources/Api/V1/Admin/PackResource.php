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
        return [
            'id'          => $this->id,
            'name'        => $this->name,
            'slug'        => $this->slug,
            'description' => $this->description,
            'price'       => $this->price,
            'period'      => $this->period,
            'features'    => $this->features ?? [],
            'limits'      => $this->limits ?? ['users' => 2, 'products' => 50, 'storage_gb' => 1],
            'is_active'   => $this->is_active,
            'order'       => $this->order,
            'created_at'  => $this->created_at->toIso8601String(),
        ];
    }
}
