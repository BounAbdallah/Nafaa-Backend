<?php

namespace App\Repositories\Eloquent;

use App\Models\User;
use App\Repositories\Contracts\UserRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class UserRepository implements UserRepositoryInterface
{
    public function __construct(private readonly User $model) {}

    public function findById(int $id): ?User
    {
        return $this->model->withoutGlobalScopes()->find($id);
    }

    public function findByEmail(string $email): ?User
    {
        return $this->model->withoutGlobalScopes()->where('email', $email)->first();
    }

    public function create(array $data): User
    {
        return $this->model->create($data);
    }

    public function update(User $user, array $data): User
    {
        $user->update($data);

        return $user->fresh();
    }

    public function delete(User $user): bool
    {
        return $user->delete();
    }

    public function findByTenant(int $tenantId): Collection
    {
        return $this->model->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->get();
    }

    public function updateLastLogin(User $user): void
    {
        $user->update(['last_login_at' => now()]);
    }
}
