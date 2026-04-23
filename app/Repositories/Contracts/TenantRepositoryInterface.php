<?php

namespace App\Repositories\Contracts;

use App\Models\Tenant;

interface TenantRepositoryInterface
{
    public function findById(int $id): ?Tenant;
    public function findBySlug(string $slug): ?Tenant;
    public function create(array $data): Tenant;
    public function update(Tenant $tenant, array $data): Tenant;
    public function slugExists(string $slug): bool;
    public function generateUniqueSlug(string $name): string;
}
