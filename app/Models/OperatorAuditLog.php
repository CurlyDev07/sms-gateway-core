<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OperatorAuditLog extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'company_id',
        'actor_user_id',
        'action',
        'target_type',
        'target_id',
        'metadata',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'company_id' => 'integer',
        'actor_user_id' => 'integer',
        'target_id' => 'integer',
        'metadata' => 'array',
    ];

    /**
     * Get the tenant company that owns this audit log.
     */
    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Get the dashboard operator who executed the action.
     */
    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
