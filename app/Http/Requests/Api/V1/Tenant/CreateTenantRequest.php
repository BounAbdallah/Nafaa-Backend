<?php

namespace App\Http\Requests\Api\V1\Tenant;

use App\Models\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateTenantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'name'         => ['required', 'string', 'max:255'],
            'slug'         => ['sometimes', 'string', 'max:100', 'regex:/^[a-z0-9\-]+$/', 'unique:tenants,slug'],
            'industry'     => ['required', 'string', Rule::in(array_keys(Tenant::INDUSTRIES))],
            'profile_type' => ['required', 'string', Rule::in([
                Tenant::PROFILE_MANUFACTURER,
                Tenant::PROFILE_RESELLER,
                Tenant::PROFILE_WHOLESALER,
                Tenant::PROFILE_SERVICE,
            ])],
            'plan'         => ['sometimes', 'string', Rule::in([
                Tenant::PLAN_DEMARRAGE,
                Tenant::PLAN_PRO,
                Tenant::PLAN_BUSINESS,
                Tenant::PLAN_ENTREPRISE,
            ])],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required'         => 'Le nom de l\'entreprise est obligatoire.',
            'industry.required'     => 'Le secteur d\'activité est obligatoire.',
            'industry.in'           => 'Le secteur d\'activité sélectionné est invalide.',
            'profile_type.required' => 'Le profil d\'activité est obligatoire.',
            'profile_type.in'       => 'Le profil d\'activité sélectionné est invalide.',
            'slug.unique'           => 'Ce nom d\'espace est déjà pris.',
            'slug.regex'            => 'Le nom d\'espace ne peut contenir que des lettres minuscules, chiffres et tirets.',
        ];
    }
}
