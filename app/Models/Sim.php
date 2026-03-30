<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Sim extends Model
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
        'modem_id',
        'slot_name',
        'phone_number',
        'carrier',
        'sim_label',
        'status',
        'mode',
        'operator_status',
        'accept_new_assignments',
        'disabled_for_new_assignments',
        'daily_limit',
        'recommended_limit',
        'burst_limit',
        'burst_interval_min_seconds',
        'burst_interval_max_seconds',
        'normal_interval_min_seconds',
        'normal_interval_max_seconds',
        'cooldown_min_seconds',
        'cooldown_max_seconds',
        'burst_count',
        'cooldown_until',
        'last_success_at',
        'last_sent_at',
        'last_received_at',
        'last_error_at',
        'notes',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'operator_status' => 'string',
        'accept_new_assignments' => 'boolean',
        'disabled_for_new_assignments' => 'boolean',
        'last_success_at' => 'datetime',
        'cooldown_until' => 'datetime',
        'last_sent_at' => 'datetime',
        'last_received_at' => 'datetime',
        'last_error_at' => 'datetime',
    ];

    /**
     * Get the owning company.
     */
    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Get the owning modem.
     */
    public function modem()
    {
        return $this->belongsTo(Modem::class);
    }

    /**
     * Get outbound messages routed via this SIM.
     */
    public function outboundMessages()
    {
        return $this->hasMany(OutboundMessage::class);
    }

    /**
     * Get inbound messages received on this SIM.
     */
    public function inboundMessages()
    {
        return $this->hasMany(InboundMessage::class);
    }

    /**
     * Get sticky customer-SIM assignments pointing to this SIM.
     */
    public function customerSimAssignments()
    {
        return $this->hasMany(CustomerSimAssignment::class);
    }

    /**
     * Get daily delivery counters for this SIM.
     */
    public function dailyStats()
    {
        return $this->hasMany(SimDailyStat::class);
    }

    /**
     * Get health logs for this SIM.
     */
    public function healthLogs()
    {
        return $this->hasMany(SimHealthLog::class);
    }

    /**
     * Determine if this SIM can currently be used for sending.
     *
     * @return bool
     */
    public function isAvailable(): bool
    {
        if (!$this->isActive()) {
            return false;
        }

        if ($this->isCoolingDown()) {
            return false;
        }

        $sentToday = SimDailyStat::query()
            ->where('sim_id', $this->id)
            ->whereDate('stat_date', today())
            ->value('sent_count') ?? 0;

        return $sentToday < $this->daily_limit;
    }

    /**
     * Determine if SIM status is active.
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Determine if operator status is active.
     *
     * @return bool
     */
    public function isOperatorActive(): bool
    {
        return $this->operator_status === 'active';
    }

    /**
     * Determine if operator status is paused.
     *
     * @return bool
     */
    public function isOperatorPaused(): bool
    {
        return $this->operator_status === 'paused';
    }

    /**
     * Determine if operator status is blocked.
     *
     * @return bool
     */
    public function isOperatorBlocked(): bool
    {
        return $this->operator_status === 'blocked';
    }

    /**
     * Determine if SIM can accept new customer assignments.
     *
     * @return bool
     */
    public function acceptsNewAssignments(): bool
    {
        return $this->accept_new_assignments && !$this->disabled_for_new_assignments;
    }

    /**
     * Mark SIM as having a successful send now.
     *
     * @return void
     */
    public function markSuccessful(): void
    {
        $this->update([
            'last_success_at' => now(),
        ]);
    }

    /**
     * Get minutes since last successful send.
     *
     * @return int|null
     */
    public function minutesSinceLastSuccess(): ?int
    {
        if ($this->last_success_at === null) {
            return null;
        }

        return now()->diffInMinutes($this->last_success_at);
    }

    /**
     * Determine if SIM is in active cooldown window.
     *
     * @return bool
     */
    public function isCoolingDown(): bool
    {
        return $this->cooldown_until !== null && $this->cooldown_until->greaterThan(now());
    }

    /**
     * Get current SIM mode with NORMAL fallback.
     *
     * @return string
     */
    public function currentMode(): string
    {
        return $this->mode ?: 'NORMAL';
    }
}
