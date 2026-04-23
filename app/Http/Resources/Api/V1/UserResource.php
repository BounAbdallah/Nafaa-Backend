<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                => $this->id,
            'name'              => $this->name,
            'email'             => $this->email,
            'email_verified_at' => $this->email_verified_at?->toIso8601String(),
            'avatar'            => $this->avatar,
            'phone'             => $this->phone,
            'locale'            => $this->locale ?? 'fr',
            'is_active'         => (bool) $this->is_active,
            'block_reason'      => $this->block_reason,
            'last_login_at'     => $this->last_login_at?->toIso8601String(),
            'tenant_id'         => $this->tenant_id,
            'tenant'            => $this->whenLoaded('tenant', fn () => new TenantResource($this->tenant)),
            'roles'             => $this->whenLoaded('roles', fn () => $this->getRoleNames()),
            'created_at'        => $this->created_at->toIso8601String(),
        ];
    }
}
