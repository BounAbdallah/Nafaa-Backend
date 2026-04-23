<?php

namespace App\Http\Requests\Api\V1\Auth;

use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'     => ['required', 'string', 'max:255'],
            'email'    => ['required', 'string', 'email:rfc,dns', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'locale'   => ['sometimes', 'string', 'in:fr,en'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required'      => 'Le nom est obligatoire.',
            'email.required'     => 'L\'adresse e-mail est obligatoire.',
            'email.email'        => 'L\'adresse e-mail n\'est pas valide.',
            'email.unique'       => 'Cette adresse e-mail est déjà utilisée.',
            'password.required'  => 'Le mot de passe est obligatoire.',
            'password.min'       => 'Le mot de passe doit contenir au moins 8 caractères.',
            'password.confirmed' => 'Les mots de passe ne correspondent pas.',
        ];
    }
}
