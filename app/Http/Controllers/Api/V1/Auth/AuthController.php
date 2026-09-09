<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\LoginRequest;
use App\Http\Requests\Api\V1\Auth\RegisterRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Services\Auth\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(private readonly AuthService $authService) {}

    public function register(RegisterRequest $request): JsonResponse
    {
        $result = $this->authService->register($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Compte créé avec succès. Veuillez vérifier votre e-mail.',
            'data'    => [
                'user'  => new UserResource($result['user']),
                'token' => $result['token'],
            ],
        ], 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $result = $this->authService->login($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Connexion réussie.',
            'data'    => [
                'user'  => new UserResource($result['user']),
                'token' => $result['token'],
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $this->authService->logout($request->user());

        return response()->json([
            'success' => true,
            'message' => 'Déconnexion réussie.',
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => [
                'user' => new UserResource($request->user()->load('tenant', 'roles')),
            ],
        ]);
    }

    /**
     * Mise à jour du profil de l'utilisateur connecté (nom, téléphone, mot de passe).
     * Accessible à tous les rôles, y compris les admins plateforme sans tenant.
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'name'             => 'sometimes|string|max:255',
            'phone'            => 'nullable|string|max:30',
            'report_frequency' => 'nullable|in:daily,weekly,monthly',
            'current_password' => 'nullable|string',
            'password'         => 'nullable|string|min:8|confirmed',
        ]);

        if (!empty($data['password'])) {
            if (empty($data['current_password']) || !\Illuminate\Support\Facades\Hash::check($data['current_password'], $user->password)) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'current_password' => ['Le mot de passe actuel est incorrect.'],
                ]);
            }
            $user->password = \Illuminate\Support\Facades\Hash::make($data['password']);
        }

        if (array_key_exists('name', $data))  $user->name  = $data['name'];
        if (array_key_exists('phone', $data)) $user->phone = $data['phone'];
        if ($request->exists('report_frequency')) $user->report_frequency = $data['report_frequency'] ?? null;
        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'Profil mis à jour avec succès.',
            'data'    => ['user' => new UserResource($user->fresh()->load('tenant', 'roles'))],
        ]);
    }
}
