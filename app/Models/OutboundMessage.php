<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OutboundMessage extends Model
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
        'customer_phone',
        'message',
        'message_type',
        'priority',
        'status',
        'scheduled_at',
        'queued_at',
        'sent_at',
        'failed_at',
        'locked_at',
        'failure_reason',
        'retry_count',
        'client_message_id',
        'metadata',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'scheduled_at' => 'datetime',
        'queued_at' => 'datetime',
        'sent_at' => 'datetime',
        'failed_at' => 'datetime',
        'locked_at' => 'datetime',
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
     * Get the SIM used to send this message.
     */
    public function sim()
    {
        return $this->belongsTo(Sim::class);
    }
}
