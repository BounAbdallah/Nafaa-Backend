<?php

namespace App\Models;

use App\Notifications\VerifyEmailNotification;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;
class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens, HasFactory, HasRoles, Notifiable, SoftDeletes;

    protected $fillable = [
        'name',
        'email',
        'password',
        'tenant_id',
        'country_code',
        'report_frequency',
        'avatar',
        'phone',
        'locale',
        'is_active',
        'block_reason',
        'last_login_at',
        'module_permissions',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at'  => 'datetime',
        'last_login_at'      => 'datetime',
        'is_active'          => 'boolean',
        'password'           => 'hashed',
        'module_permissions' => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function isTenantAdmin(): bool
    {
        return $this->hasRole('admin');
    }

    /**
     * Check if the user can perform an action on a module.
     * Admins always have full access.
     * For employees, checks module_permissions JSON.
     * Default for employees with no permissions set: view-only on orders/pos.
     */
    public function canDo(string $module, string $action = 'view'): bool
    {
        if ($this->isTenantAdmin()) return true;

        $perms = $this->module_permissions ?? [];
        return (bool) ($perms[$module][$action] ?? false);
    }

    /**
     * Returns the full permissions array, with admin defaults filled in.
     */
    public function getEffectivePermissions(): array
    {
        if ($this->isTenantAdmin()) {
            return self::fullPermissions();
        }
        return $this->module_permissions ?? [];
    }

    public static function fullPermissions(): array
    {
        $modules = ['pos', 'products', 'orders', 'customers', 'suppliers', 'purchase_orders', 'expenses'];
        $result = [];
        foreach ($modules as $mod) {
            $result[$mod] = ['view' => true, 'create' => true, 'edit' => true, 'delete' => true];
        }
        $result['reports'] = ['view' => true];
        return $result;
    }

    public static function defaultEmployeePermissions(): array
    {
        return [
            'pos'             => ['view' => true, 'create' => true, 'edit' => false, 'delete' => false],
            'products'        => ['view' => true, 'create' => false, 'edit' => false, 'delete' => false],
            'orders'          => ['view' => true, 'create' => true, 'edit' => false, 'delete' => false],
            'customers'       => ['view' => true, 'create' => true, 'edit' => false, 'delete' => false],
            'suppliers'       => ['view' => false, 'create' => false, 'edit' => false, 'delete' => false],
            'purchase_orders' => ['view' => false, 'create' => false, 'edit' => false, 'delete' => false],
            'expenses'        => ['view' => false, 'create' => false, 'edit' => false, 'delete' => false],
            'reports'         => ['view' => true],
        ];
    }

    public function scopeForTenant($query, int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    /**
     * Override email verification notification with Qiwam ERP branding.
     */
    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new VerifyEmailNotification());
    }
}
