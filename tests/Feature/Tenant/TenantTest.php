<?php

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->baseUrl = '/api/v1/tenants';
    $this->user    = User::factory()->create();
});

describe('Tenant creation', function () {
    it('creates a tenant successfully', function () {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson($this->baseUrl, [
                'name'         => 'Bakari Commerce',
                'industry'     => 'commerce',
                'profile_type' => 'reseller',
                'plan'         => 'demarrage',
            ]);

        $response->assertStatus(201)
            ->assertJson(['success' => true])
            ->assertJsonStructure([
                'data' => ['tenant' => ['id', 'name', 'slug', 'plan']],
            ]);

        $this->assertDatabaseHas('tenants', ['name' => 'Bakari Commerce']);
        $this->assertDatabaseHas('users', [
            'id'        => $this->user->id,
            'tenant_id' => $response->json('data.tenant.id'),
        ]);
    });

    it('prevents creating a second tenant', function () {
        $tenant = Tenant::factory()->create();
        $this->user->update(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson($this->baseUrl, [
                'name'         => 'Another Company',
                'industry'     => 'commerce',
                'profile_type' => 'reseller',
            ]);

        $response->assertStatus(422);
    });

    it('fails with invalid profile_type', function () {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson($this->baseUrl, [
                'name'         => 'Test Company',
                'industry'     => 'commerce',
                'profile_type' => 'invalid_type',
            ]);

        $response->assertStatus(422);
    });

    it('requires authentication', function () {
        $response = $this->postJson($this->baseUrl, [
            'name'         => 'Test Company',
            'industry'     => 'commerce',
            'profile_type' => 'reseller',
        ]);

        $response->assertStatus(401);
    });
});

describe('Get current tenant', function () {
    it('returns current tenant', function () {
        $tenant = Tenant::factory()->create();
        $this->user->update(['tenant_id' => $tenant->id]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("{$this->baseUrl}/current");

        $response->assertStatus(200)
            ->assertJsonPath('data.tenant.id', $tenant->id);
    });

    it('returns 404 when no tenant', function () {
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("{$this->baseUrl}/current");

        $response->assertStatus(404);
    });
});
