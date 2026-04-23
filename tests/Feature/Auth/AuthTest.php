<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->baseUrl = '/api/v1/auth';
});

describe('Registration', function () {
    it('registers a new user successfully', function () {
        $response = $this->postJson("{$this->baseUrl}/register", [
            'name'                  => 'Amadou Diallo',
            'email'                 => 'amadou@example.com',
            'password'              => 'Password123!',
            'password_confirmation' => 'Password123!',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => ['user' => ['id', 'name', 'email'], 'token'],
            ])
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('users', ['email' => 'amadou@example.com']);
    });

    it('fails registration with duplicate email', function () {
        User::factory()->create(['email' => 'amadou@example.com']);

        $response = $this->postJson("{$this->baseUrl}/register", [
            'name'                  => 'Amadou Diallo',
            'email'                 => 'amadou@example.com',
            'password'              => 'Password123!',
            'password_confirmation' => 'Password123!',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false);
    });

    it('fails registration with short password', function () {
        $response = $this->postJson("{$this->baseUrl}/register", [
            'name'                  => 'Amadou Diallo',
            'email'                 => 'amadou@example.com',
            'password'              => '123',
            'password_confirmation' => '123',
        ]);

        $response->assertStatus(422);
    });
});

describe('Login', function () {
    it('logs in successfully with correct credentials', function () {
        User::factory()->create([
            'email'    => 'test@example.com',
            'password' => Hash::make('Password123!'),
        ]);

        $response = $this->postJson("{$this->baseUrl}/login", [
            'email'    => 'test@example.com',
            'password' => 'Password123!',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success', 'message',
                'data' => ['user', 'token'],
            ])
            ->assertJson(['success' => true]);
    });

    it('fails login with wrong password', function () {
        User::factory()->create([
            'email'    => 'test@example.com',
            'password' => Hash::make('Password123!'),
        ]);

        $response = $this->postJson("{$this->baseUrl}/login", [
            'email'    => 'test@example.com',
            'password' => 'WrongPassword',
        ]);

        $response->assertStatus(422)
            ->assertJson(['success' => false]);
    });
});

describe('Me endpoint', function () {
    it('returns current user when authenticated', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("{$this->baseUrl}/me");

        $response->assertStatus(200)
            ->assertJsonPath('data.user.email', $user->email);
    });

    it('returns 401 when unauthenticated', function () {
        $response = $this->getJson("{$this->baseUrl}/me");
        $response->assertStatus(401);
    });
});

describe('Logout', function () {
    it('logs out successfully', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("{$this->baseUrl}/logout");

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    });
});
