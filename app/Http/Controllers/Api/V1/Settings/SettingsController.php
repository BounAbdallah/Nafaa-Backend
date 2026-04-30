<?php

namespace App\Http\Controllers\Api\V1\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SettingsController extends Controller
{
    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'name'             => 'required|string|max:255',
            'email'            => ['required', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
            'current_password' => 'nullable|string',
            'password'         => 'nullable|string|min:8|confirmed',
        ]);

        if (!empty($data['password'])) {
            if (empty($data['current_password']) || !Hash::check($data['current_password'], $user->password)) {
                throw ValidationException::withMessages([
                    'current_password' => ['Le mot de passe actuel est incorrect.'],
                ]);
            }
            $user->password = Hash::make($data['password']);
        }

        $user->name = $data['name'];
        $user->email = $data['email'];
        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'Profil mis à jour avec succès.',
            'user'    => $user->fresh(),
        ]);
    }

    public function updateTenant(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenant = $user->tenant;

        if (!$tenant || !$user->hasRole('admin')) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'avez pas les droits pour modifier cet espace.',
            ], 403);
        }

        $data = $request->validate([
            'name'     => 'required|string|max:255',
            'industry' => 'required|string|max:100',
            'logo'     => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',
        ]);

        if ($request->hasFile('logo')) {
            if ($tenant->logo) {
                \Illuminate\Support\Facades\Storage::disk('public')->delete($tenant->logo);
            }
            $path = $request->file('logo')->store('tenants', 'public');
            $tenant->logo = $path;
        }

        $tenant->name = $data['name'];
        $tenant->industry = $data['industry'];
        $tenant->save();

        return response()->json([
            'success' => true,
            'message' => 'Paramètres de l\'espace mis à jour.',
            'tenant'  => $tenant->fresh(),
        ]);
    }
}
