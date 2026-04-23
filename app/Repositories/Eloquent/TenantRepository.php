<?php

namespace App\Repositories\Eloquent;

use App\Models\Tenant;
use App\Repositories\Contracts\TenantRepositoryInterface;
use Illuminate\Support\Str;

class TenantRepository implements TenantRepositoryInterface
{
    public function __construct(private readonly Tenant $model) {}

    public function findById(int $id): ?Tenant
    {
        return $this->model->find($id);
    }

    public function findBySlug(string $slug): ?Tenant
    {
        return $this->model->where('slug', $slug)->first();
    }

    public function create(array $data): Tenant
    {
        return $this->model->create($data);
    }

    public function update(Tenant $tenant, array $data): Tenant
    {
        $tenant->update($data);

        return $tenant->fresh();
    }

    public function slugExists(string $slug): bool
    {
        return $this->model->where('slug', $slug)->exists();
    }

    public function generateUniqueSlug(string $name): string
    {
        $base    = Str::slug($name);
        $slug    = $base;
        $counter = 1;

        while ($this->slugExists($slug)) {
            $slug = $base.'-'.$counter;
            $counter++;
        }

        return $slug;
    }
}
