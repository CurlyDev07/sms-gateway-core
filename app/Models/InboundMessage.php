<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InboundMessage extends Model
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
        'sim_id',
        'runtime_sim_id',
        'customer_phone',
        'message',
        'received_at',
        'idempotency_key',
        'relayed_to_chat_app',
        'relayed_at',
        'relay_status',
        'relay_retry_count',
        'relay_next_attempt_at',
        'relay_failed_at',
        'relay_locked_at',
        'relay_error',
        'metadata',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'received_at' => 'datetime',
        'relayed_to_chat_app' => 'boolean',
        'relayed_at' => 'datetime',
        'relay_next_attempt_at' => 'datetime',
        'relay_failed_at' => 'datetime',
        'relay_locked_at' => 'datetime',
        'metadata' => 'array',
    ];

    /**
     * Get the owning company.
     */
    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Get the receiving SIM.
     */
    public function sim()
    {
        return $this->belongsTo(Sim::class);
    }
}
