<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    public const ROLE_OWNER = 'owner';
    public const ROLE_ADMIN = 'admin';
    public const ROLE_SUPPORT = 'support';

    /**
     * Dashboard write roles.
     *
     * @var array<int, string>
     */
    public const DASHBOARD_WRITE_ROLES = [
        self::ROLE_OWNER,
        self::ROLE_ADMIN,
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'must_change_password',
        'company_id',
        'operator_role',
        'is_active',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'must_change_password' => 'boolean',
        'company_id' => 'integer',
        'is_active' => 'boolean',
    ];

    /**
     * Determine if this operator can execute dashboard write/control actions.
     */
    public function canDashboardWrite(): bool
    {
        return in_array((string) $this->operator_role, self::DASHBOARD_WRITE_ROLES, true);
    }

    /**
     * Determine if this operator has a valid known role.
     */
    public function hasValidOperatorRole(): bool
    {
        return in_array((string) $this->operator_role, [
            self::ROLE_OWNER,
            self::ROLE_ADMIN,
            self::ROLE_SUPPORT,
        ], true);
    }

    /**
     * Get the tenant company bound to this dashboard operator.
     */
    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
