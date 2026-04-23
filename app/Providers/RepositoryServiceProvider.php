<?php

namespace App\Providers;

use App\Repositories\Contracts\TenantRepositoryInterface;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Repositories\Eloquent\TenantRepository;
use App\Repositories\Eloquent\UserRepository;
use Illuminate\Support\ServiceProvider;

class RepositoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(UserRepositoryInterface::class, UserRepository::class);
        $this->app->bind(TenantRepositoryInterface::class, TenantRepository::class);
    }

    public function boot(): void {}
}
