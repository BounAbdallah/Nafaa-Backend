<?php

namespace App\Http\Requests\Api\V1\Tenant;

use App\Models\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateTenantRequest extends FormRequest
{
    /** Indicatifs téléphoniques par code pays ISO-2 */
    private const DIAL_CODES = [
        'SN' => '+221', 'ML' => '+223', 'CI' => '+225', 'GN' => '+224',
        'BF' => '+226', 'BJ' => '+229', 'TG' => '+228', 'NE' => '+227',
        'MR' => '+222', 'GM' => '+220', 'GW' => '+245', 'CV' => '+238',
        'CM' => '+237', 'GA' => '+241', 'CG' => '+242', 'CD' => '+243',
        'TD' => '+235', 'CF' => '+236', 'GQ' => '+240', 'MA' => '+212',
        'DZ' => '+213', 'TN' => '+216', 'NG' => '+234', 'GH' => '+233',
        'FR' => '+33',  'BE' => '+32',  'CH' => '+41',  'US' => '+1',
        'GB' => '+44',
    ];

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
            'pack_id'      => ['nullable', 'exists:packs,id'],
            'country'      => ['nullable', 'string', 'max:10'],
            'currency'     => ['nullable', 'string', 'max:10'],
            'phone'        => [
                'nullable',
                'string',
                'max:25',
                function (string $attribute, mixed $value, \Closure $fail) {
                    if (! $value) return;

                    $country  = $this->input('country');
                    $dialCode = $country ? (self::DIAL_CODES[$country] ?? null) : null;

                    if ($dialCode && ! str_starts_with($value, $dialCode)) {
                        $fail("Le numéro de téléphone doit commencer par {$dialCode} (indicatif du pays sélectionné).");
                    }
                },
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required'         => "Le nom de l'entreprise est obligatoire.",
            'industry.required'     => "Le secteur d'activité est obligatoire.",
            'industry.in'           => "Le secteur d'activité sélectionné est invalide.",
            'profile_type.required' => "Le profil d'activité est obligatoire.",
            'profile_type.in'       => "Le profil d'activité sélectionné est invalide.",
            'slug.unique'           => "Ce nom d'espace est déjà pris.",
            'slug.regex'            => "Le nom d'espace ne peut contenir que des lettres minuscules, chiffres et tirets.",
            'phone.max'             => "Le numéro de téléphone ne peut pas dépasser 25 caractères.",
        ];
    }
}
