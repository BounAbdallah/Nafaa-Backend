<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\AuthService;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailVerificationController extends Controller
{
    public function __construct(private readonly AuthService $authService) {}

    public function verify(EmailVerificationRequest $request): JsonResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return response()->json([
                'success' => true,
                'message' => 'E-mail déjà vérifié.',
            ]);
        }

        if ($request->user()->markEmailAsVerified()) {
            event(new Verified($request->user()));
        }

        return response()->json([
            'success' => true,
            'message' => 'E-mail vérifié avec succès.',
        ]);
    }

    public function resend(Request $request): JsonResponse
    {
        $this->authService->resendVerificationEmail($request->user());

        return response()->json([
            'success' => true,
            'message' => 'E-mail de vérification renvoyé.',
        ]);
    }
}
