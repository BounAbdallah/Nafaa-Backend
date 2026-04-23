<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // ── Tenant-level permissions ─────────────────────────────────────
        $tenantPermissions = [
            'view users', 'create users', 'edit users', 'delete users',
            'view products', 'create products', 'edit products', 'delete products',
            'view orders', 'create orders', 'edit orders', 'delete orders',
            'view customers', 'create customers', 'edit customers', 'delete customers',
            'view suppliers', 'create suppliers', 'edit suppliers', 'delete suppliers',
            'view purchase-orders', 'create purchase-orders', 'edit purchase-orders', 'delete purchase-orders',
            'view expenses', 'create expenses', 'edit expenses', 'delete expenses',
            'view reports', 'export reports',
            'manage settings', 'manage billing',
        ];

        // ── Platform-level permissions (super_admin only) ─────────────────
        $platformPermissions = [
            'block users', 'unblock users',
            'view tenants', 'manage tenants',
            'impersonate users',
        ];

        foreach (array_merge($tenantPermissions, $platformPermissions) as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'sanctum']);
        }

        // ── super_admin : toutes les permissions (plateforme + tenant) ────
        $superAdminRole = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'sanctum']);
        $superAdminRole->syncPermissions(Permission::where('guard_name', 'sanctum')->get());

        // ── admin tenant : toutes les permissions tenant ──────────────────
        $adminRole = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'sanctum']);
        $adminRole->syncPermissions(
            Permission::whereIn('name', $tenantPermissions)->where('guard_name', 'sanctum')->get()
        );

        // ── employee : lecture + création/édition opérationnelle ──────────
        $employeeRole = Role::firstOrCreate(['name' => 'employee', 'guard_name' => 'sanctum']);
        $employeeRole->syncPermissions([
            'view users',
            'view products', 'create products', 'edit products',
            'view orders', 'create orders', 'edit orders',
            'view customers', 'create customers', 'edit customers',
            'view reports',
        ]);

        // ── viewer : lecture seule ────────────────────────────────────────
        $viewerRole = Role::firstOrCreate(['name' => 'viewer', 'guard_name' => 'sanctum']);
        $viewerRole->syncPermissions([
            'view users', 'view products', 'view orders', 'view customers', 'view reports',
        ]);

        // ── Compte super_admin de démonstration ───────────────────────────
        $superAdmin = User::firstOrCreate(
            ['email' => 'admin@nafaa.app'],
            [
                'name'              => 'Super Admin NAFAA',
                'password'          => Hash::make('Nafaa@2026'),
                'email_verified_at' => now(),
                'is_active'         => true,
                'locale'            => 'fr',
            ]
        );
        $superAdmin->assignRole($superAdminRole);
    }
}
