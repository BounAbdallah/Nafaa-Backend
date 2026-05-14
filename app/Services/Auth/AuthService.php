<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Repositories\Contracts\UserRepositoryInterface;
use Illuminate\Auth\Events\PasswordReset;
// event(new Registered()) suppressed — emails triggered after onboarding
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthService
{
    public function __construct(
        private readonly UserRepositoryInterface $userRepository,
    ) {}

    public function register(array $data): array
    {
        $user = $this->userRepository->create([
            'name'     => $data['name'],
            'email'    => $data['email'],
            'password' => $data['password'],
            'locale'   => $data['locale'] ?? 'fr',
        ]);

        // ⚠️  Ne pas envoyer l'e-mail de vérification ici.
        // Il sera déclenché une fois que l'utilisateur aura renseigné
        // les informations de son entreprise (TenantService::createTenant).

        $token = $user->createToken('nafaa-auth-token')->plainTextToken;

        return [
            'user'  => $user->load('roles'),
            'token' => $token,
        ];
    }

    public function login(array $credentials): array
    {
        $user = $this->userRepository->findByEmail($credentials['email']);

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Les identifiants fournis sont incorrects.'],
            ]);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'email' => ['Votre compte a été désactivé. Contactez le support.'],
            ]);
        }

        if ($user->tenant && ! $user->tenant->is_active) {
            throw ValidationException::withMessages([
                'email' => ["Votre espace de travail est en attente d'approbation ou a été désactivé. Veuillez patienter ou contacter le support."],
            ]);
        }

        $user->tokens()->delete();
        $token = $user->createToken('nafaa-auth-token')->plainTextToken;

        $this->userRepository->updateLastLogin($user);

        \App\Models\ActivityLog::record(
            $user->tenant_id ?? 0,
            'login',
            $user->id,
            null,
            [],
            request()->ip()
        );

        return [
            'user'  => $user->fresh(['tenant', 'roles']),
            'token' => $token,
        ];
    }

    public function logout(User $user): void
    {
        $user->currentAccessToken()->delete();
    }

    public function sendPasswordResetLink(string $email): string
    {
        $status = Password::sendResetLink(['email' => $email]);

        if ($status !== Password::RESET_LINK_SENT) {
            throw ValidationException::withMessages([
                'email' => [__($status)],
            ]);
        }

        return $status;
    }

    public function resetPassword(array $data): void
    {
        $status = Password::reset(
            [
                'email'                 => $data['email'],
                'password'              => $data['password'],
                'password_confirmation' => $data['password_confirmation'] ?? $data['password'],
                'token'                 => $data['token'],
            ],
            function (User $user, string $password) {
                $user->forceFill([
                    'password'       => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();

                $user->tokens()->delete();

                event(new PasswordReset($user));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => [__($status)],
            ]);
        }
    }

    public function resendVerificationEmail(User $user): void
    {
        if ($user->hasVerifiedEmail()) {
            throw ValidationException::withMessages([
                'email' => ['Votre adresse e-mail est déjà vérifiée.'],
            ]);
        }

        $user->sendEmailVerificationNotification();
    }
}
