<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\ForgotPasswordRequest;
use App\Http\Requests\Api\V1\Auth\ResetPasswordRequest;
use App\Services\Auth\AuthService;
use Illuminate\Http\JsonResponse;

class PasswordResetController extends Controller
{
    public function __construct(private readonly AuthService $authService) {}

    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $this->authService->sendPasswordResetLink($request->validated('email'));

        return response()->json([
            'success' => true,
            'message' => 'Si cet e-mail existe, un lien de réinitialisation a été envoyé.',
        ]);
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $this->authService->resetPassword($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Mot de passe réinitialisé avec succès.',
        ]);
    }
}
