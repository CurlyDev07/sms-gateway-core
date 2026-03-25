<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CustomerSimAssignment extends Model
{
    use HasFactory, HasUuid;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'uuid',
        'company_id',
        'customer_phone',
        'sim_id',
        'status',
        'assigned_at',
        'last_used_at',
        'last_inbound_at',
        'last_outbound_at',
        'has_replied',
        'safe_to_migrate',
        'migration_locked',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'assigned_at' => 'datetime',
        'last_used_at' => 'datetime',
        'last_inbound_at' => 'datetime',
        'last_outbound_at' => 'datetime',
        'has_replied' => 'boolean',
        'safe_to_migrate' => 'boolean',
        'migration_locked' => 'boolean',
    ];

    /**
     * Get the owning company.
     */
    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Get the assigned SIM.
     */
    public function sim()
    {
        return $this->belongsTo(Sim::class);
    }

    /**
     * Determine if assignment is active.
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Determine if assignment can be migrated.
     *
     * @return bool
     */
    public function canMigrate(): bool
    {
        return (bool) $this->safe_to_migrate && !(bool) $this->migration_locked;
    }
}
