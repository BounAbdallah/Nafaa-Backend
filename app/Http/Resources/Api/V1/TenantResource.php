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
            'plan_limits'     => $this->getPlanLimits(),
            'logo'            => $this->logo,
            'is_active'       => $this->is_active,
            'is_on_trial'     => $this->isOnTrial(),
            'has_active_plan' => $this->hasActivePlan(),
            'plan_expires_at' => $this->plan_expires_at?->toIso8601String(),
            'trial_ends_at'   => $this->trial_ends_at?->toIso8601String(),
            'created_at'      => $this->created_at->toIso8601String(),
        ];
    }
}
