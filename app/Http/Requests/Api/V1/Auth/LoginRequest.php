<?php

namespace App\Http\Requests\Api\V1\Auth;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email'    => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.required'    => 'L\'adresse e-mail est obligatoire.',
            'email.email'       => 'L\'adresse e-mail n\'est pas valide.',
            'password.required' => 'Le mot de passe est obligatoire.',
        ];
    }
}
