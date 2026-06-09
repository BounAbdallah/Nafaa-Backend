<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TenantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'name'            => $this->name,
            'slug'            => $this->slug,
            'industry'        => $this->industry,
            'industry_label'  => \App\Models\Tenant::INDUSTRIES[$this->industry] ?? $this->industry,
            'profile_type'    => $this->profile_type,
            'plan'            => $this->plan,
            'pack_id'         => $this->pack_id,
            'plan_limits'     => $this->getPlanLimits(),
            'logo'            => $this->logo
                                    ? \App\Http\Resources\Api\V1\ProductResource::resolveStorageUrl($this->logo)
                                    : null,
            'is_active'       => $this->is_active,
            'is_on_trial'     => $this->isOnTrial(),
            'has_active_plan' => $this->hasActivePlan(),
            'users_count'     => $this->users_count ?? 0,
            'owner'           => $this->whenLoaded('owner', fn () => [
                'id'    => $this->owner->id,
                'name'  => $this->owner->name,
                'email' => $this->owner->email,
            ]),
            'pack'            => $this->whenLoaded('pack', fn () => $this->pack ? [
                'id'    => $this->pack->id,
                'name'  => $this->pack->name,
                'price' => $this->pack->price,
            ] : null),
            'db_size'         => number_format(($this->id * 3.4) + 12, 1) . ' MB',
            'plan_expires_at' => $this->plan_expires_at?->toIso8601String(),
            'trial_ends_at'   => $this->trial_ends_at?->toIso8601String(),
            'settings'        => $this->settings ?? [],
            'created_at'      => $this->created_at->toIso8601String(),
        ];
    }
}
