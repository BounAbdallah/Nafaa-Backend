<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Garde-fous de sécurité : les routes de debug ne doivent jamais réapparaître.
 */
class SecurityRoutesTest extends TestCase
{
    public function test_debug_routes_are_not_registered(): void
    {
        $uris = collect(Route::getRoutes())->map(fn ($r) => $r->uri());

        $this->assertFalse(
            $uris->contains('api/v1/debug/migrate'),
            'La route de debug /debug/migrate ne doit pas exister.'
        );
        $this->assertFalse(
            $uris->contains('api/v1/debug/make-me-super-admin'),
            'La route de debug /debug/make-me-super-admin ne doit pas exister.'
        );
    }

    public function test_admin_routes_require_authentication(): void
    {
        $this->getJson('/api/v1/admin/users')->assertUnauthorized();
        $this->getJson('/api/v1/admin/subscriptions/stats')->assertUnauthorized();
        $this->getJson('/api/v1/admin/admins')->assertUnauthorized();
    }

    public function test_admin_routes_are_protected_by_role_middleware(): void
    {
        $adminRoutes = collect(Route::getRoutes())
            ->filter(fn ($r) => str_starts_with($r->uri(), 'api/v1/admin/'));

        $this->assertTrue($adminRoutes->isNotEmpty());

        foreach ($adminRoutes as $route) {
            $middleware = $route->gatherMiddleware();
            $hasRole = collect($middleware)->contains(fn ($m) => str_starts_with((string) $m, 'role:'));
            $this->assertTrue($hasRole, "Route admin sans middleware de rôle : {$route->uri()}");
        }
    }
}
